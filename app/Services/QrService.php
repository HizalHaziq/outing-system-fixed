<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QrService
{
    public function __construct(private Settings $settings, private GateService $gate, private GroupService $groups) {}

    public function period(): int
    {
        return min(60, max(30, (int) $this->settings->get('qr_refresh_seconds')));
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF), hex2bin($secret), true);
        $offset = ord($hash[19]) & 15;
        $bin = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function issue(Student $student, string $kind): array
    {
        abort_unless($student->is_active, 403);
        $id = $student->std_no;
        if ($kind === 'G') {
            $g = $this->groups->forStudent($id);
            abort_unless($g && count($this->groups->participants($g['id'])) >= 2, 404);
            $id = (string) $g['id'];
        }
        $subject = $kind.':'.$id;
        DB::table('qr_secret')->insertOrIgnore(['subject' => $subject, 'secret' => bin2hex(random_bytes(20)), 'created_at' => now()]);
        $secret = DB::table('qr_secret')->where('subject', $subject)->value('secret');
        $period = $this->period();
        $ts = now()->timestamp;
        $step = intdiv($ts, $period);

        return ['token' => 'OQ1|'.$kind.'|'.$id.'|'.self::code($secret, $step), 'period' => $period, 'expires_in' => ($step + 1) * $period - $ts];
    }

    public function scan(string $token, Staff $guard, string $session): array
    {
        if (! preg_match('/^OQ1\|(S|G)\|([A-Za-z0-9_-]{1,30})\|(\d{6})$/D', trim($token), $m)) {
            RequestService::fail('Invalid outing QR code.');
        }
        [, $kind,$id,$code] = $m;
        $subject = $kind.':'.$id;

        return DB::transaction(function () use ($subject, $code, $kind, $id, $guard, $session) {
            $r = DB::table('qr_secret')->where('subject', $subject)->lockForUpdate()->first();
            $step = intdiv(now()->timestamp, $this->period());
            $ts = $step * $this->period();
            if (! $r || ! hash_equals(self::code($r->secret, $step), $code)) {
                RequestService::fail('QR code expired or invalid. Ask for the live code.');
            }
            if ($r->last_ts >= $ts) {
                RequestService::fail('QR code already used. Ask for the next code.');
            }
            if ($kind === 'G' && ! $this->groups->find((int) $id)) {
                RequestService::fail('Group closed or expired.');
            }
            if ($kind === 'S' && ! Student::where('std_no', $id)->exists()) {
                RequestService::fail('Student not found.');
            }
            if (! DB::table('qr_secret')->where('subject', $subject)->where('last_ts', '<', $ts)->update(['last_ts' => $ts])) {
                RequestService::fail('QR code already used.');
            }

            return ['subject' => $subject, 'grant' => $this->grant($subject, $guard, $session, true)];
        }, 3);
    }

    public function grant(string $subject, Staff $guard, string $session, bool $viaQr): string
    {
        $id = (string) Str::uuid();
        DB::table('gate_verification')->insert(['id' => $id, 'guard_id' => $guard->staff_id, 'session_hash' => hash('sha256', $session), 'subject' => $subject, 'fingerprint' => $this->gate->fingerprint($subject), 'via_qr' => $viaQr, 'expires_at' => now()->addSeconds(180)]);

        return $id;
    }

    public function valid(?string $id, string $subject, Staff $guard, string $session): bool
    {
        return $id && DB::table('gate_verification')->where('id', $id)->where('guard_id', $guard->staff_id)->where('session_hash', hash('sha256', $session))->where('subject', $subject)->whereNull('used_at')->where('expires_at', '>', now())->exists();
    }

    public function perform(string $id, Staff $guard, string $session, array $data): mixed
    {
        try {
            return DB::transaction(function () use ($id, $guard, $session, $data) {
                $v = DB::table('gate_verification')->where('id', $id)->lockForUpdate()->first();
                if (! $v || ! $this->valid($id, $v->subject, $guard, $session)) {
                    RequestService::fail('Verification expired or already used. Look up or scan again.');
                }
                $group = $data['action'] === 'group_process';
                $subject = $group ? 'G:'.$data['group_id'] : 'S:'.$data['std_no'];
                if (! hash_equals($v->subject, $subject)) {
                    RequestService::fail('Verification belongs to a different student or group.');
                }
                $current = Staff::where('id', $guard->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->is_active && $current->role === 'guard', 403);
                if ($group) {
                    DB::table('outing_group')->where('id', $data['group_id'])->lockForUpdate()->first();
                } else {
                    $g = $this->groups->forStudent($data['std_no']);
                    if (! $g) {
                        $gid = DB::table('standard_outing as o')->join('outing_group_link as l', function ($q) {
                            $q->on('l.ref_id', '=', 'o.id')->where('l.kind', 'standard');
                        })->where('o.std_no', $data['std_no'])->whereNull('o.checked_in_at')->value('l.group_id');
                        if ($gid) {
                            $g = ['id' => $gid];
                        }
                    }
                    if ($g) {
                        DB::table('outing_group')->where('id', $g['id'])->lockForUpdate()->first();
                    }
                    Student::where('std_no', $data['std_no'])->lockForUpdate()->firstOrFail();
                }
                if ($group) {
                    $stds = array_column($this->groups->participants((int) $data['group_id']), 'std_no');
                    Student::whereIn('std_no', $stds)->orderBy('std_no')->lockForUpdate()->get();
                }
                $recovery = false;
                if (! $group) {
                    $route = $this->gate->route($data['std_no']);
                    $recovery = $route['direction'] === 'in' && ! $route['student']['is_active'];
                }
                if (! $v->via_qr && ($group || ($this->settings->get('allow_manual_gate_entry') !== '1' && ! $recovery))) {
                    RequestService::fail('Manual entry is disabled. Scan the live QR.');
                }
                if (! hash_equals($v->fingerprint, $this->gate->fingerprint($subject))) {
                    RequestService::fail('Outing state or membership changed. Verify again.');
                }
                DB::table('gate_verification')->where('id', $id)->update(['used_at' => now()]);

                return $group ? $this->gate->batch((int) $data['group_id'], array_map('strval', $data['members']), $current) : $this->gate->execute($data['std_no'], $current, $data['action'], isset($data['record_id']) ? (int) $data['record_id'] : (isset($data['request_id']) ? (int) $data['request_id'] : null));
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            RequestService::fail('Movement already changed. Look up or scan again.');
        }
    }
}

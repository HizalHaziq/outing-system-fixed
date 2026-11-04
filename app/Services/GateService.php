<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class GateService
{
    public function __construct(private Curfew $curfew, private RequestService $requests, private GroupService $groups, private Notifier $notify) {}

    public function route(string $std, bool $group = false): array
    {
        $s = DB::table('student')->select('std_no', 'std_name', 'is_active', 'program', 'phone')->where('std_no', $std)->first();
        $base = ['std_no' => $std, 'student' => $s ? (array) $s : null, 'curfew' => $this->curfew->active(), 'state' => 'not_found', 'track' => null, 'direction' => null, 'standard_open' => null, 'special_active' => null, 'special_ready' => null, 'arrival_approval' => null];
        if (! $s) {
            return $base;
        }
        $standard = DB::table('standard_outing')->where('std_no', $std)->whereNull('checked_in_at')->first();
        $special = DB::table('outing_request')->where('std_no', $std)->where('type', 'checkout')->whereIn('status', ['checked_out', 'overdue'])->first();
        $ready = DB::table('outing_request')->where('std_no', $std)->where('type', 'checkout')->where('status', 'approved')->orderBy('requested_out_at')->first();
        $base['standard_open'] = $standard ? (array) $standard : null;
        $base['special_active'] = $special ? (array) $special : null;
        $base['special_ready'] = $ready ? (array) $ready : null;
        $base['arrival_approval'] = $this->requests->arrival($std);
        if ($standard && $special) {
            RequestService::fail('Conflicting open outings. Ask an administrator to reconcile gate history.');
        }
        if ($standard) {
            $state = 'standard_out';
            $track = 'standard';
            $direction = 'in';
        } elseif ($special) {
            $state = $group ? 'special_excluded' : 'special_out';
            $track = 'special';
            $direction = $group ? null : 'in';
        } elseif (! $s->is_active) {
            $state = 'inactive';
            $track = null;
            $direction = null;
        } elseif ($group && $this->groups->hasSpecial($std)) {
            $state = 'special_excluded';
            $track = 'special';
            $direction = null;
        } elseif ($ready) {
            $state = 'special_ready';
            $track = 'special';
            $direction = 'out';
        } elseif ($base['curfew']) {
            $state = 'blocked_curfew';
            $track = 'special';
            $direction = null;
        } else {
            $state = 'standard_ready';
            $track = 'standard';
            $direction = 'out';
        }

        return array_replace($base, compact('state', 'track', 'direction'));
    }

    public function fingerprint(string $subject): string
    {
        if (str_starts_with($subject, 'G:')) {
            $id = (int) substr($subject, 2);
            $g = $this->groups->find($id);
            $view = $this->groupView($id);
            $data = [$g['status'] ?? null, $g['expires_at'] ?? null, $view['phase']];
            foreach ($view['rows'] as $r) {
                $data[] = [$r['std_no'], $r['actionable'], $this->signature($r['route'])];
            }
        } else {
            $data = $this->signature($this->route(substr($subject, 2)));
        }

        return hash('sha256', json_encode($data));
    }

    private function signature(array $r): array
    {
        return [$r['std_no'], $r['state'], $r['student']['is_active'] ?? null, $r['curfew'], $r['standard_open']['id'] ?? null, $r['special_active']['id'] ?? null, $r['special_ready']['id'] ?? null, $r['arrival_approval']['id'] ?? null];
    }

    public function groupView(int $id): array
    {
        $participants = $this->groups->participants($id);
        $logs = DB::table('outing_group_log')->where('group_id', $id)->get()->keyBy('std_no');
        $phase = $this->groups->hasOutstanding($id) ? 'return' : 'out';
        $rows = [];
        foreach ($participants as $p) {
            $route = $this->route($p['std_no'], true);
            $l = $logs[$p['std_no']] ?? null;
            $actionable = in_array($route['state'], ['standard_ready', 'standard_out']);
            $note = self::label($route);
            $tone = $actionable ? 'ok' : 'bad';
            if ($phase === 'return' && (! $l || $l->in_at || $route['direction'] !== 'in')) {
                $actionable = false;
                $note = $l?->in_at ? 'Already checked in' : 'Did not go out with this group';
                $tone = 'info';
            }
            if ($phase === 'out' && $route['direction'] === 'in') {
                $actionable = false;
                $note = 'Not a departure for this group';
            }
            $rows[] = $p + compact('route', 'actionable', 'note', 'tone');
        }

return compact('phase', 'rows');
    }

    public static function label(array $r): string
    {
        return match ($r['state']) {
            'standard_ready' => 'Check out (standard)','standard_out' => ! empty($r['arrival_approval']) ? 'Check in (standard outing, using arrival permission)' : 'Check in (standard)','special_out' => 'Check in (special)','special_ready' => 'Check out (approved request)','special_excluded' => 'Has a special request -- process individually (not part of group)','inactive' => 'Account inactive: departure blocked','blocked_curfew' => 'Blocked: curfew, no approved request',default => 'Not found'
        };
    }

    // Must be called inside the verification transaction, with student and any group locked.
    public function execute(string $std, Staff $guard, string $action, ?int $ref = null, ?int $group = null): string
    {
        abort_unless($guard->is_active && $guard->role === 'guard', 403);
        $s = Student::where('std_no', $std)->lockForUpdate()->firstOrFail();
        $r = $this->route($std, $group !== null);
        $expected = match ($r['state']) {
            'standard_ready' => 'standard_check_out','standard_out' => 'standard_check_in','special_ready' => 'special_check_out','special_out' => 'special_check_in',default => null
        };
        if (! $expected || $action !== $expected) {
            RequestService::fail('State changed or action unavailable. Look up the student again.');
        }
        $kind = $r['track'];
        $direction = $r['direction'];
        if ($direction === 'out') {
            if (! $s->is_active) {
                RequestService::fail('Inactive accounts cannot depart.');
            }
            if ($this->requests->isOut($std) || DB::table('active_outing')->where('std_no', $std)->exists()) {
                RequestService::fail('Student already has an open outing.');
            }
            if ($kind === 'special') {
                $ready = $r['special_ready'];
                if ($ref !== (int) $ready['id']) {
                    RequestService::fail('Request changed.');
                }
                // An approved future departure cannot be used before its scheduled time.
                if ($ready['requested_out_at'] && now()->lt($ready['requested_out_at'])) {
                    RequestService::fail('Approved departure time has not arrived.');
                }
                $id = $ready['id'];
                DB::table('outing_request')->where('id', $id)->where('status', 'approved')->update(['status' => 'checked_out', 'actual_out_at' => now()]);
                $this->requests->event($id, 'checked_out', 'staff', $guard->staff_id);
            } else {
                if ($this->curfew->active()) {
                    RequestService::fail('Curfew is active. An approved special request is required.');
                }
                $id = DB::table('standard_outing')->insertGetId(['std_no' => $std, 'checked_out_at' => now(), 'checked_out_by' => $guard->staff_id, 'gate_location_out' => 'Main Gate', 'created_at' => now()]);
            }
            DB::table('active_outing')->insert(['std_no' => $std, 'kind' => $kind, 'ref_id' => $id]);
        } else {
            $row = $kind === 'standard' ? $r['standard_open'] : $r['special_active'];
            $id = (int) $row['id'];
            if ($ref !== $id) {
                RequestService::fail('Open outing changed. Look up the student again.');
            }
            if ($kind === 'standard') {
                $arrival = $this->requests->arrival($std);
                $late = $this->curfew->active() && ! $arrival;
                DB::table('standard_outing')->where('id', $id)->whereNull('checked_in_at')->update(['checked_in_at' => now(), 'checked_in_by' => $guard->staff_id, 'gate_location_in' => 'Main Gate', 'is_late_return' => $late ? 1 : 0, 'arrival_request_id' => $arrival['id'] ?? null]);
                $this->requests->finishArrivals($std, $guard, $arrival);
                if ($late) {
                    $this->notify->student($std, 'violation', 'A late return was recorded on your account.', 'standard_outing', $id);
                }
            } else {
                DB::table('outing_request')->where('id', $id)->whereIn('status', ['checked_out', 'overdue'])->update(['status' => 'checked_in', 'actual_return_at' => now()]);
                $this->requests->event($id, 'checked_in', 'staff', $guard->staff_id);
                $this->requests->finishArrivals($std, $guard, null);
            }
            DB::table('active_outing')->where('std_no', $std)->delete();
        }
        if ($kind === 'standard') {
            $this->groups->movement($std, $direction, $id, $group);
        }

        return self::label($r).'.';
    }

    public function batch(int $id, array $selected, Staff $guard): array
    {
        $g = $this->groups->find($id);
        if (! $g) {
            RequestService::fail('Group closed or expired.');
        }
        $view = $this->groupView($id);
        $by = collect($view['rows'])->keyBy('std_no');
        foreach ($selected as $std) {
            if (! isset($by[$std])) {
                RequestService::fail('Selected student is not an accepted participant.');
            }
        }
        Student::whereIn('std_no', $by->keys())->orderBy('std_no')->lockForUpdate()->get();
        $view = $this->groupView($id);
        $done = [];
        $skipped = [];
        foreach ($view['rows'] as $row) {
            $std = $row['std_no'];
            if (! in_array($std, $selected, true)) {
                continue;
            }
            if (! $row['actionable']) {
                $skipped[] = $std.': '.$row['note'];

                continue;
            }
            if ($view['phase'] === 'out' && now()->gte($g['expires_at'])) {
                $skipped[] = $std.': Group departure window expired';

                continue;
            }
            $route = $row['route'];
            $action = $route['direction'] === 'in' ? 'standard_check_in' : 'standard_check_out';
            $done[] = $std.': '.$this->execute($std, $guard, $action, isset($route['standard_open']['id']) ? (int) $route['standard_open']['id'] : null, $id);
        }
        DB::table('outing_group_scan')->insert(['group_id' => $id, 'guard_id' => $guard->staff_id, 'gate_location' => 'Main Gate', 'processed_count' => count($done), 'skipped_count' => count($skipped), 'created_at' => now()]);

        return compact('done','skipped') + ['closed' => DB::table('outing_group')->where('id',$id)->value('status') === 'closed'];
    }
}

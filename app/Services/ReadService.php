<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class ReadService
{
    public function rows($q): array
    {
        return $q->get()->map(fn ($r) => (array) $r)->all();
    }

    public function one($q): ?array
    {
        $r = $q->first();

        return $r ? (array) $r : null;
    }

    public function requests()
    {
        return DB::table('outing_request as r')->join('student as s', 's.std_no', '=', 'r.std_no')->select('r.*', 's.std_name', 's.email as student_email');
    }

    public function range($q, string $col, ?string $from, ?string $to)
    {
        if ($from) {
            $q->where($col, '>=', $from.' 00:00:00');
        }if ($to) {
            $q->where($col, '<=', $to.' 23:59:59');
        }

return $q;
    }

    public function get_student_requests(string $std): array
    {
        return $this->rows(DB::table('outing_request')->where('std_no', $std)->orderByDesc('created_at'));
    }

    public function get_request_for_student(int $id, string $std): ?array
    {
        return $this->one(DB::table('outing_request')->where('id', $id)->where('std_no', $std));
    }

    public function get_pending_requests(): array
    {
        return $this->rows($this->requests()->where('status', 'pending')->orderBy('created_at'));
    }

    public function get_all_requests(?string $status = null, ?string $type = null, ?string $from = null, ?string $to = null, ?array $in = null): array
    {
        $q = $this->requests()->leftJoin('outing_group_link as gl', function ($j) {
            $j->on('gl.ref_id', '=', 'r.id')->where('gl.kind', 'special');
        })->leftJoin('outing_group as og', 'og.id', '=', 'gl.group_id')->leftJoin('student as gs', 'gs.std_no', '=', 'og.lead_std_no')->addSelect('gl.group_id', 'gs.std_name as group_lead_name');
        if ($in) {
            $q->whereIn('r.status', $in);
        }if ($status) {
            $q->where('r.status', $status);
        }if ($type) {
            $q->where('r.type', $type);
        }

return $this->rows($this->range($q, 'r.created_at', $from, $to)->orderByDesc('r.created_at'));
    }

    public function find_student_for_gate(string $std): ?array
    {
        return $this->one(DB::table('student')->select('std_no', 'std_name', 'is_active', 'program', 'phone')->where('std_no', $std));
    }

    public function get_open_standard_outing(string $std): ?array
    {
        return $this->one(DB::table('standard_outing')->where('std_no', $std)->whereNull('checked_in_at'));
    }

    public function get_active_checkout(string $std): ?array
    {
        return $this->one(DB::table('outing_request')->where('std_no', $std)->where('type', 'checkout')->whereIn('status', ['checked_out', 'overdue']));
    }

    public function get_checkoutable_request(string $std): ?array
    {
        return $this->one(DB::table('outing_request')->where('std_no', $std)->where('type', 'checkout')->where('status', 'approved')->orderBy('requested_out_at'));
    }

    public function get_special_currently_out(bool $overdue = false): array
    {
        $q = $this->requests()->where('r.type', 'checkout')->whereIn('r.status', $overdue ? ['overdue'] : ['checked_out', 'overdue']);

        return $this->rows($q->orderByDesc('actual_out_at'));
    }

    public function get_pending_arrivals(bool $overdue = false): array
    {
        return $this->rows($this->requests()->where('r.type', 'checkin')->whereIn('r.status', $overdue ? ['overdue'] : ['approved', 'overdue'])->orderBy('expected_return_at'));
    }

    public function standards()
    {
        return DB::table('standard_outing as so')->join('student as s', 's.std_no', '=', 'so.std_no')->leftJoin('outing_group_link as gl', function ($j) {
            $j->on('gl.ref_id', '=', 'so.id')->where('gl.kind', 'standard');
        })->leftJoin('outing_group as og', 'og.id', '=', 'gl.group_id')->leftJoin('student as gs', 'gs.std_no', '=', 'og.lead_std_no')->select('so.*', 's.std_name', 'gl.group_id', 'gs.std_name as group_lead_name');
    }

    public function get_standard_currently_out(): array
    {
        return $this->rows($this->standards()->whereNull('checked_in_at')->orderByDesc('checked_out_at'));
    }

    public function get_standard_history(bool $late = false, ?string $from = null, ?string $to = null): array
    {
        $q = $this->standards();
        if ($late) {
            $q->where('is_late_return', 1);
        }

return $this->rows($this->range($q, 'checked_out_at', $from, $to)->orderByDesc('checked_out_at'));
    }

    public function count_late_returns(int $days = 7): int
    {
        return DB::table('standard_outing')->where('is_late_return', 1)->where('checked_in_at', '>=', now()->subDays($days))->count();
    }

    public function get_all_staff(): array
    {
        return $this->rows(DB::table('staff')->select('staff_id', 'name', 'email', 'role', 'is_active')->orderBy('role')->orderBy('name'));
    }

    public function get_student_profile(string $std): ?array
    {
        return $this->one(DB::table('student')->select('std_no', 'std_name', 'ic_no', 'program', 'email', 'phone', 'emergency_contact_name', 'emergency_contact_phone')->where('std_no', $std));
    }

    public function get_staff_profile(string $id): ?array
    {
        return $this->one(DB::table('staff')->select('staff_id', 'name', 'email', 'role')->where('staff_id', $id));
    }

    public function late(object|array $r): bool
    {
        $r = (object) $r;
        if ($r->unreviewed_by_staff || ! $r->expected_return_at) {
            return false;
        }
        $limit = Time::parse($r->expected_return_at)->addMinutes((int) app(Settings::class)->get('special_tolerance_minutes'));

        return ($r->actual_return_at && Time::parse($r->actual_return_at)->gt($limit)) || ($r->type === 'checkout' && DB::table('outing_event')->where('request_id', $r->id)->where('event_type', 'overdue_flagged')->exists());
    }

    public function history(?string $std = null): array
    {
        $q = $this->standards();
        if ($std) {
            $q->where('so.std_no', $std);
        }$rows = [];
        foreach ($q->get() as $o) {
            $arrival = $o->arrival_request_id ? DB::table('outing_request')->where('id', $o->arrival_request_id)->first() : null;
            $late = $o->is_late_return || ($arrival && $this->late($arrival));
            $rows[] = ['std_no' => $o->std_no, 'std_name' => $o->std_name, 'track' => 'standard', 'out_at' => $o->checked_out_at, 'back_at' => $o->checked_in_at, 'sort_at' => $o->checked_out_at, 'result' => $late ? 'late' : ($o->checked_in_at ? 'on_time' : 'out'), 'with_arrival' => (bool) $arrival, 'group_id' => $o->group_id, 'group_lead_name' => $o->group_lead_name, 'due_at' => $arrival?->expected_return_at, 'violation_at' => $o->checked_in_at, 'request_id' => $o->arrival_request_id];
        }
        $q = $this->requests();
        if ($std) {
            $q->where('r.std_no', $std);
        }
        foreach ($q->get() as $r) {
            if ($r->type === 'checkout' && ! $r->actual_out_at) {
                continue;
            }
            if ($r->type === 'checkin' && (! in_array($r->status, ['approved', 'checked_in', 'overdue']) || DB::table('standard_outing')->where('arrival_request_id', $r->id)->exists())) {
                continue;
            }
            $rows[] = ['std_no' => $r->std_no, 'std_name' => $r->std_name, 'track' => 'special_'.$r->type, 'out_at' => $r->actual_out_at, 'back_at' => $r->actual_return_at, 'sort_at' => $r->actual_out_at ?? $r->actual_return_at ?? $r->expected_return_at, 'result' => $this->late($r) ? 'late' : ($r->actual_return_at ? 'on_time' : ($r->type === 'checkin' ? 'awaiting' : 'out')), 'with_arrival' => false, 'group_id' => null, 'group_lead_name' => null, 'due_at' => $r->expected_return_at, 'violation_at' => $r->actual_return_at ?? $r->expected_return_at, 'request_id' => $r->id];
        }
        usort($rows, fn ($a, $b) => strcmp($b['sort_at'] ?? '', $a['sort_at'] ?? ''));

        return $rows;
    }

    public function get_student_outing_history(string $std): array
    {
        return $this->history($std);
    }

    public function get_violations(?string $std = null, ?string $from = null, ?string $to = null): array
    {
        return array_values(array_filter($this->history($std), function ($r) use ($from, $to) {
            $date = substr($r['violation_at'] ?? '', 0, 10);

            return $r['result'] === 'late' && (! $from || $date >= $from) && (! $to || $date <= $to);
        }));
    }

    public function get_violation_summary(?string $from = null, ?string $to = null): array
    {
        $by = [];
        foreach ($this->get_violations(null, $from, $to) as $r) {
            $key = $r['std_no'];
            $by[$key] ??= ['std_no' => $key, 'std_name' => $r['std_name'], 'violations' => 0, 'last_violation_at' => $r['violation_at']];
            $by[$key]['violations']++;
            $by[$key]['last_violation_at'] = max($by[$key]['last_violation_at'], $r['violation_at']);
        }usort($by, fn ($a, $b) => $b['violations'] <=> $a['violations']);

        return array_values($by);
    }

    public function search_violations(array $rows, ?string $search): array
    {
        return array_values(array_filter($rows, fn ($r) => ! $search || mb_stripos($r['std_name'], $search) !== false || mb_stripos($r['std_no'], $search) !== false));
    }

    public function count_overdue_now(): int
    {
        $n = 0;
        $t = (int) app(Settings::class)->get('special_tolerance_minutes');
        foreach (DB::table('outing_request')->whereNotNull('expected_return_at')->whereIn('status', ['checked_out', 'approved', 'overdue'])->get() as $r) {
            if (($r->type === 'checkout' && ! in_array($r->status, ['checked_out', 'overdue'])) || ($r->type === 'checkin' && ! in_array($r->status, ['approved', 'overdue']))) {
                continue;
            }if (now()->gt(Time::parse($r->expected_return_at)->addMinutes($t))) {
                $n++;
            }
        }

return $n;
    }

    public function get_dashboard_counts(): array
    {
        $special = count($this->get_special_currently_out());
        $standard = DB::table('standard_outing')->whereNull('checked_in_at')->count();

        return ['out_now' => $special + $standard, 'special_out' => $special, 'standard_out' => $standard, 'due_today' => count($this->get_due_back_today(100000)), 'overdue_now' => $this->count_overdue_now()];
    }

    public function get_due_back_today(int $limit = 10): array
    {
        return $this->rows($this->requests()->whereDate('expected_return_at', today()->toDateString())->where(function ($q) {
            $q->where(function ($q) {
                $q->where('r.type', 'checkout')->whereIn('r.status', ['checked_out', 'overdue']);
            })->orWhere(function ($q) {
                $q->where('r.type', 'checkin')->whereIn('r.status', ['approved', 'overdue']);
            });
        })->orderBy('expected_return_at')->limit($limit));
    }

    public function get_staff_summary(): array
    {
        $total = DB::table('staff')->count();
        $active = DB::table('staff')->where('is_active', 1)->count();

        return ['total' => $total, 'active' => $active, 'inactive' => $total - $active, 'by_role' => DB::table('staff')->where('is_active', 1)->selectRaw('role, COUNT(*) as c')->groupBy('role')->pluck('c', 'role')->all()];
    }

    public function get_recent_gate_activity(int $limit = 10): array
    {
        $rows = [];
        foreach ($this->get_standard_history() as $o) {
            foreach (['out', 'in'] as $d) {
                $at = $o['checked_'.$d.'_at'];
                if (! $at) {
                    continue;
                }$rows[] = ['activity_at' => $at, 'action' => 'checked_'.$d, 'std_name' => $o['std_name'], 'std_no' => $o['std_no'], 'track' => $d === 'in' && $o['arrival_request_id'] ? 'standard_arrival' : 'standard', 'staff_id' => $o['checked_'.$d.'_by'], 'gate_location' => $o['gate_location_'.$d]];
            }
        }
        foreach (DB::table('outing_event as e')->join('outing_request as r', 'r.id', '=', 'e.request_id')->join('student as s', 's.std_no', '=', 'r.std_no')->whereIn('e.event_type', ['checked_out', 'checked_in'])->select('e.*', 'r.type', 's.std_name', 'r.std_no')->get() as $e) {
            if ($e->type === 'checkin' && DB::table('standard_outing')->where('arrival_request_id',$e->request_id)->exists()) {
                continue;
            }$rows[] = ['activity_at' => $e->event_at, 'action' => $e->event_type, 'std_name' => $e->std_name, 'std_no' => $e->std_no, 'track' => 'special', 'staff_id' => $e->actor_id, 'gate_location' => $e->gate_location];
        }
        usort($rows,fn ($a,$b) => strcmp($b['activity_at'],$a['activity_at']));

        return array_slice($rows,0,$limit);
    }
}

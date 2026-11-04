<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Student;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestService
{
    public function __construct(private Curfew $curfew, private Notifier $notify) {}

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }

    public function event(int $id, string $event, string $actor = 'system', ?string $who = null, ?string $note = null): void
    {
        DB::table('outing_event')->insert(['request_id' => $id, 'event_type' => $event, 'actor_type' => $actor, 'actor_id' => $who, 'note' => $note, 'event_at' => now(), 'gate_location' => $actor === 'staff' && in_array($event, ['checked_in', 'checked_out']) ? 'Main Gate' : null]);
    }

    public function isOut(string $std): bool
    {
        return DB::table('standard_outing')->where('std_no', $std)->whereNull('checked_in_at')->exists() || DB::table('outing_request')->where('std_no', $std)->where('type', 'checkout')->whereIn('status', ['checked_out', 'overdue'])->exists();
    }

    public function save(Student $student, array $data, ?int $id = null): int
    {
        return DB::transaction(function () use ($student, $data, $id) {
            $s = Student::where('id', $student->id)->lockForUpdate()->firstOrFail();
            if (! $s->is_active) {
                self::fail('Account inactive.');
            }
            $r = $id ? DB::table('outing_request')->where('id', $id)->where('std_no', $s->std_no)->lockForUpdate()->first() : null;
            if ($id && (! $r || $r->status !== 'pending')) {
                self::fail('Only your own pending requests can be edited.');
            }
            $type = $r?->type ?? $data['type'];
            $time = $type === 'checkout' ? $data['requested_out_at'] : $data['expected_return_at'];
            if (! $time || Time::parse($time)->lt(now()->subMinutes(5)) || ! $this->curfew->active($time)) {
                self::fail('Choose a current or future time inside curfew ('.$this->curfew->label().').');
            }
            if ($type === 'checkin' && ! $this->isOut($s->std_no)) {
                self::fail('Arrival permission can only be requested while checked out.');
            }
            if ($type === 'checkout' && $this->isOut($s->std_no)) {
                self::fail('You are already checked out. Request arrival permission if needed.');
            }
            if ($type === 'checkout' && ! empty($data['expected_return_at']) && Time::parse($data['expected_return_at'])->lte(Time::parse($time))) {
                self::fail('Expected return must be after departure.');
            }
            $duplicates = DB::table('outing_request')->where('std_no', $s->std_no)->where('type', $type)->whereIn('status', ['pending', 'approved']);
            if ($id) {
                $duplicates->where('id', '!=', $id);
            }
            if ($duplicates->exists()) {
                self::fail('You already have a pending or approved request of this type.');
            }
            $values = ['std_no' => $s->std_no, 'type' => $type, 'reason' => $data['reason'], 'destination' => $type === 'checkout' ? $data['destination'] : null, 'coming_from' => $type === 'checkin' ? $data['destination'] : null, 'requested_out_at' => $type === 'checkout' ? Time::parse($time)->format('Y-m-d H:i:s') : null, 'expected_return_at' => ! empty($data['expected_return_at']) ? Time::parse($data['expected_return_at'])->format('Y-m-d H:i:s') : null];
            if ($id) {
                DB::table('outing_request')->where('id', $id)->update($values);
            } else {
                $id = DB::table('outing_request')->insertGetId($values + ['status' => 'pending', 'created_at' => now()]);
            }
            $this->event($id, 'submitted', 'student', $s->std_no, $r ? 'Edited by student' : null);
            if (! $r) {
                $this->notify->student($s->std_no, 'request_submitted', 'Your outing application has been submitted. CRS will review your application soon.', 'outing_request', $id);
            }

            return $id;
        }, 3);
    }

    public function cancel(Student $student, int $id): void
    {
        DB::transaction(function () use ($student, $id) {
            Student::where('id', $student->id)->lockForUpdate()->firstOrFail();
            $r = DB::table('outing_request')->where('id', $id)->where('std_no', $student->std_no)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending') {
                self::fail('Only your own pending requests can be cancelled.');
            }
            DB::table('outing_request')->where('id', $id)->update(['status' => 'cancelled']);
            $this->event($id, 'cancelled', 'student', $student->std_no);
        }, 3);
    }

    public function expire(?string $std = null): int
    {
        $count = 0;
        $q = DB::table('outing_request')->where('type', 'checkin')->where('status', 'pending');
        if ($std) {
            $q->where('std_no', $std);
        }
        foreach ($q->get() as $row) {
            if (now()->lte($this->curfew->reviewDeadline($row))) {
                continue;
            }
            $count += DB::transaction(function () use ($row) {
                Student::where('std_no', $row->std_no)->lockForUpdate()->firstOrFail();
                $r = DB::table('outing_request')->where('id', $row->id)->lockForUpdate()->first();
                if ($r->status !== 'pending' || now()->lte($this->curfew->reviewDeadline($r))) {
                    return 0;
                }
                DB::table('outing_request')->where('id', $r->id)->update(['status' => 'expired', 'unreviewed_by_staff' => 1]);
                $this->event($r->id, 'expired', note: 'Not reviewed before the review deadline');
                $this->notify->student($r->std_no, 'request_expired', 'Your arrival request was not reviewed in time. Arrival in its curfew window will not count as late.', 'outing_request', $r->id);

                return 1;
            }, 3);
        }

return $count;
    }

    public function review(Staff $staff, int $id, string $decision, ?string $reason): void
    {
        abort_unless($staff->role === 'warden' && $staff->is_active, 403);
        $student = DB::table('outing_request')->where('id', $id)->value('std_no');
        abort_unless($student, 404);
        $this->expire($student); // Commit expiration even when the attempted late review is rejected.
        DB::transaction(function () use ($staff, $id, $decision, $reason, $student) {
            $s = Student::where('std_no', $student)->lockForUpdate()->firstOrFail();
            if (! $s->is_active) {
                self::fail('Student account inactive.');
            }
            $r = DB::table('outing_request')->where('id', $id)->lockForUpdate()->first();
            if ($r->status !== 'pending') {
                self::fail('This request is no longer pending.');
            }
            if ($r->type === 'checkin' && (! $this->isOut($student) || now()->gt($this->curfew->reviewDeadline($r)))) {
                self::fail('Arrival request no longer reviewable.');
            }
            DB::table('outing_request')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $staff->staff_id, 'reviewed_at' => now(), 'rejection_reason' => $decision === 'rejected' ? $reason : null]);
            $this->event($id, $decision, 'staff', $staff->staff_id, $reason);
            $this->notify->student($student, 'request_'.$decision, 'Your outing request has been '.$decision.'.', 'outing_request', $id);
            if ($s->email) {
                $this->notify->mail('review:'.$id.':'.$decision, $s->email, 'Outing request '.$decision, 'Your outing request #'.$id.' has been '.$decision.'.'.($decision === 'rejected' ? ' Reason: '.$reason : ''));
            }
        }, 3);
    }

    public function arrival(string $std): ?array
    {
        $unreviewed = null;
        foreach (DB::table('outing_request')->where('std_no', $std)->where('type', 'checkin')->whereIn('status', ['approved', 'overdue', 'pending', 'expired'])->orderBy('expected_return_at')->get() as $r) {
            if (in_array($r->status, ['approved', 'overdue'])) {
                return (array) $r;
            }
            if (! $unreviewed && $this->curfew->coversUnreviewed($r)) {
                $unreviewed = (array) $r;
            }
        }

return $unreviewed;
    }

    public function finishArrivals(string $std, Staff $guard, ?array $arrival): void
    {
        if ($arrival) {
            $unreviewed = in_array($arrival['status'], ['pending', 'expired']);
            DB::table('outing_request')->where('id', $arrival['id'])->update(['status' => 'checked_in', 'actual_return_at' => now(), 'unreviewed_by_staff' => $unreviewed ? 1 : 0]);
            $this->event($arrival['id'], 'checked_in', 'staff', $guard->staff_id, $unreviewed ? 'Arrival request was not reviewed by staff' : null);
            if ($unreviewed) {
                $this->notify->student($std, 'arrival_unreviewed', 'Your arrival was recorded. Your request was not reviewed in time, so no late return was recorded.', 'outing_request', $arrival['id']);
            }
        }
        foreach (DB::table('outing_request')->where('std_no',$std)->where('type','checkin')->whereIn('status',['pending', 'approved', 'overdue', 'expired'])->get() as $r) {
            DB::table('outing_request')->where('id',$r->id)->update(['status' => 'cancelled']);
            $this->event($r->id,'cancelled',note: 'Student already back on campus');
        }
    }
}

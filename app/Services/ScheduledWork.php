<?php

namespace App\Services;

use App\Models\Student;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class ScheduledWork
{
    public function __construct(private RequestService $requests, private Notifier $notify, private Settings $settings, private Curfew $curfew) {}

    public function overdue(): int
    {
        $this->requests->expire();
        $count = 0;
        $t = (int) $this->settings->get('special_tolerance_minutes');
        foreach (DB::table('outing_request')->whereNotNull('expected_return_at')->whereIn('status', ['checked_out', 'approved'])->get() as $row) {
            if (($row->type === 'checkout' && $row->status !== 'checked_out') || ($row->type === 'checkin' && $row->status !== 'approved') || now()->lte(Time::parse($row->expected_return_at)->addMinutes($t))) {
                continue;
            }
            $count += DB::transaction(function () use ($row, $t) {
                Student::where('std_no', $row->std_no)->lockForUpdate()->firstOrFail();
                $r = DB::table('outing_request')->where('id', $row->id)->lockForUpdate()->first();
                if ($r->status !== $row->status || now()->lte(Time::parse($r->expected_return_at)->addMinutes($t))) {
                    return 0;
                }
                DB::table('outing_request')->where('id', $r->id)->update(['status' => 'overdue']);
                $this->requests->event($r->id, 'overdue_flagged', note: 'Past expected return time, including grace period');
                $this->notify->student($r->std_no, 'overdue', 'Your expected return or arrival time has passed. Please return to the gate.', 'outing_request', $r->id);
                $s = Student::where('std_no', $r->std_no)->first();
                $recipients = DB::table('staff')->where('is_active', 1)->whereIn('role', ['admin', 'warden'])->whereNotNull('email')->pluck('email')->all();
                if ($s->email) {
                    $recipients[] = $s->email;
                }
                foreach (array_unique($recipients) as $email) {
                    $this->notify->mail('overdue:'.$r->id, $email, 'Overdue outing alert', $s->std_name.' ('.$s->std_no.') is overdue on request #'.$r->id.'.');
                }

return 1;
            }, 3);
        }

return $count;
    }

    public function reminders(): int
    {
        $count = 0;
        $start = Time::now()->setTimeFromTimeString($this->settings->get('curfew_start'));
        if ($start->lte(now())) {
            $start = $start->addDay();
        }
        if (! $this->curfew->active()) {
            foreach (DB::table('standard_outing')->whereNull('checked_in_at')->get() as $r) {
                $left = ($start->timestamp - now()->timestamp) / 60;
                foreach ([60, 30, 10] as $mins) {
                    if ($left <= $mins && $left > $mins - 2) {
                        // Include the curfew date so a multi-day open trip can receive a new day's reminder.
                        $count += (int) $this->notify->student($r->std_no, 'time_alert_'.$mins, 'Reminder: curfew ('.$this->curfew->label().') starts in '.$mins.' minutes. Please check in soon.', 'standard_outing:'.$start->format('Ymd'), $r->id);
                    }
                }
            }
        }
        foreach (DB::table('outing_request')->where('type', 'checkout')->where('status', 'checked_out')->whereNotNull('expected_return_at')->get() as $r) {
            $left = (Time::parse($r->expected_return_at)->timestamp - now()->timestamp) / 60;
            if ($left <= 60 && $left > 58) {
                $count += (int) $this->notify->student($r->std_no,'time_alert_due',"You're due back in 1 hour.",'outing_request',$r->id);
            }
        }

        return $count;
    }
}

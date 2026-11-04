<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;

class Curfew
{
    public function __construct(private Settings $settings) {}

    public function active($at = null): bool
    {
        $time = Time::parse($at ?? now(), 'Asia/Kuala_Lumpur')->format('H:i:s');
        $start = $this->settings->get('curfew_start');
        $end = $this->settings->get('curfew_end');

        return $start <= $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    public function label(): string
    {
        return Time::parse($this->settings->get('curfew_start'))->format('g:i A').' - '.Time::parse($this->settings->get('curfew_end'))->format('g:i A');
    }

    public function window($at): array
    {
        $at = Time::parse($at);
        $start = $at->setTimeFromTimeString($this->settings->get('curfew_start'));
        $end = $at->setTimeFromTimeString($this->settings->get('curfew_end'));
        if ($end <= $start) {
            if ($at < $end) {
                $start = $start->subDay();
            } else {
                $end = $end->addDay();
            }
        }

        return [$start, $end];
    }

    public function reviewDeadline(object|array $r): Time
    {
        $r = (object) $r;
        $lead = (int) $this->settings->get('arrival_review_lead_minutes');

        return Time::parse($r->expected_return_at)->subMinutes($lead)->max(Time::parse($r->created_at)->addMinutes($lead));
    }

    public function coversUnreviewed(object|array $r): bool
    {
        $r = (object) $r;
        if (! $r->expected_return_at || ! $this->active($r->expected_return_at)) {
            return false;
        }[$start,$end] = $this->window($r->expected_return_at);

        return now()->gte($start) && now()->lt($end);
    }
}

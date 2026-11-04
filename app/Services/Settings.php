<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Settings
{
    const DEFAULTS = ['curfew_start' => '23:00:00', 'curfew_end' => '06:00:00', 'special_tolerance_minutes' => '15', 'arrival_review_lead_minutes' => '30', 'qr_refresh_seconds' => '30', 'allow_manual_gate_entry' => '1'];

    public function get(string $key): string
    {
        return (string) (DB::table('app_setting')->where('setting_key', $key)->value('setting_value') ?? self::DEFAULTS[$key] ?? '');
    }

    public function all(): array
    {
        $labels = ['curfew_start' => 'Curfew start', 'curfew_end' => 'Curfew end', 'special_tolerance_minutes' => 'Special outing tolerance (minutes)', 'arrival_review_lead_minutes' => 'Arrival request review deadline (minutes before arrival)', 'qr_refresh_seconds' => 'QR refresh interval (seconds)', 'allow_manual_gate_entry' => 'Allow manual student-number entry at the gate'];
        $rows = [];
        foreach (self::DEFAULTS as $key => $default) {
            $rows[$key] = ['key' => $key, 'value' => $this->get($key), 'label' => $labels[$key], 'help' => '', 'type' => str_starts_with($key, 'curfew_') ? 'time' : ($key === 'allow_manual_gate_entry' ? 'bool' : 'number'), 'min' => $key === 'qr_refresh_seconds' ? 30 : 0, 'max' => $key === 'qr_refresh_seconds' ? 60 : 720];
        }

return $rows;
    }

    public function save(array $values, string $staff): void
    {
        DB::transaction(function () use ($values, $staff) {
            foreach (self::DEFAULTS as $k => $v) {
                DB::table('app_setting')->updateOrInsert(['setting_key' => $k], ['setting_value' => str_starts_with($k, 'curfew_') && strlen($values[$k]) === 5 ? $values[$k].':00' : $values[$k], 'updated_by' => $staff, 'updated_at' => now()]);
            }
        });
    }
}

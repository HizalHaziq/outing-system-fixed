<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['curfew_start' => 'required|date_format:H:i|different:curfew_end', 'curfew_end' => 'required|date_format:H:i', 'special_tolerance_minutes' => 'required|integer|between:0,720', 'arrival_review_lead_minutes' => 'required|integer|between:0,720', 'qr_refresh_seconds' => 'required|integer|between:30,60', 'allow_manual_gate_entry' => 'required|in:0,1'];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OutingFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['id' => 'nullable|integer|min:1', 'type' => 'required|in:checkout,checkin', 'reason' => 'required|string|max:255', 'destination' => 'required|string|max:255', 'requested_out_at' => 'nullable|required_if:type,checkout|date_format:Y-m-d\\TH:i', 'expected_return_at' => 'nullable|required_if:type,checkin|date_format:Y-m-d\\TH:i'];
    }
}

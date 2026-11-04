<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GateFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['action' => 'required|in:standard_check_out,standard_check_in,special_check_out,special_check_in,group_process', 'std_no' => 'required_unless:action,group_process|nullable|regex:/^[A-Za-z0-9_-]{1,20}$/', 'group_id' => 'required_if:action,group_process|nullable|integer|min:1', 'record_id' => 'required_if:action,standard_check_in|nullable|integer|min:1', 'request_id' => 'required_if:action,special_check_in,special_check_out|nullable|integer|min:1', 'members' => 'required_if:action,group_process|array|max:5', 'members.*' => 'string|distinct|regex:/^[A-Za-z0-9_-]{1,20}$/', 'verification_id' => 'required|uuid', 'identity_confirmed' => 'accepted'];
    }
}

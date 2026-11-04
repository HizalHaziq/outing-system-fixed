<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['password' => 'required|string|max:200', $this->routeIs('staff.login') ? 'staff_id' : 'std_no' => 'required|string|max:20'];
    }
}

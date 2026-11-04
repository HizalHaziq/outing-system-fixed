<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['current_password' => 'required|string|max:200', 'new_password' => ['required', 'string', 'min:8', 'max:72', 'different:current_password', function ($a, $v, $fail) {
            if (strlen($v) > 72) {
                $fail('Password must be at most 72 bytes.');
            }
        }], 'confirm_password' => 'required|same:new_password'];
    }
}

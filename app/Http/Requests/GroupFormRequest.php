<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GroupFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['action' => 'required|in:create,invite,remove,close,accept,decline,leave', 'group_id' => 'required_if:action,invite,remove,close,leave|nullable|integer|min:1', 'member_std_no' => 'required_if:action,invite,remove|nullable|string|max:20', 'member_row_id' => 'required_if:action,accept,decline|nullable|integer|min:1'];
    }
}

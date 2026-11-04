<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => 'required|integer|min:1', 'decision' => 'required|in:approved,rejected', 'rejection_reason' => 'nullable|required_if:decision,rejected|string|max:255'];
    }
}

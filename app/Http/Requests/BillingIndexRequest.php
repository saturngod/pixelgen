<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class BillingIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|In|string>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(['credits', 'usage'])],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GenerationStatusesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * One History page shows at most 12 generations, so a small cap is enough.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:24'],
            'ids.*' => ['required', 'string', 'ulid'],
        ];
    }
}

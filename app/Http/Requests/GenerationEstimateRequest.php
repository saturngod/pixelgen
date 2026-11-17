<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerationEstimateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Empty reference image rows are allowed so errors line up with the form rows.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ];
    }
}

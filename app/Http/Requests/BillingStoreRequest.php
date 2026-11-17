<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class BillingStoreRequest extends FormRequest
{
    /**
     * The credit packs a user can buy, in dollars.
     *
     * @var list<string>
     */
    public const array CreditPacks = ['5', '10', '25'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|In|string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', Rule::in(self::CreditPacks)],
        ];
    }
}

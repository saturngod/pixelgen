<?php

namespace App\Http\Requests;

use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Validator;

class AdminWebhookDeliveryIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|In|string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'outcome' => ['nullable', 'string', Rule::in(WebhookDelivery::Outcomes)],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->startDate() === null || $this->endDate() === null) {
                    return;
                }

                if ($this->endDate()->lessThan($this->startDate())) {
                    $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
                }
            },
        ];
    }

    /**
     * The first UTC day to show, or null for no lower bound.
     */
    public function startDate(): ?CarbonImmutable
    {
        $startDate = $this->input('start_date');

        return $startDate === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $startDate, 'UTC');
    }

    /**
     * The last UTC day to show, included, or null for no upper bound.
     */
    public function endDate(): ?CarbonImmutable
    {
        $endDate = $this->input('end_date');

        return $endDate === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'UTC');
    }
}

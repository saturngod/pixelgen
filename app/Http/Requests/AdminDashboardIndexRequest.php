<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminDashboardIndexRequest extends FormRequest
{
    /**
     * Pixelforge reports at most this many days of usage in one request.
     */
    public const int MaxDays = 92;

    /**
     * Days shown when no range is given, ending today.
     */
    public const int DefaultDays = 30;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d', 'required_with:end_date'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:start_date', 'after_or_equal:start_date', 'before_or_equal:today'],
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
                if ($validator->errors()->isNotEmpty() || $this->input('start_date') === null) {
                    return;
                }

                if ($this->startDate()->diffInDays($this->endDate()) >= self::MaxDays) {
                    $validator->errors()->add('start_date', 'The date range can cover at most '.self::MaxDays.' days.');
                }
            },
        ];
    }

    /**
     * The first UTC day of the report.
     */
    public function startDate(): CarbonImmutable
    {
        $startDate = $this->input('start_date');

        return $startDate === null
            ? CarbonImmutable::today('UTC')->subDays(self::DefaultDays - 1)
            : CarbonImmutable::createFromFormat('!Y-m-d', $startDate, 'UTC');
    }

    /**
     * The last UTC day of the report, included.
     */
    public function endDate(): CarbonImmutable
    {
        $endDate = $this->input('end_date');

        return $endDate === null
            ? CarbonImmutable::today('UTC')
            : CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'UTC');
    }
}

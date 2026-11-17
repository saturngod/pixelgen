<?php

namespace App\Http\Requests;

use App\Models\WebhookDelivery;
use App\Services\PixelforgeService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A signed Pixelforge webhook event. The signature is checked by middleware before this runs.
 */
class PixelforgeWebhookRequest extends FormRequest
{
    /**
     * Event types we act on. A ping only confirms the webhook works.
     */
    public const array GenerationEvents = ['generation.processing', 'generation.succeeded', 'generation.failed'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $isGenerationEvent = in_array($this->input('type'), self::GenerationEvents, true);

        return [
            'id' => ['required', 'string', 'starts_with:evt_', 'max:64'],
            'type' => ['required', 'string', 'max:64'],
            ...($isGenerationEvent ? [
                'data.object' => ['required', 'array'],
                ...PixelforgeService::generationRules('data.object'),
            ] : []),
        ];
    }

    /**
     * A bad payload is recorded and acknowledged, because a retry would carry the same payload.
     */
    protected function failedValidation(Validator $validator): void
    {
        WebhookDelivery::recordSigned($this, WebhookDelivery::InvalidPayload, [
            'reason' => mb_strimwidth('Unexpected fields: '.implode(', ', $validator->errors()->keys()), 0, 255, '...'),
        ]);

        throw new HttpResponseException(response()->noContent());
    }
}

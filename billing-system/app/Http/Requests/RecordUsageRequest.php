<?php

namespace App\Http\Requests;

use App\DTOs\UsageEventData;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class RecordUsageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation by pulling the Idempotency-Key header.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxFutureIso = now()->addMinutes(5)->toIso8601String();

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'customer_reference' => ['required', 'string', 'max:100'],
            'units' => ['required', 'integer', 'min:1', 'max:1000000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.$maxFutureIso],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.max' => 'The Idempotency-Key must not exceed 100 characters.',
            'occurred_at.before_or_equal' => 'The occurred_at timestamp cannot be more than 5 minutes in the future.',
            'units.min' => 'Units must be at least 1.',
            'units.max' => 'Units cannot exceed 1,000,000.',
        ];
    }

    /**
     * Convert request to strongly-typed DTO.
     */
    public function toDto(int $merchantId): UsageEventData
    {
        $hasExplicitOccurredAt = $this->filled('occurred_at');
        $occurredAt = $hasExplicitOccurredAt
            ? Carbon::parse($this->input('occurred_at'))->utc()
            : now()->utc();

        return new UsageEventData(
            merchantId: $merchantId,
            idempotencyKey: (string) $this->header('Idempotency-Key'),
            customerReference: (string) $this->input('customer_reference'),
            units: (int) $this->input('units'),
            occurredAt: $occurredAt,
            explicitOccurredAt: $hasExplicitOccurredAt,
        );
    }
}

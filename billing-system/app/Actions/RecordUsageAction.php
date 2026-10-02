<?php

namespace App\Actions;

use App\DTOs\RecordUsageResult;
use App\DTOs\UsageEventData;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class RecordUsageAction
{
    /**
     * Ingest customer usage event idempotently.
     */
    public function execute(UsageEventData $dto): RecordUsageResult
    {
        // 1. Look up customer by composite unique key [merchant_id, customer_reference]
        $customerId = Customer::where('merchant_id', $dto->merchantId)
            ->where('customer_reference', $dto->customerReference)
            ->value('id');

        if (! $customerId) {
            throw new NotFoundHttpException("Customer '{$dto->customerReference}' does not exist for this merchant.");
        }

        // 2. Check subscription coverage for occurred_at date
        // Verify starts_at <= occurredDate AND (ends_at IS NULL OR ends_at >= occurredDate)
        // Does not restrict to status = 'active' so late events for cancelled subscriptions remain valid
        $occurredDate = $dto->occurredAt->toDateString();

        $hasSubscription = Subscription::where('customer_id', $customerId)
            ->where('merchant_id', $dto->merchantId)
            ->where('starts_at', '<=', $occurredDate)
            ->where(function ($query) use ($occurredDate) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $occurredDate);
            })
            ->exists();

        if (! $hasSubscription) {
            throw new UnprocessableEntityHttpException("Customer does not have an active subscription covering occurred_at ({$occurredDate}).");
        }

        // 3. Perform atomic insert relying on unique index [merchant_id, idempotency_key]
        try {
            $event = UsageEvent::create([
                'merchant_id' => $dto->merchantId,
                'customer_id' => $customerId,
                'idempotency_key' => $dto->idempotencyKey,
                'units' => $dto->units,
                'occurred_at' => $dto->occurredAt->toDateTimeString(),
                'created_at' => now()->utc(),
            ]);

            return new RecordUsageResult(
                usageEvent: $event,
                customerReference: $dto->customerReference,
                isReplay: false
            );
        } catch (UniqueConstraintViolationException|QueryException $e) {
            // Check for duplicate key violation
            if ($e instanceof UniqueConstraintViolationException || $e->getCode() == 23000 || str_contains($e->getMessage(), '1062')) {
                return $this->handleDuplicateKey($dto, $customerId);
            }

            throw $e;
        }
    }

    /**
     * Handle unique key collision for idempotent requests.
     */
    private function handleDuplicateKey(UsageEventData $dto, int $customerId): RecordUsageResult
    {
        $existing = UsageEvent::where('merchant_id', $dto->merchantId)
            ->where('idempotency_key', $dto->idempotencyKey)
            ->first();

        if (! $existing) {
            throw new ConflictHttpException("Idempotency conflict detected.");
        }

        // Conflict check: customer must match
        if ($existing->customer_id !== $customerId) {
            throw new ConflictHttpException("Idempotency-Key was previously used with a different customer.");
        }

        // Conflict check: units must match
        if ($existing->units !== $dto->units) {
            throw new ConflictHttpException("Idempotency-Key was previously used with different units.");
        }

        // Conflict check: compare occurred_at only if explicitly supplied by client
        if ($dto->explicitOccurredAt) {
            if ($existing->occurred_at->format('Y-m-d H:i:s') !== $dto->occurredAt->format('Y-m-d H:i:s')) {
                throw new ConflictHttpException("Idempotency-Key was previously used with a different occurred_at timestamp.");
            }
        }

        return new RecordUsageResult(
            usageEvent: $existing,
            customerReference: $dto->customerReference,
            isReplay: true
        );
    }
}

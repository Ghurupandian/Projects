<?php

namespace App\Jobs;

use App\Actions\GenerateSubscriptionInvoiceAction;
use App\Actions\RecomputeCycleDailyUsageAction;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateSubscriptionInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * @param  list<int>  $subscriptionIds
     */
    public function __construct(
        public readonly array $subscriptionIds,
        public readonly string $cycleStart,
        public readonly string $cycleEnd,
    ) {}

    public function handle(
        RecomputeCycleDailyUsageAction $recomputeDailyUsage,
        GenerateSubscriptionInvoiceAction $generateInvoice,
    ): void {
        $customerIds = Subscription::query()
            ->whereIn('id', $this->subscriptionIds)
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $recomputeDailyUsage->execute($this->cycleStart, $this->cycleEnd, $customerIds);

        $failedSubscriptionIds = [];

        foreach ($this->subscriptionIds as $subscriptionId) {
            try {
                $generateInvoice->execute((int) $subscriptionId, $this->cycleStart, $this->cycleEnd);
            } catch (Throwable $exception) {
                $failedSubscriptionIds[] = (int) $subscriptionId;
                Log::error('Invoice generation failed for a subscription; continuing with the chunk.', [
                    'subscription_id' => $subscriptionId,
                    'cycle_start' => $this->cycleStart,
                    'cycle_end' => $this->cycleEnd,
                    'exception' => $exception,
                ]);
            }
        }

        if ($failedSubscriptionIds !== []) {
            throw new RuntimeException(
                'Invoice generation failed for subscription IDs: '.implode(', ', $failedSubscriptionIds),
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Invoice generation chunk exhausted its retries.', [
            'subscription_ids' => $this->subscriptionIds,
            'cycle_start' => $this->cycleStart,
            'cycle_end' => $this->cycleEnd,
            'exception' => $exception,
        ]);
    }
}

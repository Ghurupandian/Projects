<?php

namespace App\Jobs;

use App\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

class PrepareCycleInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $cycleStart,
        public readonly string $cycleEnd,
    ) {}

    public function handle(): void
    {
        $start = $this->parseCycleDate($this->cycleStart);
        $end = $this->parseCycleDate($this->cycleEnd);

        if ($start->format('d') !== '01'
            || $end->format('Y-m-d') !== $start->modify('last day of this month')->format('Y-m-d')) {
            throw new InvalidArgumentException('Invoice dates must describe one complete calendar month.');
        }

        Subscription::query()
            ->whereDate('starts_at', '<=', $this->cycleEnd)
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhereDate('ends_at', '>=', $this->cycleStart);
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions): void {
                GenerateSubscriptionInvoicesJob::dispatch(
                    $subscriptions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    $this->cycleStart,
                    $this->cycleEnd,
                );
            });
    }

    private function parseCycleDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invoice dates must be valid YYYY-MM-DD dates.');
        }

        return $parsed;
    }
}

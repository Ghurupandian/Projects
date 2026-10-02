<?php

namespace App\Actions;

use App\DTOs\Billing\BillingCalculationInput;
use App\DTOs\Billing\BillingSegmentInput;
use App\DTOs\Billing\DailyUsageInput;
use App\Events\InvoiceGenerated;
use App\Models\DailyUsage;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\BillingCalculationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class GenerateSubscriptionInvoiceAction
{
    public function __construct(private readonly BillingCalculationService $calculator) {}

    public function execute(int $subscriptionId, string $cycleStart, string $cycleEnd): ?Invoice
    {
        if ($this->findInvoice($subscriptionId, $cycleStart, $cycleEnd) !== null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($subscriptionId, $cycleStart, $cycleEnd): ?Invoice {
                if ($this->findInvoice($subscriptionId, $cycleStart, $cycleEnd) !== null) {
                    return null;
                }

                $subscription = Subscription::query()
                    ->with(['segments.plan', 'customer'])
                    ->findOrFail($subscriptionId);

                $dailyUsages = DailyUsage::query()
                    ->where('customer_id', $subscription->customer_id)
                    ->whereBetween('usage_date', [$cycleStart, $cycleEnd])
                    ->orderBy('usage_date')
                    ->get()
                    ->map(fn (DailyUsage $usage): DailyUsageInput => new DailyUsageInput(
                        usageDate: $usage->usage_date->toDateString(),
                        totalUnits: $usage->total_units,
                    ))
                    ->all();

                $segments = $subscription->segments
                    ->map(fn ($segment): BillingSegmentInput => new BillingSegmentInput(
                        planId: $segment->plan_id,
                        startsAt: $segment->starts_at->toDateString(),
                        endsAt: $segment->ends_at?->toDateString(),
                        basePricePaise: $segment->plan->base_price_paise,
                        includedUnits: $segment->plan->included_units,
                        overageRatePaise: $segment->plan->overage_rate_paise,
                    ))
                    ->all();

                $calculation = $this->calculator->calculate(new BillingCalculationInput(
                    cycleStart: $cycleStart,
                    cycleEnd: $cycleEnd,
                    subscriptionStart: $subscription->starts_at->toDateString(),
                    subscriptionEnd: $subscription->ends_at?->toDateString(),
                    segments: $segments,
                    dailyUsages: $dailyUsages,
                ));

                $invoice = Invoice::query()->create([
                    'merchant_id' => $subscription->merchant_id,
                    'customer_id' => $subscription->customer_id,
                    'subscription_id' => $subscription->id,
                    'invoice_number' => $this->invoiceNumber($subscriptionId, $cycleStart),
                    'cycle_start' => $cycleStart,
                    'cycle_end' => $cycleEnd,
                    'base_amount_paise' => $calculation->baseAmountPaise,
                    'overage_amount_paise' => $calculation->overageAmountPaise,
                    'total_amount_paise' => $calculation->totalAmountPaise,
                    'currency' => 'INR',
                    'status' => 'issued',
                ]);

                foreach ($calculation->segments as $segmentResult) {
                    $segmentInput = $subscription->segments->firstWhere('plan_id', $segmentResult->planId);
                    $planName = $segmentInput?->plan->name ?? 'Plan '.$segmentResult->planId;

                    $invoice->lineItems()->create([
                        'plan_id' => $segmentResult->planId,
                        'description' => $planName.' subscription charges',
                        'segment_start' => $segmentResult->segmentStart,
                        'segment_end' => $segmentResult->segmentEnd,
                        'days_in_segment' => $segmentResult->daysInSegment,
                        'days_in_cycle' => $segmentResult->daysInCycle,
                        'units_used' => $segmentResult->unitsUsed,
                        'units_included' => $this->roundHalfUp(
                            $segmentResult->allowanceUnitsNumerator,
                            $segmentResult->daysInCycle,
                        ),
                        'units_included_numerator' => $segmentResult->allowanceUnitsNumerator,
                        'overage_units' => $this->roundHalfUp(
                            $segmentResult->overageUnitsNumerator,
                            $segmentResult->daysInCycle,
                        ),
                        'overage_units_numerator' => $segmentResult->overageUnitsNumerator,
                        'overage_rate_paise' => $segmentInput->plan->overage_rate_paise,
                        'base_amount_paise' => $segmentResult->baseAmountPaise,
                        'overage_amount_paise' => $segmentResult->overageAmountPaise,
                        'subtotal_paise' => $segmentResult->subtotalPaise,
                    ]);
                }

                event(new InvoiceGenerated($invoice));

                return $invoice;
            });
        } catch (QueryException $exception) {
            if ($this->findInvoice($subscriptionId, $cycleStart, $cycleEnd) !== null) {
                return null;
            }

            throw $exception;
        }
    }

    private function findInvoice(int $subscriptionId, string $cycleStart, string $cycleEnd): ?Invoice
    {
        return Invoice::query()
            ->where('subscription_id', $subscriptionId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->first();
    }

    private function invoiceNumber(int $subscriptionId, string $cycleStart): string
    {
        return sprintf('INV-%d-%s', $subscriptionId, str_replace('-', '', substr($cycleStart, 0, 7)));
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        $whole = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        $halfThreshold = intdiv($denominator, 2) + ($denominator % 2);

        return $remainder >= $halfThreshold ? $whole + 1 : $whole;
    }
}

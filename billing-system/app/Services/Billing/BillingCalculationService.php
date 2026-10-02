<?php

namespace App\Services\Billing;

use App\DTOs\Billing\BillingCalculationInput;
use App\DTOs\Billing\BillingCalculationResult;
use App\DTOs\Billing\BillingSegmentInput;
use App\DTOs\Billing\BillingSegmentResult;
use App\DTOs\Billing\DailyUsageInput;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

class BillingCalculationService
{
    public function calculate(BillingCalculationInput $input): BillingCalculationResult
    {
        $cycleStart = $this->parseDate($input->cycleStart, 'cycle start');
        $cycleEnd = $this->parseDate($input->cycleEnd, 'cycle end');

        if ($cycleStart->format('d') !== '01'
            || $cycleEnd->format('Y-m-d') !== $cycleStart->modify('last day of this month')->format('Y-m-d')) {
            throw new InvalidArgumentException('The billing cycle must be one complete calendar month.');
        }

        $daysInCycle = (int) $cycleStart->format('t');
        $subscriptionStart = $this->parseDate($input->subscriptionStart, 'subscription start');
        $subscriptionEnd = $input->subscriptionEnd === null
            ? null
            : $this->parseDate($input->subscriptionEnd, 'subscription end');

        if ($subscriptionEnd !== null && $subscriptionEnd < $subscriptionStart) {
            throw new InvalidArgumentException('The subscription end date cannot precede its start date.');
        }

        $dailyUnitsByDate = $this->indexDailyUsage($input->dailyUsages);
        $segments = $this->validateAndClipSegments(
            $input->segments,
            $cycleStart,
            $cycleEnd,
        );

        $activeStart = $this->maxDate($cycleStart, $subscriptionStart);
        $activeEnd = $subscriptionEnd === null
            ? $cycleEnd
            : $this->minDate($cycleEnd, $subscriptionEnd);

        if ($activeStart > $activeEnd) {
            return new BillingCalculationResult([], 0, 0, 0);
        }

        $activeStartString = $activeStart->format('Y-m-d');
        $activeEndString = $activeEnd->format('Y-m-d');
        $applicableSegments = [];

        foreach ($segments as $segment) {
            $segmentStart = $this->maxDate($segment['start'], $activeStart);
            $segmentEnd = $this->minDate($segment['end'], $activeEnd);

            if ($segmentStart <= $segmentEnd) {
                $applicableSegments[] = [
                    'input' => $segment['input'],
                    'start' => $segmentStart,
                    'end' => $segmentEnd,
                ];
            }
        }

        $this->assertContinuousCoverage($applicableSegments, $activeStartString, $activeEndString);

        $results = [];
        $totalBaseAmountPaise = 0;
        $totalOverageAmountPaise = 0;

        foreach ($applicableSegments as $segment) {
            /** @var BillingSegmentInput $plan */
            $plan = $segment['input'];
            $segmentStart = $segment['start'];
            $segmentEnd = $segment['end'];
            $daysInSegment = $this->inclusiveDays($segmentStart, $segmentEnd);
            $segmentStartString = $segmentStart->format('Y-m-d');
            $segmentEndString = $segmentEnd->format('Y-m-d');
            $unitsUsed = 0;

            foreach ($dailyUnitsByDate as $usageDate => $units) {
                if ($usageDate >= $segmentStartString && $usageDate <= $segmentEndString) {
                    $unitsUsed = $this->checkedAdd($unitsUsed, $units, 'segment usage');
                }
            }

            $allowanceUnitsNumerator = $this->checkedMultiply(
                $plan->includedUnits,
                $daysInSegment,
                'allowance units',
            );
            $usageOverCycle = $this->checkedMultiply($unitsUsed, $daysInCycle, 'usage units');
            $overageUnitsNumerator = max(0, $usageOverCycle - $allowanceUnitsNumerator);

            $baseNumerator = $this->checkedMultiply(
                $plan->basePricePaise,
                $daysInSegment,
                'base amount',
            );
            $overageNumerator = $this->checkedMultiply(
                $plan->overageRatePaise,
                $overageUnitsNumerator,
                'overage amount',
            );
            $baseAmountPaise = $this->roundHalfUp($baseNumerator, $daysInCycle);
            $overageAmountPaise = $this->roundHalfUp($overageNumerator, $daysInCycle);
            $subtotalPaise = $this->checkedAdd(
                $baseAmountPaise,
                $overageAmountPaise,
                'segment subtotal',
            );

            $results[] = new BillingSegmentResult(
                planId: $plan->planId,
                segmentStart: $segmentStartString,
                segmentEnd: $segmentEndString,
                daysInSegment: $daysInSegment,
                daysInCycle: $daysInCycle,
                unitsUsed: $unitsUsed,
                allowanceUnitsNumerator: $allowanceUnitsNumerator,
                overageUnitsNumerator: $overageUnitsNumerator,
                baseAmountPaise: $baseAmountPaise,
                overageAmountPaise: $overageAmountPaise,
                subtotalPaise: $subtotalPaise,
            );

            $totalBaseAmountPaise = $this->checkedAdd(
                $totalBaseAmountPaise,
                $baseAmountPaise,
                'cycle base amount',
            );
            $totalOverageAmountPaise = $this->checkedAdd(
                $totalOverageAmountPaise,
                $overageAmountPaise,
                'cycle overage amount',
            );
        }

        return new BillingCalculationResult(
            segments: $results,
            baseAmountPaise: $totalBaseAmountPaise,
            overageAmountPaise: $totalOverageAmountPaise,
            totalAmountPaise: $this->checkedAdd(
                $totalBaseAmountPaise,
                $totalOverageAmountPaise,
                'cycle total',
            ),
        );
    }

    /**
     * @param  list<DailyUsageInput>  $dailyUsages
     * @return array<string, int>
     */
    private function indexDailyUsage(array $dailyUsages): array
    {
        $unitsByDate = [];

        foreach ($dailyUsages as $dailyUsage) {
            if (! $dailyUsage instanceof DailyUsageInput) {
                throw new InvalidArgumentException('Daily usage entries must be DailyUsageInput instances.');
            }

            $usageDate = $this->parseDate($dailyUsage->usageDate, 'usage date')->format('Y-m-d');

            if (array_key_exists($usageDate, $unitsByDate)) {
                throw new InvalidArgumentException("Duplicate daily usage date: {$usageDate}.");
            }

            if ($dailyUsage->totalUnits < 0) {
                throw new InvalidArgumentException('Daily usage units cannot be negative.');
            }

            $unitsByDate[$usageDate] = $dailyUsage->totalUnits;
        }

        return $unitsByDate;
    }

    /**
     * @param  list<BillingSegmentInput>  $segments
     * @return list<array{input: BillingSegmentInput, start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    private function validateAndClipSegments(
        array $segments,
        DateTimeImmutable $cycleStart,
        DateTimeImmutable $cycleEnd,
    ): array {
        $clipped = [];

        foreach ($segments as $segment) {
            if (! $segment instanceof BillingSegmentInput) {
                throw new InvalidArgumentException('Plan segments must be BillingSegmentInput instances.');
            }

            if ($segment->planId <= 0
                || $segment->basePricePaise < 0
                || $segment->includedUnits < 0
                || $segment->overageRatePaise < 0) {
                throw new InvalidArgumentException('Plan identifiers must be positive and billing values non-negative.');
            }

            $startsAt = $this->parseDate($segment->startsAt, 'plan segment start');
            $endsAt = $segment->endsAt === null
                ? $cycleEnd
                : $this->parseDate($segment->endsAt, 'plan segment end');

            if ($endsAt < $startsAt) {
                throw new InvalidArgumentException('A plan segment end date cannot precede its start date.');
            }

            $start = $this->maxDate($startsAt, $cycleStart);
            $end = $this->minDate($endsAt, $cycleEnd);

            if ($start <= $end) {
                $clipped[] = ['input' => $segment, 'start' => $start, 'end' => $end];
            }
        }

        usort(
            $clipped,
            static fn (array $left, array $right): int => $left['start'] <=> $right['start']
                ?: $left['end'] <=> $right['end'],
        );

        return $clipped;
    }

    /**
     * @param  list<array{input: BillingSegmentInput, start: DateTimeImmutable, end: DateTimeImmutable}>  $segments
     */
    private function assertContinuousCoverage(
        array $segments,
        string $activeStart,
        string $activeEnd,
    ): void {
        $nextExpectedStart = $activeStart;
        $isCovered = false;

        foreach ($segments as $segment) {
            $start = $segment['start']->format('Y-m-d');
            $end = $segment['end']->format('Y-m-d');

            if ($isCovered) {
                throw new InvalidArgumentException('Plan segments overlap during the active billing period.');
            }

            if ($start < $nextExpectedStart) {
                throw new InvalidArgumentException('Plan segments overlap during the active billing period.');
            }

            if ($start > $nextExpectedStart) {
                throw new InvalidArgumentException('Plan segments have a gap during the active billing period.');
            }

            if ($end >= $activeEnd) {
                $isCovered = true;

                continue;
            }

            $nextExpectedStart = $segment['end']->modify('+1 day')->format('Y-m-d');
        }

        if (! $isCovered) {
            throw new InvalidArgumentException('Plan segments do not cover the full active billing period.');
        }
    }

    private function parseDate(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("The {$field} must be a valid date in YYYY-MM-DD format.");
        }

        return $date;
    }

    private function inclusiveDays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $difference = $start->diff($end)->days;

        if (! is_int($difference)) {
            throw new InvalidArgumentException('Unable to calculate the segment day count.');
        }

        return $difference + 1;
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('Rounding requires a non-negative numerator and positive denominator.');
        }

        $whole = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        $halfThreshold = intdiv($denominator, 2) + ($denominator % 2);

        return $remainder >= $halfThreshold ? $whole + 1 : $whole;
    }

    private function checkedMultiply(int $left, int $right, string $field): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidArgumentException("The {$field} operands must be non-negative.");
        }

        if ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right)) {
            throw new OverflowException("The {$field} calculation exceeds the supported integer range.");
        }

        return $left * $right;
    }

    private function checkedAdd(int $left, int $right, string $field): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidArgumentException("The {$field} operands must be non-negative.");
        }

        if ($left > PHP_INT_MAX - $right) {
            throw new OverflowException("The {$field} calculation exceeds the supported integer range.");
        }

        return $left + $right;
    }

    private function maxDate(DateTimeImmutable $left, DateTimeImmutable $right): DateTimeImmutable
    {
        return $left >= $right ? $left : $right;
    }

    private function minDate(DateTimeImmutable $left, DateTimeImmutable $right): DateTimeImmutable
    {
        return $left <= $right ? $left : $right;
    }
}

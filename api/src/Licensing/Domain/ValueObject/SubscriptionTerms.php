<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain\ValueObject;

use App\Licensing\Domain\Exception\InvalidSubscription;

/**
 * What the operator agreed with a company: the length of a paid period and its price, the trial and paid time
 * covered, and, when this company's differ from the platform's, its grace days and its unpaid mode.
 */
final readonly class SubscriptionTerms
{
    public const int MAX_GRACE_DAYS = 365;
    private const string PRICE = '/^(0|[1-9]\d{0,9})(\.\d{1,3})?$/';
    private const string CURRENCY = '/^[A-Z]{3}$/';

    public function __construct(
        public BillingPeriod $period,
        public ?\DateTimeImmutable $trialEndsAt,
        public ?\DateTimeImmutable $paidUntil,
        public ?string $price = null,
        public ?string $currency = null,
        public ?int $graceDays = null,
        public ?UnpaidMode $unpaidMode = null,
        /** This company's hold days; null follows the platform's. */
        public ?int $holdDays = null,
    ) {
        if (null === $trialEndsAt && null === $paidUntil) {
            throw new InvalidSubscription('paidThrough', 'A subscription covers a trial, a paid period, or both.', InvalidSubscription::TRIAL_OR_PAID);
        }
        if (null !== $graceDays && ($graceDays < 0 || $graceDays > self::MAX_GRACE_DAYS)) {
            throw new InvalidSubscription('graceDays', \sprintf('A grace period lasts 0 to %d days.', self::MAX_GRACE_DAYS), 'whole_number_between', ['min' => 0, 'max' => self::MAX_GRACE_DAYS]);
        }
        if (null !== $holdDays && ($holdDays < 0 || $holdDays > self::MAX_GRACE_DAYS)) {
            throw new InvalidSubscription('holdDays', \sprintf('A declared payment is held 0 to %d days.', self::MAX_GRACE_DAYS), 'whole_number_between', ['min' => 0, 'max' => self::MAX_GRACE_DAYS]);
        }
        if (null !== $price && 1 !== preg_match(self::PRICE, $price)) {
            throw new InvalidSubscription('price', 'A price is a decimal amount with at most three decimals.', 'invalid_amount', ['decimals' => 3]);
        }
        if (null !== $currency && 1 !== preg_match(self::CURRENCY, $currency)) {
            throw new InvalidSubscription('currency', 'A currency is an ISO 4217 code.', 'invalid_value');
        }
        if ((null === $price) !== (null === $currency)) {
            // Said under the one left empty, which is the one to fill in.
            throw new InvalidSubscription(null === $price ? 'price' : 'currency', 'A price names its currency, and a currency its price.', 'value_required');
        }
    }

    /** The last instant covered: the later of the trial's end and the paid time. */
    public function coveredUntil(): \DateTimeImmutable
    {
        if (null === $this->trialEndsAt) {
            // The constructor refuses terms where both are null.
            return $this->paidUntil ?? throw new InvalidSubscription('paidThrough', 'A subscription covers a trial, a paid period, or both.', InvalidSubscription::TRIAL_OR_PAID);
        }

        return null === $this->paidUntil ? $this->trialEndsAt : max($this->trialEndsAt, $this->paidUntil);
    }
}

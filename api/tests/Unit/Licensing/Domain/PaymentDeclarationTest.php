<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Licensing\Domain;

use App\Licensing\Domain\Access;
use App\Licensing\Domain\BillingPeriod;
use App\Licensing\Domain\DeclarationStatus;
use App\Licensing\Domain\DeclaredPayment;
use App\Licensing\Domain\InvalidPayment;
use App\Licensing\Domain\LicensingDefaults;
use App\Licensing\Domain\PaymentDeclaration;
use App\Licensing\Domain\PaymentMethod;
use App\Licensing\Domain\PeriodUnit;
use App\Licensing\Domain\Stage;
use App\Licensing\Domain\Subscription;
use App\Licensing\Domain\SubscriptionTerms;
use App\Licensing\Domain\UnpaidMode;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PaymentDeclaration::class)]
#[CoversClass(DeclaredPayment::class)]
#[CoversClass(Subscription::class)]
final class PaymentDeclarationTest extends TestCase
{
    private const string NOW = '2026-09-17T12:00:00+00:00';

    public function testADeclaredPaymentWaitsForTheOperatorsDecision(): void
    {
        $declaration = $this->declaration();

        self::assertSame(DeclarationStatus::Declared, $declaration->getStatus());
        self::assertTrue($declaration->isOpen());
        self::assertSame('600.000', $declaration->getAmount());
        self::assertNull($declaration->getDecidedAt());
    }

    public function testConfirmingRecordsWhoDecidedAndWhen(): void
    {
        $declaration = $this->declaration();
        $operator = Uuid::v7();
        $later = new \DateTimeImmutable('2026-09-18T09:00:00+00:00');

        $declaration->confirm($operator, $later, ' reçu en main propre ');

        self::assertSame(DeclarationStatus::Confirmed, $declaration->getStatus());
        self::assertFalse($declaration->isOpen());
        self::assertSame($operator, $declaration->getDecidedBy());
        self::assertSame($later, $declaration->getDecidedAt());
        self::assertSame('reçu en main propre', $declaration->getDecisionNote());
    }

    public function testADecidedDeclarationIsNotDecidedAgain(): void
    {
        $declaration = $this->declaration();
        $declaration->reject(Uuid::v7(), new \DateTimeImmutable(), null);

        $this->expectException(InvalidPayment::class);
        $declaration->confirm(Uuid::v7(), new \DateTimeImmutable(), null);
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refusedPayments(): iterable
    {
        yield 'no amount at all' => [static fn () => new DeclaredPayment('', 'TND', PaymentMethod::Cash, new \DateTimeImmutable(self::NOW), null, null)];
        yield 'an amount that is none' => [static fn () => new DeclaredPayment('60,5', 'TND', PaymentMethod::Cash, new \DateTimeImmutable(self::NOW), null, null)];
        yield 'nothing paid' => [static fn () => new DeclaredPayment('0', 'TND', PaymentMethod::Cash, new \DateTimeImmutable(self::NOW), null, null)];
        yield 'a currency that is no code' => [static fn () => new DeclaredPayment('600.000', 'dinar', PaymentMethod::Cash, new \DateTimeImmutable(self::NOW), null, null)];
    }

    /** @param \Closure(): mixed $build */
    #[DataProvider('refusedPayments')]
    public function testAPaymentThatCannotBeTrueIsRefused(\Closure $build): void
    {
        $this->expectException(InvalidPayment::class);
        $build();
    }

    public function testAnUnpaidCompanyStaysOpenWhileADeclaredPaymentWaits(): void
    {
        $subscription = $this->subscription(paidUntil: '2026-09-01T00:00:00+00:00', holdDays: 7);
        $now = new \DateTimeImmutable(self::NOW);
        $defaults = new LicensingDefaults(3, UnpaidMode::Locked, 7);

        $unpaid = $subscription->standingAt($now, $defaults);
        self::assertSame(Stage::Unpaid, $unpaid->stage);

        $held = $subscription->standingAt($now, $defaults, new \DateTimeImmutable('2026-09-15T08:00:00+00:00'));
        self::assertSame(Stage::Held, $held->stage);
        self::assertSame(Access::Full, $held->access);
        self::assertSame(5, $held->daysLeft);

        // A declaration older than the hold no longer keeps the company open: the operator never decided.
        $stale = $subscription->standingAt($now, $defaults, new \DateTimeImmutable('2026-09-01T08:00:00+00:00'));
        self::assertSame(Stage::Unpaid, $stale->stage);
        self::assertSame(Access::Locked, $stale->access);
    }

    public function testADeclarationChangesNothingWhileTheSubscriptionIsStillCovered(): void
    {
        $subscription = $this->subscription(paidUntil: '2026-12-31T00:00:00+00:00');

        $standing = $subscription->standingAt(new \DateTimeImmutable(self::NOW), new LicensingDefaults(7, UnpaidMode::ReadOnly, 7), new \DateTimeImmutable(self::NOW));

        self::assertSame(Stage::Paid, $standing->stage);
    }

    public function testConfirmingAPaymentCarriesTheCoveredTimeForwardByWholePeriods(): void
    {
        $subscription = $this->subscription(paidUntil: '2026-09-30T23:59:59+00:00');

        $subscription->coverPeriods(2, new \DateTimeImmutable(self::NOW));

        self::assertSame('2026-11-30T23:59:59+00:00', $subscription->getTerms()->paidUntil?->format(\DATE_ATOM));
    }

    public function testCoveringFromTodayWhenTheCoveredTimeIsLongPast(): void
    {
        // Paying one month after three unpaid months buys the month ahead, never a month already gone.
        $subscription = $this->subscription(paidUntil: '2026-06-17T12:00:00+00:00');

        $subscription->coverPeriods(1, new \DateTimeImmutable(self::NOW));

        self::assertSame('2026-10-17T12:00:00+00:00', $subscription->getTerms()->paidUntil?->format(\DATE_ATOM));
    }

    private function declaration(): PaymentDeclaration
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $payment = new DeclaredPayment('600.000', 'TND', PaymentMethod::Cash, new \DateTimeImmutable('2026-09-16T00:00:00+00:00'), 'REC-12', null);

        return new PaymentDeclaration($company, $payment, Uuid::v7(), new \DateTimeImmutable(self::NOW));
    }

    private function subscription(?string $paidUntil = null, ?int $holdDays = null): Subscription
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $terms = new SubscriptionTerms(
            new BillingPeriod(1, PeriodUnit::Month),
            null,
            null === $paidUntil ? null : new \DateTimeImmutable($paidUntil),
            holdDays: $holdDays,
        );

        return new Subscription($company, $terms, new \DateTimeImmutable(self::NOW));
    }
}

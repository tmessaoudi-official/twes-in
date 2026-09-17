<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Licensing\Domain;

use App\Licensing\Domain\Access;
use App\Licensing\Domain\BillingPeriod;
use App\Licensing\Domain\InvalidSubscription;
use App\Licensing\Domain\LicensingDefaults;
use App\Licensing\Domain\PeriodUnit;
use App\Licensing\Domain\Stage;
use App\Licensing\Domain\Standing;
use App\Licensing\Domain\Subscription;
use App\Licensing\Domain\SubscriptionTerms;
use App\Licensing\Domain\UnpaidMode;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Subscription::class)]
#[CoversClass(SubscriptionTerms::class)]
#[CoversClass(BillingPeriod::class)]
#[CoversClass(Standing::class)]
#[CoversClass(Access::class)]
final class SubscriptionStandingTest extends TestCase
{
    private const string NOW = '2026-09-17T12:00:00+00:00';

    public function testATrialGivesFullAccessAndCountsTheDaysLeft(): void
    {
        $standing = $this->subscription(trialEndsAt: '2026-09-27T00:00:00+00:00')->standingAt($this->now(), $this->defaults());

        self::assertSame(Stage::Trial, $standing->stage);
        self::assertSame(Access::Full, $standing->access);
        self::assertSame(10, $standing->daysLeft);
        self::assertSame('2026-09-27T00:00:00+00:00', $standing->coveredUntil->format(\DATE_ATOM));
    }

    public function testAPaidPeriodBeyondTheTrialIsWhatCovers(): void
    {
        $standing = $this->subscription(trialEndsAt: '2026-09-01T00:00:00+00:00', paidUntil: '2026-10-01T00:00:00+00:00')
            ->standingAt($this->now(), $this->defaults());

        self::assertSame(Stage::Paid, $standing->stage);
        self::assertSame(Access::Full, $standing->access);
        self::assertSame(14, $standing->daysLeft);
    }

    public function testOnceCoveredTimeEndsTheGracePeriodKeepsFullAccessAndCountsDownToItsEnd(): void
    {
        $standing = $this->subscription(paidUntil: '2026-09-15T12:00:00+00:00')->standingAt($this->now(), $this->defaults(graceDays: 7));

        self::assertSame(Stage::Grace, $standing->stage);
        self::assertSame(Access::Full, $standing->access);
        self::assertSame(5, $standing->daysLeft);
        self::assertSame('2026-09-22T12:00:00+00:00', $standing->graceEndsAt->format(\DATE_ATOM));
    }

    public function testAfterGraceTheCompanyGetsThePlatformsUnpaidMode(): void
    {
        $subscription = $this->subscription(paidUntil: '2026-09-01T00:00:00+00:00');

        $readOnly = $subscription->standingAt($this->now(), $this->defaults(graceDays: 7, mode: UnpaidMode::ReadOnly));
        self::assertSame(Stage::Unpaid, $readOnly->stage);
        self::assertSame(Access::ReadOnly, $readOnly->access);
        self::assertNull($readOnly->daysLeft);

        self::assertSame(Access::Locked, $subscription->standingAt($this->now(), $this->defaults(graceDays: 7, mode: UnpaidMode::Locked))->access);
    }

    public function testTheCompanysOwnTermsOverrideThePlatformDefaults(): void
    {
        $subscription = $this->subscription(paidUntil: '2026-09-01T00:00:00+00:00', graceDays: 30, unpaidMode: UnpaidMode::Locked);
        self::assertSame(Stage::Grace, $subscription->standingAt($this->now(), $this->defaults(graceDays: 7))->stage);

        $noGrace = $this->subscription(paidUntil: '2026-09-17T11:59:59+00:00', graceDays: 0, unpaidMode: UnpaidMode::Locked);
        self::assertSame(Access::Locked, $noGrace->standingAt($this->now(), $this->defaults(graceDays: 7, mode: UnpaidMode::ReadOnly))->access);
    }

    public function testTheLastCoveredInstantIsStillCovered(): void
    {
        $standing = $this->subscription(paidUntil: self::NOW)->standingAt($this->now(), $this->defaults(graceDays: 0));

        self::assertSame(Access::Full, $standing->access);
        self::assertSame(Stage::Paid, $standing->stage);
        self::assertSame(0, $standing->daysLeft);
    }

    public function testRevisingTheTermsReplacesThemAndMovesUpdatedAt(): void
    {
        $subscription = $this->subscription(trialEndsAt: '2026-09-20T00:00:00+00:00');
        $later = new \DateTimeImmutable('2026-09-18T08:00:00+00:00');

        $subscription->revise(new SubscriptionTerms(new BillingPeriod(6, PeriodUnit::Month), null, new \DateTimeImmutable('2027-03-01T00:00:00+00:00'), '120.000', 'TND', 10, UnpaidMode::Locked), $later);

        self::assertSame(6, $subscription->getTerms()->period->count);
        self::assertNull($subscription->getTerms()->trialEndsAt);
        self::assertSame('120.000', $subscription->getTerms()->price);
        self::assertSame($later, $subscription->getUpdatedAt());
        self::assertSame(Stage::Paid, $subscription->standingAt($later, $this->defaults())->stage);
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function invalidTerms(): iterable
    {
        yield 'nothing covered' => [static fn () => new SubscriptionTerms(new BillingPeriod(1, PeriodUnit::Month), null, null)];
        yield 'a period of zero' => [static fn () => new BillingPeriod(0, PeriodUnit::Month)];
        yield 'an absurd period' => [static fn () => new BillingPeriod(1201, PeriodUnit::Day)];
        yield 'a negative grace' => [static fn () => new SubscriptionTerms(new BillingPeriod(1, PeriodUnit::Year), null, new \DateTimeImmutable(), graceDays: -1)];
        yield 'a grace over a year' => [static fn () => new SubscriptionTerms(new BillingPeriod(1, PeriodUnit::Year), null, new \DateTimeImmutable(), graceDays: 366)];
        yield 'a price that is no amount' => [static fn () => new SubscriptionTerms(new BillingPeriod(1, PeriodUnit::Year), null, new \DateTimeImmutable(), '12,5', 'TND')];
        yield 'a price without its currency' => [static fn () => new SubscriptionTerms(new BillingPeriod(1, PeriodUnit::Year), null, new \DateTimeImmutable(), '12.500')];
        yield 'a currency that is no code' => [static fn () => new SubscriptionTerms(new BillingPeriod(1, PeriodUnit::Year), null, new \DateTimeImmutable(), '12.500', 'dinar')];
    }

    /** @param \Closure(): mixed $build */
    #[DataProvider('invalidTerms')]
    public function testTermsThatCannotBeHeldAreRefused(\Closure $build): void
    {
        $this->expectException(InvalidSubscription::class);
        $build();
    }

    public function testAPeriodMovesADateByItsLengthInItsUnit(): void
    {
        $from = new \DateTimeImmutable('2026-01-31T10:00:00+01:00');

        self::assertSame('2026-02-14T10:00:00+01:00', new BillingPeriod(14, PeriodUnit::Day)->after($from)->format(\DATE_ATOM));
        self::assertSame('2026-07-31T10:00:00+02:00', new BillingPeriod(6, PeriodUnit::Month)->after($from->setTimezone(new \DateTimeZone('Europe/Paris')))->format(\DATE_ATOM));
        self::assertSame('2032-01-31T10:00:00+01:00', new BillingPeriod(3, PeriodUnit::Year)->after($from, 2)->format(\DATE_ATOM));
    }

    public function testFullAccessPermitsEverythingTheRoleGrants(): void
    {
        self::assertTrue(Access::Full->permits('invoice.issue'));
        self::assertTrue(Access::Full->permits('*'));
    }

    public function testReadOnlyPermitsReadingAndPayingOnlyWhateverTheRoleGrants(): void
    {
        self::assertTrue(Access::ReadOnly->permits('invoice.read'));
        self::assertTrue(Access::ReadOnly->permits('subscription.read'));
        self::assertTrue(Access::ReadOnly->permits('subscription.pay'));
        self::assertFalse(Access::ReadOnly->permits('invoice.write'));
        self::assertFalse(Access::ReadOnly->permits('invoice.issue'));
        self::assertFalse(Access::ReadOnly->permits('company.settings'));
        // An owner's role grants "*"; the requested permission is what is judged, never the grant.
        self::assertFalse(Access::ReadOnly->permits('*'));
        self::assertFalse(Access::ReadOnly->permits('invoice.read_all.write'));
    }

    public function testLockedPermitsOnlyWhatLeadsOutOfTheLock(): void
    {
        self::assertTrue(Access::Locked->permits('subscription.read'));
        self::assertTrue(Access::Locked->permits('subscription.pay'));
        self::assertFalse(Access::Locked->permits('invoice.read'));
        self::assertFalse(Access::Locked->permits('company.read'));
    }

    private function subscription(?string $trialEndsAt = null, ?string $paidUntil = null, ?int $graceDays = null, ?UnpaidMode $unpaidMode = null): Subscription
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis', $this->now());
        $terms = new SubscriptionTerms(
            new BillingPeriod(1, PeriodUnit::Month),
            null === $trialEndsAt ? null : new \DateTimeImmutable($trialEndsAt),
            null === $paidUntil ? null : new \DateTimeImmutable($paidUntil),
            graceDays: $graceDays,
            unpaidMode: $unpaidMode,
        );

        return new Subscription($company, $terms, $this->now());
    }

    private function defaults(int $graceDays = 7, UnpaidMode $mode = UnpaidMode::ReadOnly): LicensingDefaults
    {
        return new LicensingDefaults($graceDays, $mode);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}

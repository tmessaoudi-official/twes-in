<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Licensing\Domain\Access;
use App\Licensing\Domain\LicensingDefaults;
use App\Licensing\Domain\PaymentDeclarationRepository;
use App\Licensing\Domain\Standing;
use App\Licensing\Domain\Subscription;
use App\Licensing\Domain\SubscriptionRepository;
use App\Licensing\Domain\UnpaidMode;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Where companies stand in their subscriptions now, and what that lets their members do. A company licensing does not
 * manage has no standing and full access. A payment the company declared and the operator has not decided on keeps it
 * open for the hold days, so the standing is read with that declaration, never without it.
 */
final readonly class CompanyStandings implements CompanyAccess
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private PaymentDeclarationRepository $declarations,
        private ReadSetting $settings,
        private ClockInterface $clock,
    ) {
    }

    public function of(Uuid $companyId): ?Standing
    {
        $subscription = $this->subscriptions->ofCompany($companyId);

        return null === $subscription ? null : $this->standingOf($subscription);
    }

    public function standingOf(Subscription $subscription): Standing
    {
        $open = $this->declarations->openOfCompany($subscription->getCompany()->getId());

        return $subscription->standingAt($this->clock->now(), $this->defaults(), $open?->getDeclaredAt());
    }

    public function accessOf(Uuid $companyId): Access
    {
        $standing = $this->of($companyId);

        return null === $standing ? Access::Full : $standing->access;
    }

    /**
     * @param list<Uuid> $companyIds
     *
     * @return array<string, Standing> by company id, for the companies licensing manages
     */
    public function ofCompanies(array $companyIds): array
    {
        $now = $this->clock->now();
        $defaults = $this->defaults();
        // One query for every open declaration rather than one per company.
        $declaredAt = $this->declarations->openDeclaredAt($companyIds);

        $standings = [];
        foreach ($this->subscriptions->ofCompanies($companyIds) as $id => $subscription) {
            $standings[$id] = $subscription->standingAt($now, $defaults, $declaredAt[$id] ?? null);
        }

        return $standings;
    }

    public function defaults(): LicensingDefaults
    {
        $platform = new SettingContext();
        $graceDays = $this->settings->value($platform, LicensingSettings::GRACE_DAYS);
        $mode = $this->settings->value($platform, LicensingSettings::UNPAID_MODE);
        $holdDays = $this->settings->value($platform, LicensingSettings::HOLD_DAYS);

        // The catalogue refuses any other value when it is stored; reaching one here is a broken invariant, not a default.
        if (!\is_int($graceDays) || !\is_string($mode) || !\is_int($holdDays)) {
            throw new \LogicException('The licensing settings resolved to values their declarations refuse.');
        }

        return new LicensingDefaults($graceDays, UnpaidMode::from($mode), $holdDays);
    }
}

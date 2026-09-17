<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Licensing\Domain\Access;
use App\Licensing\Domain\LicensingDefaults;
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
 * manage has no standing and full access.
 */
final readonly class CompanyStandings implements CompanyAccess
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private ReadSetting $settings,
        private ClockInterface $clock,
    ) {
    }

    public function of(Uuid $companyId): ?Standing
    {
        return $this->subscriptions->ofCompany($companyId)?->standingAt($this->clock->now(), $this->defaults());
    }

    public function standingOf(Subscription $subscription): Standing
    {
        return $subscription->standingAt($this->clock->now(), $this->defaults());
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

        return array_map(
            static fn (Subscription $subscription): Standing => $subscription->standingAt($now, $defaults),
            $this->subscriptions->ofCompanies($companyIds),
        );
    }

    public function defaults(): LicensingDefaults
    {
        $platform = new SettingContext();
        $graceDays = $this->settings->value($platform, LicensingSettings::GRACE_DAYS);
        $mode = $this->settings->value($platform, LicensingSettings::UNPAID_MODE);

        // The catalogue refuses any other value when it is stored; reaching one here is a broken invariant, not a default.
        if (!\is_int($graceDays) || !\is_string($mode)) {
            throw new \LogicException('The licensing settings resolved to values their declarations refuse.');
        }

        return new LicensingDefaults($graceDays, UnpaidMode::from($mode));
    }
}

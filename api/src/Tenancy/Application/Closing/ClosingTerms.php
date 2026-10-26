<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Closing;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Model\Company;

/**
 * Until when a closing may be taken back, the days the platform sets counted from the moment it was closed: one reading
 * for the use case that refuses a late reopening and for the session, whose page offers « Rouvrir » until then.
 */
final readonly class ClosingTerms
{
    public function __construct(private ReadSetting $settings)
    {
    }

    public function reopenUntil(\DateTimeImmutable $closedDownAt): \DateTimeImmutable
    {
        return $closedDownAt->modify(\sprintf('+%d days', $this->settings->integer(new SettingContext(), CompanyClosingSettings::REOPEN_DAYS)));
    }

    /** A closed company's terms, null while it works. */
    public function of(Company $company): ?ClosedDown
    {
        $closedDownAt = $company->getClosedDownAt();
        $retainUntil = $company->getRetainUntil();

        return null === $closedDownAt || null === $retainUntil ? null : new ClosedDown($retainUntil, $this->reopenUntil($closedDownAt));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;

/**
 * A company's « Journal d'activité » (docs/SPEC.md § 7, 2026-09-26 23:04): its entries within what the company keeps,
 * whatever days the reader asks for — an older entry is not shown, nor written in a file.
 */
final readonly class ReadActivity
{
    public function __construct(private ActivityJournal $journal, private ReadSetting $settings, private ClockInterface $clock)
    {
    }

    /** @return Page<ActivityEntry> */
    public function search(Company $company, ActivitySearch $search, PageRequest $page): Page
    {
        return $this->journal->page($company->getId(), $search, $this->keptSince($company), $page);
    }

    /** The first moment the company still keeps. */
    public function keptSince(Company $company): \DateTimeImmutable
    {
        $months = $this->settings->value(new SettingContext($company), ActivitySettings::RETENTION_MONTHS);
        \is_int($months) || throw new \LogicException(ActivitySettings::RETENTION_MONTHS.' is declared as a whole number.');

        return $this->clock->now()->modify(\sprintf('-%d months', $months));
    }
}

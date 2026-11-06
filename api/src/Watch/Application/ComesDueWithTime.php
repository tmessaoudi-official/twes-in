<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Application;

use App\Tenancy\Domain\Model\Company;

/**
 * A part of « À surveiller » whose rows come or go with the clock alone within a day (a draft waiting past its hours, an
 * invitation lapsing), so no write announces it. It says the next such moment; the summary carries the earliest, and a
 * screen reads it again then. What a day turning changes needs none: the summary already reads again at the next day.
 */
interface ComesDueWithTime
{
    /** The next moment after `$now` at which one of its counts changes with time alone in the company; null for none. */
    public function nextChangeAt(Company $company, \DateTimeImmutable $now): ?\DateTimeImmutable;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Installation\Application;

/**
 * The sign-ins refused since a moment, as the journal holds them: what a shop's installation script reads each quarter
 * hour (scripts/onprem/twes), so a burst of guesses or of locked accounts reaches the developer once the shop is
 * reachable from outside.
 */
interface SignInAttempts
{
    public function since(\DateTimeImmutable $since): SignInCounts;
}

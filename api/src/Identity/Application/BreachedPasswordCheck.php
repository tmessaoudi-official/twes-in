<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Whether a password is known to have been breached. Three answers, not two: the third is what makes the
 * ruling of 2026-09-09 expressible, which is that an unreachable service accepts the password and the skip
 * is audited. A constraint that silently skips could not say which happened.
 */
interface BreachedPasswordCheck
{
    /** @return bool|null true when the password appears in a breach corpus, false when it does not, null when the check could not be made */
    public function isBreached(string $plainPassword): ?bool;
}

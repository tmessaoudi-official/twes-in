<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

/** The wrong answer that spent this sign-in's budget: the session ends, the account stays open (the C-F3 ruling). */
final class StepUpExhausted extends \DomainException
{
}

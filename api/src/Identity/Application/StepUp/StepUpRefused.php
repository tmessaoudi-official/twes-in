<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

/** The proof given again did not hold: a wrong password, or a passkey that is not this account's or answers another challenge. */
final class StepUpRefused extends \DomainException
{
}

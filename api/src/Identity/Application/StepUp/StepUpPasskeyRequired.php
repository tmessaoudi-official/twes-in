<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

/**
 * A password was sent where only the account's passkey proves it: a second factor is required and the passkey is the
 * account's only one. Refused before the password is read, so it spends none of the sign-in's answers.
 */
final class StepUpPasskeyRequired extends \DomainException
{
}

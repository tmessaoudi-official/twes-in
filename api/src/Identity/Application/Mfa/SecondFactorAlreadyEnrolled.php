<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** An authenticator is already in force, so a new enrolment would switch it off: it has to be removed first. */
final class SecondFactorAlreadyEnrolled extends \DomainException
{
}

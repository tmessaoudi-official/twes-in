<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/**
 * The stored authenticator secret cannot be read with the current key: APP_MFA_KEY was rotated, which means re-enrolment.
 *
 * Refused, like a wrong code, but not a wrong guess: no code could have been accepted, so it never counts toward the
 * account lock (docs/SPEC.md § 8 row 26). Deliberately a sibling of SecondFactorRefused and not a subclass of it: every
 * `catch (SecondFactorRefused)` counts, and a subclass would be caught and counted by any site that forgot this case.
 */
final class SecondFactorUnreadable extends \DomainException
{
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Seed;

/** No operator password is built in: creating the operator needs one, and nothing is written without it. */
final class OperatorPasswordRequired extends \DomainException
{
}

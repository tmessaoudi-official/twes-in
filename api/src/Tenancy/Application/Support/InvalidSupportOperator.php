<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Support;

/** The operator named is not one a grant would let in: not an operator, without a second factor, a member, or no one. */
final class InvalidSupportOperator extends \DomainException
{
}

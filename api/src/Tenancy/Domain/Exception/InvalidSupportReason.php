<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain\Exception;

/** What the owner says is wrong is not text, or is longer than support access keeps. */
final class InvalidSupportReason extends \DomainException
{
}

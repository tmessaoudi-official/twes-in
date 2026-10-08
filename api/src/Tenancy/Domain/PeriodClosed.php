<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/** A document dated on or before the last closed day of the books. Its message starts with « closedPeriod: », which the screens read. */
final class PeriodClosed extends \DomainException
{
}

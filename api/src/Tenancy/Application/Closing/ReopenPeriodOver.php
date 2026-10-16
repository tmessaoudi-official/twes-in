<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Closing;

/** The days the platform allows to take a closing back have passed: the company stays closed until it is removed. */
final class ReopenPeriodOver extends \DomainException
{
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Closing;

/** Reopening a company that works: there is no closing to take back. */
final class NotClosedDown extends \DomainException
{
}

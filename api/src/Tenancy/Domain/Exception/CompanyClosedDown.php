<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain\Exception;

/** A closed company asked to change its status by anything but its owner's reopening: approving, suspending, an invitation. */
final class CompanyClosedDown extends \DomainException
{
}

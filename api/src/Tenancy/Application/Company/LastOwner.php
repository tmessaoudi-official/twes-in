<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** A company always keeps at least one owner, otherwise nobody could ever administer it again. */
final class LastOwner extends \DomainException
{
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** A company is pending, active or suspended; asking for any other status is a mistake, not an empty list. */
final class UnknownCompanyStatus extends \DomainException
{
}

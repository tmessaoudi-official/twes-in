<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** No company with that identifier. Answered as 404, never as "you may not", so identifiers cannot be probed. */
final class CompanyNotFound extends \DomainException
{
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** One company an account belongs to, and the role it holds there: what the platform's accounts list shows beside a person. */
final readonly class AccountCompany
{
    public function __construct(public string $id, public string $name, public string $role)
    {
    }
}

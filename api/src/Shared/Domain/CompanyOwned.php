<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * A row of one company's own business data, held in a non-null company_id column (docs/SPEC.md § 3 Tenancy). Once a
 * request acts for a company, the company filter keeps every other company's rows of such an entity out of reach.
 * What describes people across companies (memberships, invitations), the platform's shared rows (built-in roles,
 * platform settings) and an account's own history do not carry it; tests/Architecture/CompanyColumnTest names them.
 */
interface CompanyOwned
{
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Whether an expense category a vendor names exists in its company and is active. A port, so vendors name no class of
 * the expenses module, which depends on them and not the reverse.
 */
interface ExpenseCategoryDirectory
{
    public function isActiveInCompany(Uuid $categoryId, Uuid $companyId): bool;

    /**
     * The id of the company's active category of this name, for a file that names one: a spreadsheet carries the name
     * a person reads, never an id (docs/SPEC.md § 8 row 59).
     */
    public function idOfActiveNameInCompany(string $name, Uuid $companyId): ?Uuid;
}

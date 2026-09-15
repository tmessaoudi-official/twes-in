<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use Symfony\Component\Uid\Uuid;

final class InMemoryExpenseCategoryDirectory implements ExpenseCategoryDirectory
{
    /** @var list<string> "companyId categoryId" of every active category */
    public array $active = [];

    public function isActiveInCompany(Uuid $categoryId, Uuid $companyId): bool
    {
        return \in_array($companyId->toRfc4122().' '.$categoryId->toRfc4122(), $this->active, true);
    }
}

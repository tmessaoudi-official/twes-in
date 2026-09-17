<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface VendorRepository
{
    /** @return list<Vendor> one company's vendors, by number */
    public function ofCompany(Uuid $companyId): array;

    /** @return Page<Vendor> one page of the company's vendors that the search finds, in its order */
    public function search(Uuid $companyId, VendorSearch $search, PageRequest $page): Page;

    /** Null for a vendor that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Vendor;

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Vendor;

    public function save(Vendor $vendor): void;
}

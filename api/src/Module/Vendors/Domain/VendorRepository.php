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

    /**
     * The few ACTIVE vendors a person means while typing, by number. It matches on the same SEARCH_TEXT expression
     * the list does, so the same index serves it, and it counts nothing: a picker asks again on every keystroke, and
     * a total over a long book is the cost that buys nothing here (docs/SPEC.md § 7, 2026-09-17).
     *
     * @return list<Vendor>
     */
    public function pick(Uuid $companyId, string $words, int $limit): array;

    /**
     * The vendors these ids name, retired ones included: an expense recorded last year still names who it was paid
     * to, and its form must be able to say so.
     *
     * @param list<Uuid> $ids
     *
     * @return list<Vendor>
     */
    public function ofIdsInCompany(array $ids, Uuid $companyId): array;

    /** Null for a vendor that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Vendor;

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Vendor;

    public function save(Vendor $vendor): void;
}

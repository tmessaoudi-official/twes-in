<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorRepository;
use App\Module\Vendors\Domain\VendorSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

final class InMemoryVendors implements VendorRepository
{
    /** @var list<Vendor> */
    public array $vendors = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->vendors, static fn (Vendor $v) => $v->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Vendor $a, Vendor $b) => $a->getNumber() <=> $b->getNumber());

        return $mine;
    }

    /** Narrows as the database does; sorts by number only, which is all the unit tests ask for. */
    public function search(Uuid $companyId, VendorSearch $search, PageRequest $page): Page
    {
        $found = array_values(array_filter($this->ofCompany($companyId), static function (Vendor $v) use ($search): bool {
            $profile = $v->getProfile();
            $address = $profile->address;

            return InMemorySearch::finds($search->text, $v->getNumber(), [$profile->name, $profile->legalName, $profile->email, $address->line1, $address->postalCode, $address->city, ...array_values($profile->identifiers)])
                && (null === $search->active || $v->isActive() === $search->active);
        }));

        return new Page(\array_slice($found, $page->offset(), $page->size), \count($found), $page);
    }

    public function pick(Uuid $companyId, string $words, int $limit): array
    {
        $found = array_values(array_filter($this->ofCompany($companyId), static function (Vendor $v) use ($words): bool {
            $profile = $v->getProfile();

            return $v->isActive() && InMemorySearch::finds($words, $v->getNumber(), [$profile->name, $profile->legalName, $profile->email]);
        }));

        return \array_slice($found, 0, $limit);
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        return array_values(array_filter(
            $this->ofCompany($companyId),
            static fn (Vendor $v): bool => \in_array($v->getId()->toRfc4122(), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), true),
        ));
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Vendor
    {
        foreach ($this->ofCompany($companyId) as $vendor) {
            if ($vendor->getId()->equals($id)) {
                return $vendor;
            }
        }

        return null;
    }

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Vendor
    {
        foreach ($this->ofCompany($companyId) as $vendor) {
            if ($vendor->getNumber() === $number) {
                return $vendor;
            }
        }

        return null;
    }

    public function save(Vendor $vendor): void
    {
        if (!\in_array($vendor, $this->vendors, true)) {
            $this->vendors[] = $vendor;
        }
    }
}

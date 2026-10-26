<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Watch;

use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Domain\ValueObject\VendorCheckReason;
use App\Module\Vendors\Domain\ValueObject\VendorSearch;
use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;

/**
 * « À vérifier » on the vendors still bought from: marked by a person, or with a name a file could not vouch for. The
 * same narrowing as the list's `toCheck`, so the count and the list agree.
 */
final readonly class VendorCheckWatch implements DeclaresWatch
{
    public const string TO_CHECK = 'vendor.to_check';

    public function __construct(private VendorRepository $vendors)
    {
    }

    public function key(): string
    {
        return 'vendor_check';
    }

    public function module(): string
    {
        return VendorsModule::KEY;
    }

    public function permission(): string
    {
        return VendorPermission::READ;
    }

    public function kinds(): array
    {
        return [self::TO_CHECK];
    }

    public function changedBy(): array
    {
        return ['vendor'];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        return $this->read($kind, $company, new PageRequest(1, 1))->total;
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $page = $this->read($kind, $company, $request);

        return new Page(array_map(static function (Vendor $vendor): WatchItem {
            $mark = $vendor->getCheckMark();

            return new WatchItem(self::TO_CHECK, $vendor->getId()->toRfc4122(), [
                'vendor' => $vendor->getProfile()->name,
                'number' => $vendor->getNumber(),
                'reasons' => implode(',', array_map(static fn (VendorCheckReason $reason): string => $reason->value, $vendor->checkReasons())),
                'marked' => $mark->marked ? 1 : 0,
                'note' => $mark->note ?? '',
            ]);
        }, $page->items), $page->total, $request);
    }

    /** @return Page<Vendor> */
    private function read(string $kind, Company $company, PageRequest $request): Page
    {
        if (self::TO_CHECK !== $kind) {
            throw new \LogicException(\sprintf('The vendor check watch has no %s.', $kind));
        }

        return $this->vendors->search($company->getId(), new VendorSearch(active: true, toCheck: true), $request);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Doctrine;

use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineVendorRepository implements VendorRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<Vendor> $vendors */
        $vendors = $this->entityManager->getRepository(Vendor::class)->findBy(['company' => $companyId], ['number' => 'ASC']);

        return $vendors;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Vendor
    {
        $vendor = $this->entityManager->find(Vendor::class, $id);

        return null !== $vendor && $vendor->getCompany()->getId()->equals($companyId) ? $vendor : null;
    }

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Vendor
    {
        return $this->entityManager->getRepository(Vendor::class)->findOneBy(['company' => $companyId, 'number' => $number]);
    }

    public function save(Vendor $vendor): void
    {
        $this->entityManager->persist($vendor);
        $this->entityManager->flush();
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Doctrine;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCustomerTaxRegimeRepository implements CustomerTaxRegimeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofPreset(string $fiscalPreset): array
    {
        return $this->entityManager->getRepository(CustomerTaxRegime::class)->findBy(['fiscalPreset' => $fiscalPreset], ['sortOrder' => 'ASC', 'code' => 'ASC']);
    }

    public function ofPresetAndCode(string $fiscalPreset, string $code): ?CustomerTaxRegime
    {
        return $this->entityManager->getRepository(CustomerTaxRegime::class)->findOneBy(['fiscalPreset' => $fiscalPreset, 'code' => $code]);
    }

    public function save(CustomerTaxRegime $regime): void
    {
        $this->entityManager->persist($regime);
        $this->entityManager->flush();
    }
}

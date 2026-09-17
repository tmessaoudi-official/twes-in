<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\Doctrine;

use App\Licensing\Domain\DeclarationStatus;
use App\Licensing\Domain\PaymentAlreadyDeclared;
use App\Licensing\Domain\PaymentDeclaration;
use App\Licensing\Domain\PaymentDeclarationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePaymentDeclarationRepository implements PaymentDeclarationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofId(Uuid $id): ?PaymentDeclaration
    {
        return $this->entityManager->find(PaymentDeclaration::class, $id);
    }

    public function openOfCompany(Uuid $companyId): ?PaymentDeclaration
    {
        return $this->entityManager->getRepository(PaymentDeclaration::class)
            ->findOneBy(['company' => $companyId, 'status' => DeclarationStatus::Declared->value], ['declaredAt' => 'DESC']);
    }

    public function ofCompany(Uuid $companyId, int $limit): array
    {
        /** @var list<PaymentDeclaration> $declarations */
        $declarations = $this->entityManager->getRepository(PaymentDeclaration::class)
            ->findBy(['company' => $companyId], ['declaredAt' => 'DESC'], $limit);

        return $declarations;
    }

    public function waiting(): array
    {
        /** @var list<PaymentDeclaration> $declarations */
        $declarations = $this->entityManager->createQueryBuilder()
            ->select('d', 'c')->from(PaymentDeclaration::class, 'd')->join('d.company', 'c')
            ->where('d.status = :status')->setParameter('status', DeclarationStatus::Declared->value)
            ->orderBy('d.declaredAt', 'ASC')
            ->getQuery()->getResult();

        return $declarations;
    }

    public function openDeclaredAt(array $companyIds): array
    {
        if ([] === $companyIds) {
            return [];
        }
        /** @var list<array{company: string, declaredAt: \DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(d.company) AS company', 'd.declaredAt AS declaredAt')
            ->from(PaymentDeclaration::class, 'd')
            ->where('d.status = :status')->setParameter('status', DeclarationStatus::Declared->value)
            ->andWhere('d.company IN (:companies)')
            ->setParameter('companies', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $companyIds), ArrayParameterType::STRING)
            ->getQuery()->getResult();

        $byCompany = [];
        foreach ($rows as $row) {
            $byCompany[$row['company']] = $row['declaredAt'];
        }

        return $byCompany;
    }

    /**
     * One declaration waits per company, and the partial unique index is what really holds that: two requests can
     * both read none open before either writes. The database's refusal is the same answer the use case gives.
     *
     * @throws PaymentAlreadyDeclared
     */
    public function save(PaymentDeclaration $declaration): void
    {
        $this->entityManager->persist($declaration);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $clash) {
            throw new PaymentAlreadyDeclared('A payment is already waiting for a decision.', previous: $clash);
        }
    }
}

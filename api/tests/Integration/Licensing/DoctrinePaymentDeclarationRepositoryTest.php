<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Licensing;

use App\Licensing\Domain\DeclaredPayment;
use App\Licensing\Domain\PaymentAlreadyDeclared;
use App\Licensing\Domain\PaymentDeclaration;
use App\Licensing\Domain\PaymentDeclarationRepository;
use App\Licensing\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * One declaration waits per company, and the use case's own check cannot hold that on its own: two requests can both
 * read none open before either writes. The partial unique index is what really holds it, and the adapter answers its
 * refusal the way the use case does, so a race reads as "one already waits" rather than as a server error.
 */
final class DoctrinePaymentDeclarationRepositoryTest extends KernelTestCase
{
    public function testTheDatabaseRefusesASecondOpenDeclarationForOneCompany(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $declarations = static::getContainer()->get(PaymentDeclarationRepository::class);

        $company = new Company('Race', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $entityManager->persist($company);
        $entityManager->flush();

        $declarations->save($this->declaration($company));
        // The second write never passes through the use case's check: this is the index alone.
        $this->expectException(PaymentAlreadyDeclared::class);
        $declarations->save($this->declaration($company));
    }

    private function declaration(Company $company): PaymentDeclaration
    {
        return new PaymentDeclaration(
            $company,
            new DeclaredPayment('600.000', 'TND', PaymentMethod::Cash, new \DateTimeImmutable('yesterday')),
            Uuid::v7(),
            new \DateTimeImmutable(),
        );
    }
}

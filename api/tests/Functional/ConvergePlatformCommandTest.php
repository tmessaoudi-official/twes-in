<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every start brings an existing database up to what this release expects of it, without the seed's operator and
 * first company: a built-in role gains the permissions a new module brought, and each company the numbering series of
 * a kind of document its preset now numbers.
 */
final class ConvergePlatformCommandTest extends ApiTestCase
{
    public function testAnEarlierReleasesRolesAndSeriesAreBroughtUpAndASecondRunWritesNothing(): void
    {
        $company = $this->createCompany('Ancienne');
        $this->converge();
        $em = $this->em();
        $clerk = $em->getRepository(Role::class)->findOneBy(['name' => Role::CLERK, 'company' => null]);
        self::assertNotNull($clerk);
        $clerk->redefinePermissions(['company.read', 'invoice.read']);
        $em->flush();
        $connection = $em->getConnection();
        $connection->executeStatement("DELETE FROM numbering_series WHERE company_id = ? AND document_type = 'quote'", [$company->getId()->toRfc4122()]);

        $tester = $this->converge();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('role clerk updated', $display);
        self::assertStringContainsString('numbering series of Ancienne', $display);
        self::assertEquals(1, $connection->fetchOne("SELECT count(*) FROM numbering_series WHERE company_id = ? AND document_type = 'quote'", [$company->getId()->toRfc4122()]));
        $em->clear();
        self::assertContains('quote.write', $em->getRepository(Role::class)->findOneBy(['name' => Role::CLERK, 'company' => null])?->getPermissions() ?? []);

        $again = $this->converge();
        self::assertSame(0, $again->getStatusCode());
        self::assertSame('', trim($again->getDisplay()));
        // Neither an operator nor a first company: those are the seed's, for a development or CI database.
        self::assertCount(0, $em->getRepository(User::class)->findAll());
        self::assertCount(1, $em->getRepository(Company::class)->findAll());
    }

    private function converge(): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:platform:converge'));
        $tester->execute([]);

        return $tester;
    }
}

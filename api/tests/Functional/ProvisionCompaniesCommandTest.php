<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every start brings each company up to its preset, so a release that numbers a new kind of document gives every
 * existing company that series without anyone running the seed.
 */
final class ProvisionCompaniesCommandTest extends ApiTestCase
{
    public function testACompanyMissingADocumentTypeGainsItsSeriesAndASecondRunWritesNothing(): void
    {
        $company = $this->createCompany('Ancienne');
        $this->provision();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("DELETE FROM numbering_series WHERE company_id = ? AND document_type = 'quote'", [$company->getId()->toRfc4122()]);

        $tester = $this->provision();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('numbering series of Ancienne', $tester->getDisplay());
        self::assertEquals(1, $connection->fetchOne("SELECT count(*) FROM numbering_series WHERE company_id = ? AND document_type = 'quote'", [$company->getId()->toRfc4122()]));

        $again = $this->provision();
        self::assertSame(0, $again->getStatusCode());
        self::assertSame('', trim($again->getDisplay()));
    }

    private function provision(): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:companies:provision'));
        $tester->execute([]);

        return $tester;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exporting customer or document data is on the short list of actions that ask who is at the screen again (docs/SPEC.md
 * § 7, 2026-09-21 19:05; audit H-b2): the API refuses the file until the password or a passkey was confirmed in the last
 * few minutes, and records every file it hands out.
 */
final class ExportStepUpTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->createUser('sales@twes.local', self::PASSWORD, $this->company, ['customer.read'], 'member');
        $this->login('sales@twes.local', self::PASSWORD);
    }

    public function testAFileIsRefusedUntilThePersonProvesWhoTheyAreAgain(): void
    {
        $this->client->request('GET', $this->path('customers.csv'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));
        self::assertSame(0, $this->exports());
    }

    public function testAWrongPasswordOpensNothing(): void
    {
        $this->postJson('/api/auth/step-up', ['password' => 'not-the-password']);
        $this->client->request('GET', $this->path('customers.csv'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOnceConfirmedTheFilesOfTheNextMinutesAreHandedOutAndEachIsRecorded(): void
    {
        $this->stepUp(self::PASSWORD);

        $this->client->request('GET', $this->path('customers.csv'));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->path('customers.xlsx'));
        self::assertResponseIsSuccessful();

        self::assertSame(2, $this->exports());
        $recorded = $this->em()->getConnection()->fetchAssociative(
            "SELECT entity_type, changes FROM audit_log WHERE action = 'export.downloaded' ORDER BY at LIMIT 1",
        );
        self::assertIsArray($recorded);
        self::assertSame('export', $recorded['entity_type'], 'no open screen reloads on a file handed out');
        self::assertIsString($recorded['changes']);
        self::assertStringContainsString('customers', $recorded['changes']);
    }

    private function exports(): int
    {
        $count = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'export.downloaded'");
        self::assertIsInt($count);

        return $count;
    }

    private function path(string $file): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/exports/'.$file;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\RunDataChecks;
use App\Demo\Application\BuildSandbox;
use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Tenancy\Application\Seed\SeedPlatform;
use Symfony\Component\Uid\Uuid;

/**
 * A demo sandbox is a made-up company a visitor tries the product in, and its owner a throwaway account: neither is the
 * platform operators' concern, so their lists leave them out, totals included, and a data check finding something in
 * one tells nobody. The same company once it is no sandbox is listed and checked as any other.
 */
final class DemoHiddenFromOperatorsTest extends ApiTestCase
{
    use MakesSandboxes;

    public function testTheOperatorsListsLeaveTheSandboxesAndTheirOwnersOut(): void
    {
        $sandbox = $this->readySandbox('TN');
        $this->createCompany('Bac à sable réel');
        $this->em()->flush();
        // A visitor is in it: one in use is left out as surely as one waiting in the pool.
        $this->em()->getConnection()->executeStatement("UPDATE demo_sandbox SET state = 'claimed', claimed_at = now() WHERE id = ?", [$sandbox->getId()->toRfc4122()]);
        $this->signedInAsOperator();

        $this->getJson('/api/platform/companies?q='.rawurlencode('Bac à sable'));
        self::assertResponseIsSuccessful();
        self::assertSame(['Bac à sable réel'], array_column($this->jsonList(), 'name'));
        self::assertSame(1, $this->jsonPage()['totalItems'], 'the total counts no sandbox either');

        $owner = $this->row('SELECT email FROM "user" WHERE id = ?', [$sandbox->getOwnerId()?->toRfc4122()])['email'];
        self::assertIsString($owner);
        $this->getJson('/api/platform/accounts?q='.rawurlencode($owner));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());

        $this->em()->getConnection()->executeStatement('DELETE FROM demo_sandbox WHERE id = ?', [$sandbox->getId()->toRfc4122()]);
        $this->getJson('/api/platform/companies?q='.rawurlencode('Bac à sable'));
        self::assertCount(2, $this->jsonList(), 'once no sandbox, the company is listed as any other');
        $this->getJson('/api/platform/accounts?q='.rawurlencode($owner));
        self::assertSame([$owner], array_column($this->jsonList(), 'email'));
    }

    public function testWhatADataCheckFindsInASandboxTellsNobody(): void
    {
        static::getContainer()->get(SeedPlatform::class)->converge();
        $sandbox = new DemoSandbox(Uuid::v7(), 'TN', new \DateTimeImmutable());
        static::getContainer()->get(DemoSandboxRepository::class)->save($sandbox);
        static::getContainer()->get(BuildSandbox::class)->handle($sandbox->getId());
        $company = $this->row('SELECT company_id FROM demo_sandbox WHERE id = ?', [$sandbox->getId()->toRfc4122()])['company_id'];
        self::assertIsString($company);
        $this->em()->getConnection()->executeStatement(
            "UPDATE invoice SET total_gross = total_gross + 1 WHERE id = (SELECT id FROM invoice WHERE company_id = ? AND status NOT IN ('draft', 'cancelled') LIMIT 1)",
            [$company],
        );

        self::assertSame([], $this->findingsOf($company));

        $this->em()->getConnection()->executeStatement('DELETE FROM demo_sandbox WHERE id = ?', [$sandbox->getId()->toRfc4122()]);
        self::assertNotSame([], $this->findingsOf($company), 'the same company once no sandbox is checked as any other');
    }

    private function signedInAsOperator(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** @return list<DataFinding> */
    private function findingsOf(string $company): array
    {
        return array_values(array_filter(
            static::getContainer()->get(RunDataChecks::class)->findings(),
            static fn (DataFinding $finding): bool => $finding->companyId?->toRfc4122() === $company,
        ));
    }
}

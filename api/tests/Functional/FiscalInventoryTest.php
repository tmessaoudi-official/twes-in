<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Inventory\FiscalInventoryCatalogue;
use App\Fiscal\Application\Inventory\FiscalRecords;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * `app:fiscal:inventory`, what a wipe reads before it removes anything (scripts/onprem/twes, make reset): per company,
 * how many issued documents and records of their ledger it holds, and what else the law or the shop keeps. It exits 3
 * when anything fiscal is there, 0 when nothing is, so a script refuses on the code alone.
 */
final class FiscalInventoryTest extends ApiTestCase
{
    private const string CARTHAGE = 'Carthage Conseil';

    public function testACompanyThatIssuedNothingHoldsNoFiscalRecord(): void
    {
        $company = $this->createCompany('Vide');

        $tester = $this->inventory(['--json' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $inventory = $this->decoded($tester);
        self::assertFalse($inventory['fiscal']);
        $listed = $this->companyIn($inventory, $company->getId()->toRfc4122());
        self::assertSame('Vide', $listed['name']);
        self::assertFalse($listed['fiscal']);
        foreach ($listed['records'] as $record) {
            self::assertIsArray($record);
            self::assertSame(0, $record['count'], (string) json_encode($record));
        }
    }

    public function testTheDemoDatasetIsCountedMemberByMemberAndExitsThree(): void
    {
        $this->createUser('operator@twes.local', 'operator-secret', operator: true);
        $empty = $this->createCompany('Vide');
        $load = $this->console(['command' => 'doctrine:fixtures:load', '--append' => true]);
        self::assertSame(0, $load->getStatusCode(), $load->getDisplay());
        $id = $this->text($this->em()->getConnection()->fetchOne('SELECT id FROM company WHERE name = ?', [self::CARTHAGE]));

        $tester = $this->inventory(['--json' => true]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        $inventory = $this->decoded($tester);
        self::assertTrue($inventory['fiscal']);
        $carthage = $this->companyIn($inventory, $id);
        self::assertTrue($carthage['fiscal']);
        $records = [];
        foreach ($carthage['records'] as $record) {
            self::assertIsArray($record);
            $records[$this->text($record['code'])] = $record;
        }
        $codes = array_map(static fn (FiscalRecords $records): string => $records->code, static::getContainer()->get(FiscalInventoryCatalogue::class)->companyRecords());
        self::assertSame($codes, array_keys($records), 'every member of the catalogue is listed for each company, in its order');

        // Counted here from what the documents ARE, not from the members' conditions: issued is every state past a draft.
        $expected = [
            'invoices.issued' => "SELECT COUNT(*) FROM invoice WHERE company_id = ? AND status IN ('issued', 'partially_paid', 'paid', 'cancelled')",
            'invoices.payments' => 'SELECT COUNT(*) FROM payment p JOIN invoice i ON i.id = p.invoice_id WHERE i.company_id = ?',
            'delivery_notes.numbered' => "SELECT COUNT(*) FROM delivery_note WHERE company_id = ? AND status IN ('validated', 'delivered', 'invoiced', 'cancelled') AND number IS NOT NULL",
            'expenses.recorded' => "SELECT COUNT(*) FROM expense WHERE company_id = ? AND status IN ('recorded', 'paid')",
            'purchases.goods_receipts' => 'SELECT COUNT(*) FROM goods_receipt WHERE company_id = ?',
            'inventory.stock_movements' => 'SELECT COUNT(*) FROM stock_movement WHERE company_id = ?',
            'audit.journal' => 'SELECT COUNT(*) FROM audit_log WHERE company_id = ?',
        ];
        foreach ($expected as $code => $sql) {
            $count = $this->int($this->em()->getConnection()->fetchOne($sql, [$id]));
            self::assertGreaterThan(0, $count, "the demo holds $code");
            self::assertSame($count, $records[$code]['count'], $code);
        }
        self::assertSame('fiscal', $records['invoices.issued']['kind']);
        self::assertSame('kept', $records['audit.journal']['kind']);

        // The first and last numbers are those of the first and last documents issued, and the dates they span.
        $first = $this->em()->getConnection()->fetchAssociative('SELECT number, issue_date FROM invoice WHERE company_id = ? AND number IS NOT NULL ORDER BY issue_date, number, id LIMIT 1', [$id]);
        $last = $this->em()->getConnection()->fetchAssociative('SELECT number, issue_date FROM invoice WHERE company_id = ? AND number IS NOT NULL ORDER BY issue_date DESC, number DESC, id DESC LIMIT 1', [$id]);
        self::assertIsArray($first);
        self::assertIsArray($last);
        self::assertSame([$first['number'], $last['number'], $first['issue_date'], $last['issue_date']], [$records['invoices.issued']['firstNumber'], $records['invoices.issued']['lastNumber'], $records['invoices.issued']['from'], $records['invoices.issued']['to']]);

        // One company asked for alone answers for itself: the empty one exits 0 though the demo's are fiscal.
        $alone = $this->inventory(['--json' => true, '--company' => $empty->getId()->toRfc4122()]);
        self::assertSame(0, $alone->getStatusCode(), $alone->getDisplay());
        self::assertCount(1, $this->decoded($alone)['companies']);
        self::assertSame(3, $this->inventory(['--company' => $id])->getStatusCode());
    }

    public function testTheTableSaysEachCompanyAndWhatItHolds(): void
    {
        $this->createCompany('Vide');

        $tester = $this->inventory([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Vide', $tester->getDisplay());
        self::assertStringContainsString('invoices.issued', $tester->getDisplay());
        self::assertStringContainsString('No fiscal record', $tester->getDisplay());
    }

    public function testACompanyNotFoundIsRefused(): void
    {
        $tester = $this->inventory(['--company' => '01890000-0000-7000-8000-000000000000']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No company 01890000-0000-7000-8000-000000000000', $tester->getDisplay());
        self::assertSame(1, $this->inventory(['--company' => 'not-a-uuid'])->getStatusCode());
    }

    /** @param array<string, mixed> $options */
    private function inventory(array $options): ApplicationTester
    {
        return $this->console(['command' => 'app:fiscal:inventory', ...$options]);
    }

    /** @param array<string, mixed> $input */
    private function console(array $input): ApplicationTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, ['interactive' => false]);

        return $tester;
    }

    /** @return array{fiscal: bool, companies: list<mixed>} */
    private function decoded(ApplicationTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsBool($decoded['fiscal'] ?? null);
        self::assertIsList($decoded['companies'] ?? null);

        return ['fiscal' => $decoded['fiscal'], 'companies' => $decoded['companies']];
    }

    /**
     * @param array{fiscal: bool, companies: list<mixed>} $inventory
     *
     * @return array{name: mixed, fiscal: mixed, records: list<mixed>}
     */
    private function companyIn(array $inventory, string $id): array
    {
        foreach ($inventory['companies'] as $company) {
            self::assertIsArray($company);
            if ($id === ($company['id'] ?? null)) {
                self::assertIsList($company['records'] ?? null);

                return ['name' => $company['name'] ?? null, 'fiscal' => $company['fiscal'] ?? null, 'records' => $company['records']];
            }
        }
        self::fail("Company $id is listed.");
    }

    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }
}

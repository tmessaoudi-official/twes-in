<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\RunDataChecks;
use App\Files\Application\FileStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\Uuid;

/**
 * Every invariant no constraint states, each broken once on the demo dataset, which every workflow writes and which the
 * checks find nothing in (DemoFixturesTest): one row changed the way only a direct write could, inside a savepoint, and
 * the check of that invariant must name the company, with nothing of what the document says.
 */
final class DataCheckMembersTest extends ApiTestCase
{
    private string $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('operator@twes.local', 'operator-secret', operator: true);
        $application = new Application(static::$kernel ?? throw new \LogicException('The kernel is not booted.'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'doctrine:fixtures:load', '--append' => true], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->company = $this->text($this->db()->fetchOne("SELECT id FROM company WHERE name = 'Carthage Conseil'"));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, list<string>}> the check, then the statements breaking it in the company `:c`
     */
    public static function breaks(): iterable
    {
        $issuedInvoice = "SELECT id FROM invoice WHERE company_id = :c AND document_type = 'invoice' AND number IS NOT NULL";
        $credited = "SELECT corrects_invoice_id FROM invoice WHERE company_id = :c AND document_type = 'credit_note' AND number IS NOT NULL LIMIT 1";
        $second = static fn (string $table): string => "SELECT id FROM $table WHERE company_id = :c AND number IS NOT NULL ORDER BY issue_date, length(number), number, id OFFSET 1 LIMIT 1";
        // The application's role cannot delete from the journal: a draft given a number by hand is a document numbered
        // without the use case that records it.
        $numbered = static fn (string $table): string => "UPDATE $table SET number = 'HORS-JOURNAL-1' WHERE id = (SELECT id FROM $table WHERE company_id = :c AND number IS NULL LIMIT 1)";

        yield 'a credit note taken off twice' => ['invoices.credit_notes', ["UPDATE invoice SET amount_credited = amount_credited + 1 WHERE id = ($credited)"]];
        yield 'a credit note of another customer' => ['invoices.credit_notes', [
            "UPDATE invoice SET customer_id = (SELECT id FROM customer WHERE company_id = :c AND id <> invoice.customer_id LIMIT 1) WHERE id = (SELECT id FROM invoice WHERE company_id = :c AND document_type = 'credit_note' AND number IS NOT NULL LIMIT 1)",
        ]];
        yield 'a full credit note giving back another tax than was charged' => ['invoices.credit_notes', [
            "UPDATE invoice c SET tax_breakdown = jsonb_set(c.tax_breakdown, '{0,amount}', to_jsonb(((c.tax_breakdown->0->>'amount')::numeric - 1)::text)) FROM invoice i WHERE i.id = c.corrects_invoice_id AND c.company_id = :c AND c.document_type = 'credit_note' AND c.number IS NOT NULL AND c.total_net = -i.total_net AND jsonb_array_length(c.tax_breakdown) > 0 AND c.id = (SELECT c2.id FROM invoice c2 JOIN invoice i2 ON i2.id = c2.corrects_invoice_id WHERE c2.company_id = :c AND c2.document_type = 'credit_note' AND c2.number IS NOT NULL AND c2.total_net = -i2.total_net AND jsonb_array_length(c2.tax_breakdown) > 0 LIMIT 1)",
        ]];
        yield 'a line whose gross is not its net and tax' => ['invoices.lines', ['UPDATE invoice_line SET line_gross = line_gross + 1 WHERE id = (SELECT l.id FROM invoice_line l JOIN invoice i ON i.id = l.invoice_id WHERE i.company_id = :c AND i.number IS NOT NULL ORDER BY i.number DESC LIMIT 1)']];
        yield 'lines that no longer add up to their document' => ['invoices.lines', [
            'UPDATE invoice_line SET line_net = line_net + 1, line_gross = line_gross + 1 WHERE id = (SELECT l.id FROM invoice_line l JOIN invoice i ON i.id = l.invoice_id WHERE i.company_id = :c AND i.number IS NOT NULL AND COALESCE(i.document_discount, 0) = 0 LIMIT 1)',
        ]];
        // issue-shortcut: a paid invoice still owing, a state no use case makes, which the check must notice
        yield 'an invoice paid while something is due' => ['invoices.settlement', ["UPDATE invoice SET status = 'paid' WHERE id = ($issuedInvoice AND status = 'issued' AND amount_due > 0 LIMIT 1)"]];
        yield 'a withdrawn draft that kept a number' => ['invoices.settlement', ["UPDATE invoice SET number = 'FAC-X-1' WHERE id = (SELECT id FROM invoice WHERE company_id = :c AND status = 'cancelled' LIMIT 1)"]];
        yield 'credit spent that was never held' => ['invoices.credit_balance', [
            "INSERT INTO customer_credit_entry (id, company_id, customer_id, entry_date, kind, amount, invoice_id, created_at) SELECT gen_random_uuid(), :c, i.customer_id, i.issue_date, 'applied', -1000000, i.id, i.issued_at FROM invoice i WHERE i.id = ($issuedInvoice LIMIT 1)",
        ]];
        yield 'credit given back with the wrong sign' => ['invoices.credit_balance', [
            "INSERT INTO customer_credit_entry (id, company_id, customer_id, entry_date, kind, amount, invoice_id, created_at) SELECT gen_random_uuid(), :c, i.customer_id, i.issue_date, 'overpayment', -1, i.id, i.issued_at FROM invoice i WHERE i.id = ($issuedInvoice LIMIT 1); INSERT INTO customer_credit_entry (id, company_id, customer_id, entry_date, kind, amount, invoice_id, created_at) SELECT gen_random_uuid(), :c, i.customer_id, i.issue_date, 'credited', 5, i.id, i.issued_at FROM invoice i WHERE i.id = ($issuedInvoice LIMIT 1)",
        ]];
        yield 'an invoice numbered with no trace in the journal' => ['invoices.journal', [$numbered('invoice')]];
        yield 'a delivery note number skipped' => ['delivery_notes.numbering', ["UPDATE delivery_note SET number = regexp_replace(number, '[0-9]+$', '99999') WHERE id = (".$second('delivery_note').')']];
        yield 'a delivery note numbered with no trace in the journal' => ['delivery_notes.journal', [$numbered('delivery_note')]];
        yield 'a quote number skipped' => ['quotes.numbering', ["UPDATE quote SET number = regexp_replace(number, '[0-9]+$', '99999') WHERE id = (".$second('quote').')']];
        yield 'a quote numbered with no trace in the journal' => ['quotes.journal', [$numbered('quote')]];
        yield 'an order number skipped' => ['purchase_orders.numbering', ["UPDATE purchase_order SET number = regexp_replace(number, '[0-9]+$', '99999') WHERE id = (".$second('purchase_order').')']];
        yield 'an order numbered with no trace in the journal' => ['purchase_orders.journal', [$numbered('purchase_order')]];
        yield 'an expense whose gross no longer adds up' => ['expenses.arithmetic', ['UPDATE expense SET amount_gross = amount_gross + 1 WHERE id = (SELECT id FROM expense WHERE company_id = :c LIMIT 1)']];
        yield 'an expense whose lines no longer add up' => ['expenses.arithmetic', ['UPDATE expense_line SET amount_net = amount_net + 1 WHERE id = (SELECT id FROM expense_line WHERE company_id = :c LIMIT 1)']];
        yield 'a serial number in stock twice' => ['inventory.serials', [
            "UPDATE stock_movement SET quantity = 2 WHERE id = (SELECT m.id FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE m.company_id = :c AND p.tracking = 'serial' AND m.quantity = 1 LIMIT 1)",
        ]];
        yield 'goods of a lot-tracked product moved under no lot' => ['inventory.lots', [
            'UPDATE stock_movement SET lot_id = NULL WHERE id = (SELECT id FROM stock_movement WHERE company_id = :c AND lot_id IS NOT NULL ORDER BY at DESC LIMIT 1)',
        ]];
        yield 'goods moved under the lot of another product' => ['inventory.lots', [
            'UPDATE stock_movement SET lot_id = (SELECT l.id FROM stock_lot l WHERE l.company_id = :c AND l.product_id <> stock_movement.product_id LIMIT 1) WHERE id = (SELECT id FROM stock_movement WHERE company_id = :c AND lot_id IS NOT NULL ORDER BY at DESC LIMIT 1)',
        ]];
        yield 'a stored file whose bytes are gone' => ['files.stored', [
            "INSERT INTO file (id, company_id, storage_key, original_name, mime, size, sha256, created_at) SELECT gen_random_uuid(), :c, 'companies/' || :c || '/' || gen_random_uuid(), 'gone.pdf', 'application/pdf', 3, repeat('0', 64), now()",
        ]];
        yield 'a key claimed by a request that stopped' => ['submissions.stuck', [
            "INSERT INTO submission_claim (account_id, key, attempt, company_id, fingerprint, claimed_at) SELECT id, gen_random_uuid(), gen_random_uuid(), :c, repeat('0', 64), now() - interval '1 hour' FROM \"user\" WHERE email = 'operator@twes.local'",
        ]];
        yield 'a key the hourly purge never forgot' => ['submissions.stuck', [
            "INSERT INTO submission_claim (account_id, key, attempt, company_id, fingerprint, claimed_at, work_done, status, body) SELECT id, gen_random_uuid(), gen_random_uuid(), :c, repeat('0', 64), now() - interval '3 days', true, 201, '{}' FROM \"user\" WHERE email = 'operator@twes.local'",
        ]];
    }

    /**
     * One test over every break rather than one per break: the demo is loaded once, each break made and undone in a
     * savepoint of its own, and every break the checks miss listed together.
     */
    public function testEachInvariantBrokenInTheDemoIsFoundByItsCheckAndNamesItsCompany(): void
    {
        self::assertSame([], $this->findings(), 'the demo, as the use cases leave it, holds every check');
        $checks = static::getContainer()->get(RunDataChecks::class)->checks();

        $missed = [];
        foreach (self::breaks() as $break => [$check, $statements]) {
            if (!\in_array($check, $checks, true)) {
                $missed[] = "$break: no check is named $check";
                continue;
            }
            $db = $this->db();
            $db->beginTransaction();
            try {
                foreach ($statements as $statement) {
                    foreach (array_filter(array_map('trim', explode(';', $statement))) as $sql) {
                        if (0 === $db->executeStatement($sql, ['c' => $this->company])) {
                            $missed[] = "$break: the demo has nothing to break, $sql";
                            continue 3;
                        }
                    }
                }
                $found = 0;
                foreach ($this->findings() as $finding) {
                    if ($finding->check === $check) {
                        ++$found;
                    }
                }
                if (0 === $found) {
                    $missed[] = "$break: $check found nothing in the company";
                }
            } catch (DriverException $refused) {
                // The database states this one itself: a check of it would be a constraint's twin.
                $missed[] = "$break: the database refuses it, ".strtok($refused->getMessage(), "\n");
            } finally {
                $db->rollBack();
            }
        }

        self::assertSame([], $missed);
    }

    /**
     * The other way round: bytes under a company's folder that no file row names, which a write cut short between the
     * bytes and the row leaves. Bytes written within the longest a request takes are a write still running, left out.
     */
    public function testBytesNoFileRowNamesAreFoundOnceNoRequestCanStillBeWritingThem(): void
    {
        $storage = static::getContainer()->get(FileStorage::class);
        $key = 'companies/'.$this->company.'/'.Uuid::v7()->toRfc4122();
        $storage->write($key, 'orphan');
        try {
            self::assertNotContains('files.stored', array_map(static fn (DataFinding $finding): string => $finding->check, $this->findings()), 'a write still running');

            $later = new MockClock();
            $later->modify('+1 hour');
            Clock::set($later);

            $found = array_values(array_filter($this->findings(), static fn (DataFinding $finding): bool => 'files.stored' === $finding->check));
            self::assertCount(1, $found);
            self::assertSame($this->company, $found[0]->companyId?->toRfc4122());
        } finally {
            $storage->delete($key);
        }
    }

    /**
     * @return list<DataFinding> the findings in the demo company, read again on every call
     *
     * @phpstan-impure
     */
    private function findings(): array
    {
        $this->em()->clear();

        return array_values(array_filter(
            static::getContainer()->get(RunDataChecks::class)->findings(),
            fn (DataFinding $finding): bool => $finding->companyId?->toRfc4122() === $this->company,
        ));
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : self::fail('Expected a text.');
    }
}

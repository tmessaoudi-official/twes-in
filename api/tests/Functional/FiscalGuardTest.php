<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An issued invoice is the fiscal record: what it says is never rewritten, by the application or by any statement its
 * role sends. The database refuses it, so a bug, a careless script or a hand typing SQL cannot change a figure, a
 * number or a line once issued; what an issued invoice still lives through, its payments, its credit notes, who received
 * its goods, its stored PDF and its seal, stays writable, and its settlement only as the figures give it. Every statement
 * here runs as the application's role, in a savepoint of its own, as the tests' transaction would be lost on the first
 * refusal otherwise.
 */
final class FiscalGuardTest extends ApiTestCase
{
    /** The columns of an issued invoice the application still writes, every other one being guarded. */
    private const array FREE = ['status', 'amount_paid', 'amount_due', 'amount_credited', 'updated_at', 'version', 'received_by_name', 'received_by_contact_id', 'notes_internal'];
    /** Written once, from nothing to a value: the stored PDF and the seal. */
    private const array SEALED_ONCE = ['pdf_file_id', 'chain_position', 'chain_previous', 'chain_hash', 'chain_version'];

    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], self::heldNow());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit'], 'member');
        $this->login('sales@twes.local', 'password-1234');
    }

    /** @return iterable<string, array{0: string, 1?: string}> the statement, then what its refusal says when one arm alone must give it */
    public static function rewrites(): iterable
    {
        yield 'its total' => ['UPDATE invoice SET total_gross = total_gross + 1 WHERE id = :id'];
        yield 'its number' => ["UPDATE invoice SET number = number || '-B' WHERE id = :id"];
        yield 'its day' => ['UPDATE invoice SET issue_date = issue_date - 1 WHERE id = :id'];
        yield 'its due day' => ['UPDATE invoice SET due_date = due_date + 30 WHERE id = :id'];
        yield 'its customer as printed' => ["UPDATE invoice SET customer_snapshot = jsonb_set(customer_snapshot, '{name}', '\"Autre\"') WHERE id = :id"];
        yield 'its names as printed' => ['UPDATE invoice SET printed_names = NULL WHERE id = :id'];
        yield 'its seal, once set' => ["UPDATE invoice SET chain_hash = repeat('0', 64) WHERE id = :id"];
        yield 'its status back to a draft' => ["UPDATE invoice SET status = 'draft' WHERE id = :id"]; // issue-shortcut: a status the guard must refuse to rewrite
        yield 'its status cancelled' => ["UPDATE invoice SET status = 'cancelled' WHERE id = :id"]; // issue-shortcut: a status the guard must refuse to rewrite
        yield 'what is due, other than the figures leave' => ['UPDATE invoice SET amount_due = amount_due + 1 WHERE id = :id'];
        yield 'paid in full, still said issued' => ['UPDATE invoice SET amount_paid = total_gross - withholding_amount, amount_due = 0 WHERE id = :id'];
        yield 'a line\'s price' => ['UPDATE invoice_line SET unit_price_net = unit_price_net + 1 WHERE invoice_id = :id'];
        yield 'a line\'s words' => ["UPDATE invoice_line SET description = 'Autre chose' WHERE invoice_id = :id"];
        yield 'a line taken out' => ['DELETE FROM invoice_line WHERE invoice_id = :id'];
        yield 'a line put in' => ['INSERT INTO invoice_line (id, invoice_id, company_id, position, description, quantity, unit_id, unit_price_net, line_net, line_tax, line_gross, returned) SELECT gen_random_uuid(), invoice_id, company_id, 99, description, quantity, unit_id, unit_price_net, line_net, line_tax, line_gross, returned FROM invoice_line WHERE invoice_id = :id LIMIT 1'];
        yield 'a line\'s tax' => ['UPDATE invoice_line_tax SET rate = rate + 1 WHERE line_id IN (SELECT id FROM invoice_line WHERE invoice_id = :id)'];
        yield 'the document\'s tax' => ['UPDATE invoice_tax SET amount = amount + 1 WHERE invoice_id = :id'];
        yield 'the invoice itself' => ['DELETE FROM invoice WHERE id = :id', 'is never deleted'];
        yield 'its company, which takes it along' => ['DELETE FROM company WHERE id = (SELECT company_id FROM invoice WHERE id = :id)'];
    }

    #[DataProvider('rewrites')]
    public function testWhatAnIssuedInvoiceSaysIsNeverRewritten(string $statement, string $said = 'fiscal guard'): void
    {
        $id = $this->issued('100');
        $before = $this->rows($id);

        self::assertStringContainsString($said, $this->refusal($statement, $id) ?? 'the database let it through');
        self::assertSame($before, $this->rows($id));
    }

    public function testACreditNotesSettlementNeverChangesOnceIssued(): void
    {
        $invoice = $this->issued('100');
        $this->postJson($this->path($invoice).'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(201);
        $creditNote = $this->stringAt($this->json(), 'id');
        $this->issue($creditNote);

        foreach ([
            "UPDATE invoice SET status = 'paid', amount_due = 0, amount_paid = total_gross WHERE id = :id", // issue-shortcut: a settlement the guard must refuse on an issued credit note
            'UPDATE invoice SET amount_credited = 1 WHERE id = :id',
        ] as $statement) {
            self::assertStringContainsString('fiscal guard', $this->refusal($statement, $creditNote) ?? "let through: $statement");
        }
    }

    public function testWhatAnIssuedInvoiceStillLivesThroughStaysWritable(): void
    {
        $id = $this->issued('100');

        foreach ([
            "UPDATE invoice SET amount_paid = '50.000', amount_due = amount_due - 50, status = 'partially_paid', version = version + 1 WHERE id = :id", // issue-shortcut: a settlement the guard must let through
            "UPDATE invoice SET received_by_name = 'Sami', notes_internal = 'livré au dépôt' WHERE id = :id",
            "UPDATE invoice SET amount_credited = '10.000', amount_due = amount_due - 10, status = 'partially_paid' WHERE id = :id", // issue-shortcut: a settlement the guard must let through
            "UPDATE invoice SET amount_paid = total_gross - withholding_amount, amount_due = 0, status = 'paid' WHERE id = :id", // issue-shortcut: a settlement the guard must let through
        ] as $statement) {
            self::assertNull($this->refusal($statement, $id), $statement);
        }
    }

    /**
     * A row a past bug left with a settlement the figures do not give still takes what it lives through: the guard reads
     * the settlement only when it is written, and the data checks report the rest.
     */
    public function testAnInconsistentRowStillTakesWhatItLivesThrough(): void
    {
        $id = $this->issued('100');
        $connection = $this->connection();
        $connection->executeStatement('SELECT fiscal_guard_test_release(true)');
        $connection->executeStatement('UPDATE invoice SET amount_due = amount_due + 7 WHERE id = ?', [$id]);
        $connection->executeStatement('SELECT fiscal_guard_test_release(false)');

        self::assertNull($this->refusal("UPDATE invoice SET received_by_name = 'Sami' WHERE id = :id", $id));
        // Its settlement written again is still held to the figures.
        self::assertStringContainsString('fiscal guard', $this->refusal('UPDATE invoice SET amount_due = amount_due - 1 WHERE id = :id', $id) ?? 'the database let it through');
    }

    /**
     * A transaction already released (the scale generator's clones, a company let go) keeps no list of the invoices it
     * writes issued: each would lengthen one setting every later row reads, and a chunk of clones took minutes on it.
     */
    public function testAReleasedTransactionKeepsNoListOfWhatItWritesIssued(): void
    {
        $id = $this->issued('100');
        $connection = $this->connection();
        $connection->executeStatement('SELECT fiscal_guard_test_release(true)');
        $connection->executeStatement('CREATE TEMPORARY TABLE invoice_clone ON COMMIT DROP AS SELECT * FROM invoice WHERE id = ?', [$id]);
        $connection->executeStatement("UPDATE invoice_clone SET id = gen_random_uuid(), number = number || '-C', pdf_file_id = NULL, chain_position = NULL, chain_previous = NULL, chain_hash = NULL, chain_version = NULL");
        $connection->executeStatement('INSERT INTO invoice SELECT * FROM invoice_clone');
        $listed = $connection->fetchOne("SELECT current_setting('twes.issuing', true)");
        $connection->executeStatement('SELECT fiscal_guard_test_release(false)');

        self::assertContains($listed, ['', null]);
    }

    public function testADraftIsTheCompanysToChange(): void
    {
        $id = $this->draft('100');

        foreach ([
            'UPDATE invoice SET discount_amount = 5 WHERE id = :id',
            "UPDATE invoice_line SET description = 'Autre chose' WHERE invoice_id = :id",
            'DELETE FROM invoice WHERE id = :id',
        ] as $statement) {
            self::assertNull($this->refusal($statement, $id), $statement);
        }
    }

    /** @return iterable<string, array{string}> every table a TRUNCATE could empty an issued invoice from, none of whose rows sees it */
    public static function emptied(): iterable
    {
        yield 'the invoices' => ['invoice'];
        yield 'their lines' => ['invoice_line'];
        yield 'their lines\' taxes' => ['invoice_line_tax'];
        yield 'their taxes' => ['invoice_tax'];
        yield 'the companies, which take them along' => ['company'];
    }

    /** The application's role holds no TRUNCATE; the owner's, which fires no row's guard, is refused by the table's. */
    #[DataProvider('emptied')]
    public function testNoTableOfAnIssuedInvoiceIsEmptiedWhileOneIsKept(string $table): void
    {
        $id = $this->issued('100');
        $before = $this->rows($id);

        $refused = $this->refusal('SELECT fiscal_guard_test_truncate(CAST(:table AS regclass))', $id, ['table' => $table], select: true);

        self::assertStringContainsString('fiscal guard', $refused ?? "the owner emptied $table");
        self::assertSame($before, $this->rows($id));
    }

    #[DataProvider('emptied')]
    public function testTablesHoldingOnlyDraftsAreEmptied(string $table): void
    {
        $id = $this->draft('100');

        self::assertNull($this->refusal('SELECT fiscal_guard_test_truncate(CAST(:table AS regclass))', $id, ['table' => $table], select: true));
    }

    /** A company whose keeping time has run out goes whole, its issued invoices with it, through the one release. */
    public function testACompanyThatMayGoIsReleasedThenRemoved(): void
    {
        $id = $this->issued('100');
        $company = $this->company->getId()->toRfc4122();
        $this->connection()->executeStatement("UPDATE company SET status = 'closed', closed_down_at = ?, retain_until = ? WHERE id = ?", [self::apiNow('-11 years'), $this->dayOf($this->company, '-1 day'), $company]);

        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement('SELECT fiscal_release_company(CAST(? AS uuid), CAST(? AS date))', [$company, $this->dayOf($this->company)]);
            self::assertSame(1, $connection->executeStatement('DELETE FROM company WHERE id = ?', [$company]));
            self::assertFalse($connection->fetchOne('SELECT 1 FROM invoice WHERE id = ?', [$id]));
        } finally {
            $connection->rollBack();
        }
    }

    /** @return iterable<string, array{?string}> how long a closed company is kept, from today, or null for one still open */
    public static function stillKept(): iterable
    {
        yield 'still open' => [null];
        yield 'closed, kept until today' => ['+0 days'];
        yield 'closed, kept ten years more' => ['+10 years'];
    }

    #[DataProvider('stillKept')]
    public function testACompanyStillKeptIsNotReleased(?string $keptUntil): void
    {
        $this->issued('100');
        $company = $this->company->getId()->toRfc4122();
        if (null !== $keptUntil) {
            $this->connection()->executeStatement("UPDATE company SET status = 'closed', closed_down_at = ?, retain_until = ? WHERE id = ?", [self::apiNow('-1 day'), $this->dayOf($this->company, $keptUntil), $company]);
        }

        self::assertStringContainsString('fiscal guard', $this->refusal('SELECT fiscal_release_company(CAST(:company AS uuid), CAST(:today AS date))', $company, ['company' => $company, 'today' => $this->dayOf($this->company)], select: true) ?? 'released a company still kept');
    }

    /** The release is written by its function alone: the application's role cannot open the door itself. */
    public function testTheApplicationCannotWriteTheReleaseItself(): void
    {
        $refusal = $this->refusal('INSERT INTO fiscal_release (company_id, transaction_id, reason, at) VALUES (CAST(:id AS uuid), pg_current_xact_id(), \'by hand\', CAST(:at AS timestamp))', '', ['id' => $this->company->getId()->toRfc4122(), 'at' => self::apiNow()]);

        self::assertStringContainsString('permission denied', $refusal ?? 'the application wrote a release');
    }

    /**
     * The guard names what stays free and lets nothing else through: a column added to the invoice later is guarded
     * until it is read and listed here, in the trigger and in this test both.
     */
    public function testEveryColumnOfAnInvoiceIsGuardedButTheOnesItStillLivesThrough(): void
    {
        $columns = $this->connection()->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'invoice'");
        foreach ([...self::FREE, ...self::SEALED_ONCE] as $listed) {
            self::assertContains($listed, $columns, "$listed is no column of the invoice any more");
        }
        $source = $this->connection()->fetchOne("SELECT prosrc FROM pg_proc WHERE proname = 'fiscal_guard_invoice'");
        self::assertIsString($source, 'no guard on the invoice');
        preg_match_all("/'([a-z_]+)'/", (string) preg_replace('/.*FREE COLUMNS:(.*?)END FREE COLUMNS.*/s', '$1', $source), $free);
        self::assertEqualsCanonicalizing(self::FREE, $free[1], 'the guard and this test name the same free columns');
    }

    /**
     * The refusal the database gave, or null when it let the statement through.
     *
     * @param array<string, string>|null $parameters
     */
    private function refusal(string $statement, string $id, ?array $parameters = null, bool $select = false): ?string
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            if ($select) {
                $connection->fetchOne($statement, $parameters ?? ['id' => $id]);

                return null;
            }
            // A statement reaching no row proves nothing: a row's guard never sees it.
            self::assertGreaterThan(0, $connection->executeStatement($statement, $parameters ?? ['id' => $id]), "touched no row: $statement");

            return null;
        } catch (DbalException $refused) {
            return $refused->getMessage();
        } finally {
            $connection->rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function rows(string $id): array
    {
        $connection = $this->connection();

        return [
            'invoice' => $connection->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]),
            'lines' => $connection->fetchAllAssociative('SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY position', [$id]),
            'taxes' => $connection->fetchAllAssociative('SELECT * FROM invoice_tax WHERE invoice_id = ? ORDER BY position', [$id]),
        ];
    }

    private function issued(string $price): string
    {
        $id = $this->draft($price);
        $this->issue($id);

        return $id;
    }

    /**
     * Issues through the API, then ends the issuing transaction's leave to write the lines: the test's whole case runs
     * in one transaction, where in production the issue's own commit ends it.
     */
    private function issue(string $id): void
    {
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseIsSuccessful();
        $this->connection()->executeStatement("SELECT set_config('twes.issuing', '', true)");
    }

    private function draft(string $price): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->path(), [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [$this->taxId('TIMBRE')],
            'lines' => [['description' => 'Conseil', 'quantity' => '1', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => $price, 'taxComponentIds' => [$this->taxId('TVA19')]]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->stringAt($this->json(), 'id');
    }

    private function taxId(string $code): string
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId()->toRfc4122();
    }

    private function path(string $id = ''): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.('' === $id ? '' : '/'.$id);
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }
}

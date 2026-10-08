<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\DemoCompanies;
use App\Module\Invoices\Application\FacturX\ExportFacturX;
use App\Module\Invoices\Application\FacturX\FacturXRefused;
use App\Tenancy\Domain\Company;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\Uuid;

/**
 * The demo dataset (docs/SPEC.md § 7, 2026-09-19): two companies the seeded operator owns, filled through the use
 * cases, so a load is also a smoke test of every workflow it goes through. Counted with SQL rather than the entity
 * manager, which a refused load leaves closed.
 */
final class DemoFixturesTest extends ApiTestCase
{
    private const array COMPANIES = ['Carthage Conseil' => 'TND', 'Atelier Mercier' => 'EUR'];

    public function testTwoCompaniesTheOperatorOwnsAreFilledWithEveryStateAScreenCanShow(): void
    {
        $this->createUser('operator@twes.local', 'operator-secret', operator: true);

        $tester = $this->load();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        foreach (self::COMPANIES as $name => $currency) {
            $company = $this->row('SELECT c.id, c.status, c.currency FROM company c WHERE c.name = ?', [$name]);
            self::assertSame(['active', $currency], [$company['status'], $company['currency']], $name);
            $id = $company['id'];
            self::assertSame(1, $this->numberOf("SELECT COUNT(*) FROM membership m JOIN \"user\" u ON u.id = m.user_id JOIN role r ON r.id = m.role_id WHERE m.company_id = ? AND u.email = 'operator@twes.local' AND r.name = 'owner'", [$id]), "$name is owned by the operator");

            // One member per built-in role, so a walkthrough can say truthfully what each of them may not do, and so
            // a permission refusal is something a person can sign in and meet rather than read about (§ 7, 2026-09-20).
            foreach (DemoCompanies::TESTERS as $role => $email) {
                self::assertSame(
                    1,
                    $this->numberOf('SELECT COUNT(*) FROM membership m JOIN "user" u ON u.id = m.user_id JOIN role r ON r.id = m.role_id WHERE m.company_id = ? AND u.email = ? AND r.name = ?', [$id, $email, $role]),
                    "$name has a $role called $email",
                );
            }

            // A list page shows 25 rows: each of these pages past it.
            foreach (['customer', 'product', 'invoice'] as $table) {
                self::assertGreaterThan(25, $this->numberOf("SELECT COUNT(*) FROM $table WHERE company_id = ?", [$id]), "$name has more $table rows than one page");
            }
            self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM customer WHERE company_id = ? AND is_active = false', [$id]), "$name has a deactivated customer");
            self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM customer_group WHERE company_id = ?', [$id]));
            self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM contact c JOIN customer k ON k.id = c.customer_id WHERE k.company_id = ?', [$id]));
            self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM product_category WHERE company_id = ?', [$id]));
            self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM vendor WHERE company_id = ?', [$id]));

            self::assertSame(
                ['cancelled', 'draft', 'issued', 'paid', 'partially_paid'],
                $this->column("SELECT DISTINCT status FROM invoice WHERE company_id = ? AND document_type = 'invoice' ORDER BY status", [$id]),
                "$name has an invoice in every state",
            );
            self::assertGreaterThan(0, $this->numberOf("SELECT COUNT(*) FROM invoice WHERE company_id = ? AND document_type = 'credit_note' AND status = 'issued'", [$id]), "$name has an issued credit note");
            self::assertGreaterThan(0, $this->numberOf("SELECT COUNT(*) FROM invoice_line l JOIN invoice i ON i.id = l.invoice_id WHERE i.company_id = ? AND i.status <> 'draft' AND l.discount_amount > 0", [$id]), "$name has an issued line discounted by an amount");
            self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM quote_line l JOIN quote q ON q.id = l.quote_id WHERE q.company_id = ? AND l.discount_amount > 0', [$id]), "$name has a quote line discounted by an amount");
            self::assertGreaterThan(0, $this->numberOf("SELECT COUNT(*) FROM invoice WHERE company_id = ? AND document_type = 'invoice' AND status IN ('issued', 'partially_paid') AND due_date < CURRENT_DATE", [$id]), "$name has an overdue invoice");
            self::assertGreaterThan(0, $this->numberOf("SELECT COUNT(*) FROM invoice WHERE company_id = ? AND document_type = 'invoice' AND status = 'issued' AND due_date >= CURRENT_DATE", [$id]), "$name has an invoice not yet due");
            self::assertSame(0, $this->numberOf("SELECT COUNT(*) FROM invoice WHERE company_id = ? AND status = 'paid' AND amount_due <> 0", [$id]), 'a paid invoice owes nothing');
            self::assertSame(0, $this->numberOf("SELECT COUNT(*) FROM invoice WHERE company_id = ? AND status IN ('issued', 'partially_paid', 'paid') AND (number IS NULL OR issue_date > CURRENT_DATE)", [$id]), 'every issued document is numbered and dated no later than today');
            self::assertGreaterThan(90, $this->numberOf('SELECT CURRENT_DATE - MIN(issue_date) FROM invoice WHERE company_id = ?', [$id]), "$name's invoices span months");
            $numbers = array_map(static fn (mixed $number): string => \is_string($number) ? $number : throw new \LogicException('A number is text.'), $this->column("SELECT number FROM invoice WHERE company_id = ? AND document_type = 'invoice' AND number IS NOT NULL ORDER BY issue_date, number", [$id]));
            $sorted = $numbers;
            sort($sorted, \SORT_STRING);
            self::assertSame($sorted, $numbers, 'numbers follow the issue dates');
            self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM invoice i WHERE i.company_id = ? AND i.amount_paid <> (SELECT COALESCE(SUM(p.amount), 0) FROM payment p WHERE p.invoice_id = i.id)', [$id]), 'what an invoice says it was paid is its payments');

            self::assertSame(
                ['cancelled', 'delivered', 'draft', 'invoiced', 'validated'],
                $this->column('SELECT DISTINCT status FROM delivery_note WHERE company_id = ? ORDER BY status', [$id]),
                "$name has a delivery note in every state",
            );
            self::assertSame(['draft', 'paid', 'recorded'], $this->column('SELECT DISTINCT status FROM expense WHERE company_id = ? ORDER BY status', [$id]));
            self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM stock_movement WHERE company_id = ? AND quantity < 0', [$id]), "$name's deliveries took goods out of stock");
            // The stock map opens on a drawn depot: a measured floor, its racks and zones drawn, the building around
            // them, and the goods sitting in the racks that are their homes, so a search on the map has places to light.
            self::assertSame(1, $this->numberOf('SELECT COUNT(*) FROM venue_area WHERE company_id = ? AND width_metres > 0 AND depth_metres > 0', [$id]), "$name has a measured floor");
            self::assertGreaterThanOrEqual(3, $this->numberOf("SELECT COUNT(*) FROM stock_location WHERE company_id = ? AND kind = 'rack' AND spot_id IS NOT NULL", [$id]), "$name's racks are drawn");
            self::assertSame(2, $this->numberOf("SELECT COUNT(*) FROM stock_location WHERE company_id = ? AND kind = 'zone' AND spot_id IS NOT NULL", [$id]), "$name's reception and dispatch zones are drawn");
            self::assertSame(
                ['dock', 'door', 'post', 'wall'],
                $this->column('SELECT DISTINCT kind FROM venue_structure WHERE company_id = ? ORDER BY kind', [$id]),
                "$name's building has its walls, a door, a post and a dock",
            );
            self::assertSame(0, $this->numberOf("SELECT COUNT(*) FROM (SELECT m.product_id FROM stock_movement m JOIN product p ON p.id = m.product_id JOIN stock_location l ON l.id = m.location_id WHERE m.company_id = ? AND p.tracking = 'none' GROUP BY m.product_id HAVING SUM(CASE WHEN l.kind = 'rack' AND l.spot_id IS NOT NULL THEN m.quantity ELSE 0 END) <= 0) held", [$id]), "every one of $name's goods is held in a drawn rack");
            self::assertSame(0, $this->numberOf("SELECT COUNT(*) FROM product p WHERE p.company_id = ? AND p.kind = 'goods' AND p.is_active AND NOT EXISTS (SELECT 1 FROM product_home_location h WHERE h.product_id = p.id)", [$id]), "every one of $name's goods has a home");
            self::assertSame(
                ['accepted', 'cancelled', 'draft', 'refused', 'sent'],
                $this->column('SELECT DISTINCT status FROM quote WHERE company_id = ? ORDER BY status', [$id]),
                "$name has a quote in every state",
            );
            self::assertSame(1, $this->numberOf("SELECT COUNT(*) FROM quote q JOIN invoice i ON i.id = q.invoice_id WHERE q.company_id = ? AND i.status <> 'draft'", [$id]), "$name invoiced an accepted quote");
            // A deposit, paid, then given back on the final invoice of its quote (docs/fiscal § 2b): the list, its PDF and
            // Factur-X all have one to show.
            self::assertSame(1, $this->numberOf("SELECT COUNT(*) FROM invoice d JOIN quote q ON q.id = d.quote_id JOIN invoice f ON f.id = q.invoice_id WHERE d.company_id = ? AND d.deposit AND d.status = 'paid' AND f.status <> 'draft' AND EXISTS (SELECT 1 FROM invoice_line l WHERE l.invoice_id = f.id AND l.deducts_invoice_id = d.id)", [$id]), "$name gave a paid deposit back on its final invoice");
            self::assertGreaterThan(0, $this->numberOf("SELECT COUNT(*) FROM customer_credit_entry WHERE company_id = ? AND kind = 'overpayment'", [$id]), "$name kept a trop-perçu to a customer's credit");
            self::assertSame(1, $this->numberOf('SELECT COUNT(*) FROM quote WHERE company_id = ? AND status = ? AND valid_until < CURRENT_DATE', [$id, 'sent']), "$name has a quote past its validity");

            // Through the use cases, not around them: every write left its audit row.
            self::assertGreaterThan(100, $this->numberOf('SELECT COUNT(*) FROM audit_log WHERE company_id = ?', [$id]), "$name was written through the audited use cases");
        }
        // Stock by lot and by serial number, there to be seen: a lot already past its date, a lot still good, and serial
        // numbers one piece each, all named by their movements.
        self::assertSame(
            ['lot', 'serial'],
            $this->column('SELECT DISTINCT p.tracking FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE m.lot_id IS NOT NULL ORDER BY p.tracking', []),
        );
        self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM stock_lot WHERE expires_on < CURRENT_DATE', []), 'a lot has expired by the end of the story');
        self::assertGreaterThan(0, $this->numberOf('SELECT COUNT(*) FROM stock_lot WHERE expires_on > CURRENT_DATE', []), 'a lot is still good');
        self::assertSame(0, $this->numberOf("SELECT COUNT(*) FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE (p.tracking = 'none') <> (m.lot_id IS NULL)", []), 'a movement names a lot exactly when its product tracks one');
        self::assertGreaterThan(0, $this->numberOf("SELECT COUNT(*) FROM stock_movement WHERE source_type = 'delivery_note' AND lot_id IS NOT NULL", []), 'a delivery note took goods from their lots');
        self::assertInstanceOf(NativeClock::class, Clock::get(), 'the clock the load moved is given back');

        // Every document the French company issued is written as Factur-X, so the invoice screen's « Factur-X » has a
        // file to hand over rather than a list of what the demo forgot (a customer's postal code, its country). Two
        // are refused, and the screen shows why: a sale to the customer whose regime is plain « exempt », which names
        // no exempting article, and the intra-community sale, whose customer cannot yet hold a VAT number of its own
        // country.
        $mercier = $this->row("SELECT id FROM company WHERE name = 'Atelier Mercier'", [])['id'];
        self::assertIsString($mercier);
        $company = $this->em()->find(Company::class, Uuid::fromString($mercier));
        self::assertInstanceOf(Company::class, $company);
        $export = static::getContainer()->get(ExportFacturX::class);
        $refused = [];
        foreach ($this->column("SELECT id FROM invoice WHERE company_id = ? AND status NOT IN ('draft', 'cancelled') ORDER BY number", [$mercier]) as $invoiceId) {
            self::assertIsString($invoiceId);
            try {
                $export->xml($company, Uuid::fromString($invoiceId));
            } catch (FacturXRefused $refusal) {
                $refused[] = array_column($refusal->gaps, 'code');
            }
        }
        sort($refused);
        self::assertSame([['buyer_vat_number_missing'], ['vat_exemption_undeclared']], $refused);
    }

    public function testASecondLoadChangesNothing(): void
    {
        $this->createUser('operator@twes.local', 'operator-secret', operator: true);
        self::assertSame(0, $this->load()->getStatusCode());
        $before = $this->numberOf('SELECT COUNT(*) FROM audit_log');

        $tester = $this->load();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(2, $this->numberOf('SELECT COUNT(*) FROM company WHERE name IN (?, ?)', array_keys(self::COMPANIES)));
        self::assertSame($before, $this->numberOf('SELECT COUNT(*) FROM audit_log'));
    }

    public function testWithoutTheSeededOperatorNothingIsLoaded(): void
    {
        $tester = $this->load();

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('make seed', $tester->getDisplay());
        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM company WHERE name IN (?, ?)', array_keys(self::COMPANIES)));
        self::assertInstanceOf(NativeClock::class, Clock::get());
    }

    /** The command as `make fixtures` runs it, through the console application: a refusal is printed, not thrown. */
    private function load(): ApplicationTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'doctrine:fixtures:load', '--append' => true], ['interactive' => false]);

        return $tester;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function row(string $sql, array $parameters): array
    {
        $row = $this->em()->getConnection()->fetchAssociative($sql, $parameters);
        self::assertIsArray($row, $sql);

        return $row;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private function column(string $sql, array $parameters): array
    {
        return $this->em()->getConnection()->fetchFirstColumn($sql, $parameters);
    }

    /** @param list<mixed> $parameters */
    private function numberOf(string $sql, array $parameters = []): int
    {
        $value = $this->em()->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($value, $sql);

        return (int) $value;
    }
}

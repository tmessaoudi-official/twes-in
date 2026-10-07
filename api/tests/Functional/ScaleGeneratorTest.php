<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\Scale\Rows;
use App\DataFixtures\Scale\ScaleGenerator;
use App\DataFixtures\Scale\ScaleInvariants;
use App\Tenancy\Domain\NumberingSeries;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\Uuid;

/**
 * The large-data generator (docs/SPEC.md § 7, 2026-09-27, row 181): it grows the demo company by cloning its
 * invoice graph in SQL, so what it writes must still be a state the application can produce. Each case here is one
 * way a clone could be wrong and no constraint would say so: a row pointing into another company satisfies every
 * foreign key, a number out of date order satisfies the unique index, a snapshot naming the base customer satisfies
 * every column. The demo dataset is the base, loaded the way DemoFixturesTest loads it.
 */
final class ScaleGeneratorTest extends ApiTestCase
{
    private const string COMPANY = 'Carthage Conseil';

    public function testTheCompanyGrowsToTheTargetAndNothingPointsIntoAnotherCompany(): void
    {
        $base = $this->baseThenGenerate(300);

        self::assertGreaterThanOrEqual($base + 300, $this->invoices());
        self::assertSame([], ScaleInvariants::crossCompanyReferences($this->connection()), 'every reference of every row stays inside one company');
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM invoice i JOIN customer c ON c.id = i.customer_id WHERE i.customer_snapshot IS NOT NULL AND i.customer_snapshot->>'name' <> c.name"), 'an invoice\'s snapshot names its own customer, not the base one');
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM invoice i WHERE i.amount_paid <> (SELECT COALESCE(SUM(p.amount), 0) FROM payment p WHERE p.invoice_id = i.id)'), 'what an invoice says it was paid is its payments');
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM invoice WHERE company_id = (SELECT id FROM company WHERE name = ?) AND document_type = 'credit_note' AND corrects_invoice_id IS NULL", [self::COMPANY]));
        self::assertGreaterThan(0, $this->number('SELECT COUNT(*) FROM invoice WHERE pdf_file_id IS NULL AND number IS NOT NULL AND status <> \'draft\''), 'a clone has no stored PDF: it is rendered on first request, a state the application already produces');
    }

    public function testTheDetectorSeesARowPointingIntoAnotherCompany(): void
    {
        $this->baseThenGenerate(60);
        $foreign = $this->text('SELECT c.id FROM customer c JOIN company o ON o.id = c.company_id WHERE o.name <> ? ORDER BY c.id LIMIT 1', [self::COMPANY]);
        $this->connection()->executeStatement('UPDATE invoice SET customer_id = ? WHERE id = (SELECT id FROM invoice WHERE company_id = (SELECT id FROM company WHERE name = ?) LIMIT 1)', [$foreign, self::COMPANY]);

        $found = ScaleInvariants::crossCompanyReferences($this->connection());

        self::assertContains('invoice.customer_id', array_map(static fn (array $hit): string => $hit['table'].'.'.$hit['column'], $found));
    }

    public function testNumbersFollowTheIssueDatesAndTheSeriesResumesAfterThem(): void
    {
        $this->baseThenGenerate(300);

        self::assertSame(0, $this->number("SELECT COUNT(*) FROM invoice WHERE number LIKE '~%'"), 'no placeholder number is left');
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM invoice WHERE status IN ('issued', 'partially_paid', 'paid') AND number IS NULL"), 'every issued document is numbered');
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM invoice WHERE status = 'draft' AND number IS NOT NULL"), 'a draft stays unnumbered');
        $rows = $this->connection()->fetchAllAssociative('SELECT company_id, establishment_id, document_type, EXTRACT(YEAR FROM issue_date)::int AS year, number, issue_date FROM invoice WHERE number IS NOT NULL ORDER BY company_id, establishment_id, document_type, number');
        $previous = null;
        foreach ($rows as $row) {
            // One series is one company's establishment and document type: another's numbers are its own.
            if (null !== $previous && [$previous['company_id'], $previous['establishment_id'], $previous['document_type'], $previous['year']] === [$row['company_id'], $row['establishment_id'], $row['document_type'], $row['year']]) {
                self::assertLessThanOrEqual($row['issue_date'], $previous['issue_date'], \sprintf('number %s follows the issue dates within %s', Rows::text($row['number']), Rows::text($row['year'])));
            }
            $previous = $row;
        }
        // The next real issue must not collide: the series stands on the last period it numbered, and the number it
        // would print next is unused.
        foreach ($this->connection()->fetchAllAssociative('SELECT s.id, s.establishment_id, s.document_type, s.next_number, s.format, s.last_reset_year FROM numbering_series s WHERE s.is_default') as $series) {
            $issued = $this->number('SELECT COUNT(*) FROM invoice WHERE establishment_id = ? AND document_type = ? AND number IS NOT NULL', [$series['establishment_id'], $series['document_type']]);
            if (0 === $issued) {
                continue;
            }
            self::assertSame(
                $this->number('SELECT MAX(EXTRACT(YEAR FROM issue_date))::int FROM invoice WHERE establishment_id = ? AND document_type = ? AND number IS NOT NULL', [$series['establishment_id'], $series['document_type']]),
                Rows::int($series['last_reset_year']),
                'the series stands on the year of its last number',
            );
            self::assertGreaterThan(0, Rows::int($series['next_number']));
        }
    }

    public function testAnInterruptedRunResumesAndAFinishedOneAddsNothing(): void
    {
        $company = $this->baseThenGenerate(0);
        $target = $company + 200;

        $first = $this->generate($target, ['--chunk-copies' => 5, '--max-chunks' => 1]);
        self::assertStringContainsString('stopped', $first->getDisplay(), 'a bounded run says it stopped before the target');
        $partial = $this->invoices();
        self::assertLessThan($target, $partial);

        $second = $this->generate($target, ['--chunk-copies' => 5]);
        self::assertSame(0, $second->getStatusCode(), $second->getDisplay());
        $done = $this->invoices();
        self::assertGreaterThanOrEqual($target, $done);
        self::assertSame([], ScaleInvariants::crossCompanyReferences($this->connection()));

        $third = $this->generate($target, ['--chunk-copies' => 5]);
        self::assertSame(0, $third->getStatusCode(), $third->getDisplay());
        self::assertSame($done, $this->invoices(), 'a run that has nothing to add adds nothing');
    }

    public function testTheTargetCanGrowAndTheNumbersAreRenumberedInDateOrder(): void
    {
        $base = $this->baseThenGenerate(100);
        $small = $this->invoices();

        $this->generate($base + 300);

        self::assertGreaterThan($small, $this->invoices());
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM invoice WHERE number LIKE '~%'"));
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM (SELECT number FROM invoice WHERE number IS NOT NULL GROUP BY company_id, document_type, number HAVING COUNT(*) > 1) d'));
    }

    public function testASeriesThePassDoesNotRenumberKeepsCountingAfterItsDocuments(): void
    {
        $base = $this->loadDemo();
        $untouched = $this->seriesWithNoNumberedInvoice();
        self::assertNotSame([], $untouched, 'the demo numbers documents that are not invoices (delivery notes), or this proves nothing');

        $tester = $this->generate($base + 100);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($untouched, $this->seriesWithNoNumberedInvoice(), 'a company\'s delivery notes keep their numbers, so their series must not start again');
    }

    public function testTheNumberASeriesPrintsNextIsNotAlreadyInUse(): void
    {
        $this->baseThenGenerate(200);

        $checked = 0;
        foreach ($this->connection()->fetchAllAssociative('SELECT s.id, s.establishment_id, s.document_type FROM numbering_series s WHERE s.is_default AND EXISTS (SELECT 1 FROM invoice i WHERE i.establishment_id = s.establishment_id AND i.document_type = s.document_type AND i.number IS NOT NULL)') as $one) {
            $series = $this->em()->find(NumberingSeries::class, Uuid::fromString(Rows::text($one['id']))) ?? throw new \LogicException('A series vanished.');
            $last = $this->text('SELECT MAX(issue_date) FROM invoice WHERE establishment_id = ? AND document_type = ? AND number IS NOT NULL', [$one['establishment_id'], $one['document_type']]);
            $next = $series->preview(new \DateTimeImmutable($last));

            self::assertSame(0, $this->number('SELECT COUNT(*) FROM invoice WHERE establishment_id = ? AND document_type = ? AND number = ?', [$one['establishment_id'], $one['document_type'], $next]), \sprintf('the next %s number, %s, is already taken', Rows::text($one['document_type']), $next));
            ++$checked;
        }
        self::assertGreaterThan(1, $checked, 'invoices and credit notes each have a series');
    }

    public function testARenumberedInvoiceKeepsNoStoredPdfThatPrintsItsOldNumber(): void
    {
        $base = $this->loadDemo();
        $others = 'SELECT COUNT(*) FROM invoice WHERE pdf_file_id IS NOT NULL AND number IS NOT NULL AND company_id <> (SELECT id FROM company WHERE name = ?)';
        $ours = 'SELECT COUNT(*) FROM invoice WHERE pdf_file_id IS NOT NULL AND number IS NOT NULL AND company_id = (SELECT id FROM company WHERE name = ?)';
        self::assertGreaterThan(0, $this->number($ours, [self::COMPANY]), 'the demo stores PDFs of its issued invoices, or this proves nothing');
        $untouched = $this->number($others, [self::COMPANY]);

        $tester = $this->generate($base + 100);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(0, $this->number($ours, [self::COMPANY]), 'a stored PDF prints the old number: it goes, and the first request renders the new one');
        self::assertSame($untouched, $this->number($others, [self::COMPANY]), 'another company\'s PDFs still print their own numbers');
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM invoice WHERE pdf_file_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM file f WHERE f.id = invoice.pdf_file_id)'));
    }

    public function testItRefusesADatabaseThatIsNotAScaleOrTestOne(): void
    {
        foreach (['twes', 'twes_prod', 'production', 'twes_w3', 'twes_test_prod', 'twes_testimonials'] as $name) {
            try {
                ScaleGenerator::refuseUnlessScaleDatabase($name);
                self::fail("$name must be refused");
            } catch (\RuntimeException $refusal) {
                self::assertStringContainsString($name, $refusal->getMessage());
            }
        }
        ScaleGenerator::refuseUnlessScaleDatabase('twes_scale');
        ScaleGenerator::refuseUnlessScaleDatabase('twes_test');
        ScaleGenerator::refuseUnlessScaleDatabase('twes_test4');
        // A parallel writer's own test database, beside the one the main tree tests on.
        ScaleGenerator::refuseUnlessScaleDatabase('twes_test_w3');
        ScaleGenerator::refuseUnlessScaleDatabase('twes_test_w2');
    }

    public function testEveryTableThatReachesTheInvoiceGraphIsClonedSharedOrNamedAsLeftOut(): void
    {
        self::assertSame([], ScaleGenerator::unaccounted($this->connection()), 'a table added since the generator was written is neither cloned, shared nor excluded');
    }

    private function baseThenGenerate(int $more): int
    {
        $base = $this->loadDemo();
        $tester = $this->generate($base + $more);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $base;
    }

    /** Loads the demo companies and returns the invoice rows the grown company starts with. */
    private function loadDemo(): int
    {
        $this->createUser('operator@twes.local', 'operator-secret', operator: true);
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $application->setAutoExit(false);
        $loaded = new ApplicationTester($application);
        $loaded->run(['command' => 'doctrine:fixtures:load', '--append' => true], ['interactive' => false]);
        self::assertSame(0, $loaded->getStatusCode(), $loaded->getDisplay());

        return $this->invoices();
    }

    /** @param array<string, mixed> $options */
    private function generate(int $target, array $options = []): ApplicationTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'app:scale:generate', '--company' => self::COMPANY, '--invoices' => $target, '--years' => 10, '--seed' => 1, ...$options], ['interactive' => false]);

        return $tester;
    }

    /** @return list<array<string, mixed>> the default series whose document type no invoice row numbers: what a pass must leave as it is */
    private function seriesWithNoNumberedInvoice(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT s.id, s.document_type, s.next_number, s.last_reset_year, s.last_reset_month FROM numbering_series s WHERE s.is_default AND NOT EXISTS (SELECT 1 FROM invoice i WHERE i.establishment_id = s.establishment_id AND i.document_type = s.document_type AND i.number IS NOT NULL) ORDER BY s.id');
    }

    private function invoices(): int
    {
        return $this->number('SELECT COUNT(*) FROM invoice WHERE company_id = (SELECT id FROM company WHERE name = ?)', [self::COMPANY]);
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    /** @param list<mixed> $parameters */
    private function number(string $sql, array $parameters = []): int
    {
        $value = $this->connection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($value, $sql);

        return (int) $value;
    }

    /** @param list<mixed> $parameters */
    private function text(string $sql, array $parameters = []): string
    {
        $value = $this->connection()->fetchOne($sql, $parameters);
        self::assertIsString($value, $sql);

        return $value;
    }
}

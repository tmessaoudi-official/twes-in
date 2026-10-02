<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures\Scale;

use App\Tenancy\Domain\NumberingSeries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Grows one company to a target number of invoices by cloning its invoice graph in SQL, so a large database exists in
 * minutes and every row is still a state the application can produce (docs/SPEC.md § 7, 2026-09-27, row 181).
 *
 * The company's own documents, made through the use cases, are the BASE graph: its invoices with their lines, taxes,
 * payments and credit notes, and the customers they name. A COPY is one dated instance of that graph. Copy `c` moves
 * every date back by a number of days drawn from the seed and `c`, gets new ids derived from `c`, and points at a
 * customer set of its own group of ten copies. Every scalar column is copied as it is; only the ones named in
 * `clone*` below change, and `unaccounted()` fails when a foreign key of a cloned table is neither remapped nor aimed
 * at shared reference data, so a column added later cannot be silently copied with a stale reference.
 *
 * Three properties the run keeps, none of which a constraint states:
 *  - a clone's ids are derived from the base id and the copy number, and carry UUID version 8 where the application's
 *    own are version 7: a re-run inserts nothing twice, and a clone is told from a base row by its id alone;
 *  - the generation is chunked and resumable, its progress in the `scale` schema next to the data (never a migration:
 *    production has no such schema), so one run fits the time this machine allows a command;
 *  - numbers are assigned last, in one pass over the company in issue-date order through the real
 *    `NumberingSeries::allocate`, so they follow the dates and the series stands where the next real issue continues.
 *
 * Left out of this slice and named in LEFT_OUT: expenses, stock movements, audit rows, delivery notes, contacts, credit
 * balances and files. They keep their base rows and do not grow.
 */
#[When('dev')]
#[When('test')]
final class ScaleGenerator
{
    /** The tables a copy writes. */
    public const array CLONED = ['customer', 'invoice', 'invoice_line', 'invoice_tax', 'invoice_line_tax', 'payment'];

    /** Reference data a clone points at and never copies: same company, same rows. */
    public const array SHARED = [
        'establishment', 'numbering_series', 'tax_component', 'unit', 'product', 'product_category', 'product_barcode',
        'product_home_location', 'product_reorder_point', 'customer_group', 'customer_tax_regime', 'custom_field_definition',
        'setting', 'role', 'membership', 'stock_location', 'stock_lot', 'vendor', 'expense_category', 'subscription',
        'module_state', 'module_interest', 'invitation', 'inbox_item', 'scan_pairing', 'venue_area', 'venue_spot', 'venue_structure',
    ];

    /** Company data this slice does not grow: table => why. */
    public const array LEFT_OUT = [
        'attachment' => 'files attached to documents are not cloned',
        'audit_log' => 'the audit trail of a clone is not written; a follow-up slice grows it',
        'contact' => 'people of a customer: a follow-up slice',
        'customer_credit_entry' => 'what a customer has to their credit: a clone starts with none',
        'delivery_note' => 'delivery notes: a follow-up slice',
        'delivery_note_line' => 'delivery notes: a follow-up slice',
        'delivery_note_line_tax' => 'delivery notes: a follow-up slice',
        'expense' => 'expenses: a follow-up slice',
        'file' => 'stored files, PDFs included: a clone has none and renders on first request',
        'payment_declaration' => 'a customer\'s declared payments: a follow-up slice',
        'stock_movement' => 'stock movements: a follow-up slice',
    ];

    /** Foreign keys of a cloned table that a copy points at a row of its own copy, or empties: `table.column`. */
    private const array REMAPPED = [
        'invoice.corrects_invoice_id', 'invoice.customer_id', 'invoice.pdf_file_id',
        'invoice_line.invoice_id', 'invoice_line_tax.line_id', 'invoice_tax.invoice_id', 'payment.invoice_id',
    ];

    /** Copies that share one set of customers. */
    private const int CUSTOMER_SET = 10;

    /** Documents numbered, and rows written, per transaction. */
    private const int NUMBERING_CHUNK = 5000;

    private const array WORDS_A = ['Atlas', 'Nord', 'Sud', 'Levant', 'Cèdre', 'Olivier', 'Horizon', 'Delta', 'Phénix', 'Sahel', 'Rivage', 'Zenith'];

    private const array WORDS_B = ['Négoce', 'Distribution', 'Matériaux', 'Équipements', 'Services', 'Logistique', 'Industrie', 'Conseil', 'Textile', 'Import', 'Bâtiment', 'Agro'];

    private const array FORMS = ['SARL', 'SA', '& Fils', 'Frères', 'Group', 'SAS'];

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The generator writes freely, so it works only on a database that says what it is for: a `_scale` one, or the
     * test one, which the suite rebuilds at will. The development data is never grown by mistake.
     */
    public static function refuseUnlessScaleDatabase(string $name): void
    {
        if (1 !== preg_match('/_(scale|test\d*)$/', $name)) {
            throw new \RuntimeException(\sprintf('The database "%s" is not a scale or test one, and the generator writes freely: name it twes_scale and point DATABASE_URL at it.', $name));
        }
    }

    /**
     * Tables and foreign keys the generator has not been told about: a company-scoped table, or one pointing at a
     * cloned table, that is neither cloned, shared nor left out; and a foreign key of a cloned table that is neither
     * remapped nor aimed at shared data.
     *
     * @return list<string>
     */
    public static function unaccounted(Connection $connection): array
    {
        $known = [...self::CLONED, ...self::SHARED, ...array_keys(self::LEFT_OUT), 'company'];
        $scoped = Rows::texts($connection->fetchFirstColumn("SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'company_id'"));
        $referencing = Rows::texts($connection->fetchFirstColumn(
            "SELECT DISTINCT t.relname::text FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_class r ON r.oid = c.confrelid WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace AND r.relname = ANY (string_to_array(?, ','))",
            [implode(',', self::CLONED)],
        ));

        $out = [];
        foreach (array_unique([...$scoped, ...$referencing]) as $table) {
            if (!\in_array($table, $known, true)) {
                $out[] = \sprintf('%s is neither cloned, shared nor left out', $table);
            }
        }
        $keys = $connection->fetchAllAssociative(
            "SELECT t.relname::text AS \"table\", a.attname::text AS \"column\", r.relname::text AS target FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_class r ON r.oid = c.confrelid JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1] WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace AND t.relname = ANY (string_to_array(?, ',')) ORDER BY 1, 2",
            [implode(',', self::CLONED)],
        );
        foreach ($keys as $key) {
            $name = Rows::text($key['table']).'.'.Rows::text($key['column']);
            $target = Rows::text($key['target']);
            if (!\in_array($name, self::REMAPPED, true) && !\in_array($target, [...self::SHARED, 'company'], true)) {
                $out[] = \sprintf('%s points at %s, which a copy neither remaps nor shares', $name, $target);
            }
        }
        foreach (self::CLONED as $table) {
            if (0 === Rows::int($connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?", [$table]))) {
                $out[] = \sprintf('%s no longer exists, and the generator still clones it', $table);
            }
        }

        return $out;
    }

    /**
     * @param ?int                   $maxChunks stop after this many chunks of copies (a bounded run, or a test of resuming)
     * @param ?callable(string):void $say       progress, one line at a time
     */
    public function generate(string $companyName, int $targetInvoices, int $years, int $seed, int $chunkCopies = 2000, ?int $maxChunks = null, ?callable $say = null): ScaleReport
    {
        self::refuseUnlessScaleDatabase($this->connection->getDatabase() ?? '');
        if ($chunkCopies < 1 || $years < 1) {
            throw new \InvalidArgumentException('Copies per chunk and years are at least one.');
        }
        $say ??= static function (string $line): void {
        };
        $this->prepareSchema();

        $company = $this->connection->fetchOne('SELECT id FROM company WHERE name = ?', [$companyName]);
        if (!\is_string($company)) {
            throw new \RuntimeException(\sprintf('No company is called "%s": load the demo companies first (make fixtures).', $companyName));
        }
        $base = Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM invoice WHERE company_id = ? AND substr(id::text, 15, 1) <> \'8\'', [$company]));
        if (0 === $base) {
            throw new \RuntimeException(\sprintf('"%s" has no invoices to clone.', $companyName));
        }
        $wanted = $targetInvoices <= $base ? 0 : intdiv($targetInvoices - $base + $base - 1, $base);

        $this->connection->executeStatement('INSERT INTO scale.run (company_id, seed, years) VALUES (?, ?, ?) ON CONFLICT (company_id) DO NOTHING', [$company, $seed, $years]);
        $run = $this->connection->fetchAssociative('SELECT seed, years, copies_done FROM scale.run WHERE company_id = ?', [$company]) ?: throw new \LogicException('The run row just written is missing.');
        $ranSeed = Rows::int($run['seed']);
        $ranYears = Rows::int($run['years']);
        if ($ranSeed !== $seed || $ranYears !== $years) {
            throw new \RuntimeException(\sprintf('This company was generated with seed %d over %d years: a different seed or span needs a fresh database (drop it and run again).', $ranSeed, $ranYears));
        }

        $done = Rows::int($run['copies_done']);
        $chunks = 0;
        while ($done < $wanted) {
            if (null !== $maxChunks && $chunks >= $maxChunks) {
                $say(\sprintf('stopped at %d of %d copies', $done, $wanted));

                return new ScaleReport($done, $wanted, $this->invoices($company), true);
            }
            $end = min($done + $chunkCopies, $wanted);
            $this->connection->transactional(function () use ($company, $done, $end, $wanted, $years, $seed): void {
                $this->cloneChunk($company, $done, $end, $years, $seed);
                $this->connection->executeStatement(
                    'UPDATE scale.run SET copies_done = ?, phase = ? WHERE company_id = ?',
                    [$end, $end >= $wanted ? 'cloned' : 'cloning', $company],
                );
            });
            $done = $end;
            ++$chunks;
            $say(\sprintf('copies %d of %d', $done, $wanted));
        }

        if ('numbered' !== $this->connection->fetchOne('SELECT phase FROM scale.run WHERE company_id = ?', [$company])) {
            $this->renumber($company, $say);
        }

        return new ScaleReport($done, $wanted, $this->invoices($company), false);
    }

    private function prepareSchema(): void
    {
        $this->connection->executeStatement('CREATE SCHEMA IF NOT EXISTS scale');
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS scale.run (
                company_id uuid PRIMARY KEY,
                seed integer NOT NULL,
                years integer NOT NULL,
                copies_done integer NOT NULL DEFAULT 0,
                phase text NOT NULL DEFAULT 'cloned' CHECK (phase IN ('cloning', 'cloned', 'numbering', 'numbered'))
            )
            SQL);
        // A clone's id: the base id and the copy number hashed into a UUID whose version nibble is 8 (RFC 9562's
        // custom version), where the application's own ids are version 7. STRICT, so an absent parent stays absent.
        $this->connection->executeStatement(<<<'SQL'
            CREATE OR REPLACE FUNCTION scale.derived_id(base uuid, copy integer) RETURNS uuid
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
                SELECT (substr(h, 1, 8) || '-' || substr(h, 9, 4) || '-8' || substr(h, 14, 3) || '-8' || substr(h, 18, 3) || '-' || substr(h, 21, 12))::uuid
                FROM (SELECT md5(base::text || ':' || copy::text) AS h) x
            $$
            SQL);
    }

    private function cloneChunk(string $company, int $from, int $to, int $years, int $seed): void
    {
        $span = $years * 365;
        $firstSet = intdiv($from, self::CUSTOMER_SET);
        $lastSet = intdiv($to - 1, self::CUSTOMER_SET);
        $set = self::CUSTOMER_SET;
        $copies = \sprintf(
            "(SELECT c, 1 + (('x' || substr(md5(%d::text || ':' || c::text), 1, 7))::bit(28)::int %% %d) AS d FROM generate_series(%d, %d) AS c) x",
            $seed,
            $span,
            $from,
            $to - 1,
        );
        $isBase = "substr(src.id::text, 15, 1) <> '8'";
        $days = static fn (string $column): string => "src.$column - x.d";
        $back = static fn (string $column): string => "src.$column - make_interval(days => x.d)";
        $derived = static fn (string $column): string => "scale.derived_id(src.$column, x.c)";

        // Customers first: an invoice names one, and its snapshot must carry that customer's name.
        $hash = \sprintf("('x' || substr(md5(%d::text || src.id::text || grp::text), 1, 7))::bit(28)::int", $seed);
        $name = \sprintf(
            '(%s)[1 + (%s) %% %d] || \' \' || (%s)[1 + ((%s) / %d) %% %d] || \' \' || (%s)[1 + ((%s) / %d) %% %d]',
            self::sqlArray(self::WORDS_A),
            $hash,
            \count(self::WORDS_A),
            self::sqlArray(self::WORDS_B),
            $hash,
            \count(self::WORDS_A),
            \count(self::WORDS_B),
            self::sqlArray(self::FORMS),
            $hash,
            \count(self::WORDS_A) * \count(self::WORDS_B),
            \count(self::FORMS),
        );
        $this->insert(
            'customer',
            "FROM customer src CROSS JOIN generate_series($firstSet, $lastSet) AS grp WHERE src.company_id = :company AND $isBase AND EXISTS (SELECT 1 FROM invoice i WHERE i.customer_id = src.id)",
            [
                'id' => 'scale.derived_id(src.id, grp)',
                'number' => "src.number || '-' || (grp + 1)",
                'name' => $name,
                'legal_name' => "CASE WHEN src.legal_name IS NULL THEN NULL ELSE $name END",
                'email' => "CASE WHEN src.email IS NULL THEN NULL ELSE 'g' || grp || '.' || src.email END",
                'created_at' => "src.created_at - make_interval(days => $span + 1)",
                'updated_at' => "src.updated_at - make_interval(days => $span + 1)",
            ],
            ['company' => $company],
        );

        $this->insert(
            'invoice',
            "FROM invoice src CROSS JOIN $copies JOIN customer nc ON nc.id = scale.derived_id(src.customer_id, x.c / $set) WHERE src.company_id = :company AND $isBase",
            [
                'id' => $derived('id'),
                'corrects_invoice_id' => $derived('corrects_invoice_id'),
                'customer_id' => 'nc.id',
                // A placeholder, so the unique index has something to hold; the real number is assigned last.
                'number' => "CASE WHEN src.number IS NULL THEN NULL ELSE '~' || scale.derived_id(src.id, x.c)::text END",
                'issue_date' => $days('issue_date'),
                'supply_date' => $days('supply_date'),
                'due_date' => $days('due_date'),
                'created_at' => $back('created_at'),
                'updated_at' => $back('updated_at'),
                'issued_at' => $back('issued_at'),
                // Not stored: the first request renders it, which is what PrintInvoice does for an issued document without one.
                'pdf_file_id' => 'NULL',
                'customer_snapshot' => "CASE WHEN src.customer_snapshot IS NULL THEN NULL ELSE jsonb_set(jsonb_set(src.customer_snapshot, '{name}', to_jsonb(nc.name)), '{email}', COALESCE(to_jsonb(nc.email), 'null'::jsonb)) END",
            ],
            ['company' => $company],
        );

        $this->insert(
            'invoice_line',
            "FROM invoice_line src CROSS JOIN $copies WHERE src.company_id = :company AND $isBase",
            ['id' => $derived('id'), 'invoice_id' => $derived('invoice_id'), 'source_delivery_note_line_id' => 'NULL'],
            ['company' => $company],
        );
        $this->insert(
            'invoice_tax',
            "FROM invoice_tax src CROSS JOIN $copies WHERE src.company_id = :company AND $isBase",
            ['id' => $derived('id'), 'invoice_id' => $derived('invoice_id')],
            ['company' => $company],
        );
        $this->insert(
            'invoice_line_tax',
            "FROM invoice_line_tax src CROSS JOIN $copies WHERE src.company_id = :company AND $isBase",
            ['id' => $derived('id'), 'line_id' => $derived('line_id')],
            ['company' => $company],
        );
        $this->insert(
            'payment',
            "FROM payment src CROSS JOIN $copies WHERE src.company_id = :company AND $isBase",
            ['id' => $derived('id'), 'invoice_id' => $derived('invoice_id'), 'payment_date' => $days('payment_date'), 'created_at' => $back('created_at')],
            ['company' => $company],
        );
    }

    /**
     * Copy every column of `$table` from `src` except the ones overridden, so a column added later is carried with
     * its value instead of dropped to its default.
     *
     * @param array<string, string> $overrides  column => SQL expression
     * @param array<string, string> $parameters
     */
    private function insert(string $table, string $from, array $overrides, array $parameters): void
    {
        $columns = Rows::texts($this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position", [$table]));
        $unknown = array_diff(array_keys($overrides), $columns);
        if ([] !== $unknown) {
            throw new \LogicException(\sprintf('%s has no column %s: the generator is out of date with the schema.', $table, implode(', ', $unknown)));
        }
        $select = implode(', ', array_map(static fn (string $column): string => $overrides[$column] ?? 'src."'.$column.'"', $columns));
        $names = implode(', ', array_map(static fn (string $column): string => '"'.$column.'"', $columns));
        $this->connection->executeStatement(\sprintf('INSERT INTO "%s" (%s) SELECT %s %s ON CONFLICT (id) DO NOTHING', $table, $names, $select, $from), $parameters);
    }

    /**
     * Numbers every numbered document of the company in issue-date order through the real series, after moving the
     * existing numbers out of the way (a placeholder is unique and never a real number), and leaves each series on
     * the period of its last number. Resumable: a placeholder still present is a document not yet numbered.
     *
     * @param callable(string):void $say
     */
    private function renumber(string $company, callable $say): void
    {
        $phase = $this->connection->fetchOne('SELECT phase FROM scale.run WHERE company_id = ?', [$company]);
        if ('numbering' !== $phase) {
            $this->connection->transactional(function () use ($company): void {
                $this->connection->executeStatement("UPDATE invoice SET number = '~' || id::text WHERE company_id = ? AND number IS NOT NULL AND number NOT LIKE '~%'", [$company]);
                // Only the series this pass renumbers start again: a company's delivery notes, say, keep their numbers, so
                // their series must keep counting after them or the next one printed repeats the first.
                $this->connection->executeStatement(
                    'UPDATE numbering_series s SET next_number = 1, last_reset_year = NULL, last_reset_month = NULL WHERE s.company_id = ? AND s.is_default AND EXISTS (SELECT 1 FROM invoice i WHERE i.company_id = s.company_id AND i.establishment_id = s.establishment_id AND i.document_type = s.document_type AND i.number IS NOT NULL)',
                    [$company],
                );
                // A stored PDF prints the number its invoice had: it goes, and the first request renders the new one.
                $files = Rows::texts($this->connection->fetchFirstColumn('SELECT DISTINCT pdf_file_id FROM invoice WHERE company_id = ? AND number IS NOT NULL AND pdf_file_id IS NOT NULL', [$company]));
                $this->connection->executeStatement('UPDATE invoice SET pdf_file_id = NULL WHERE company_id = ? AND number IS NOT NULL AND pdf_file_id IS NOT NULL', [$company]);
                if ([] !== $files) {
                    $this->connection->executeStatement(
                        'DELETE FROM file f WHERE f.id = ANY (?::uuid[]) AND NOT EXISTS (SELECT 1 FROM attachment a WHERE a.file_id = f.id) AND NOT EXISTS (SELECT 1 FROM delivery_note d WHERE d.pdf_file_id = f.id)',
                        [self::pgArray($files)],
                    );
                }
                $this->connection->executeStatement("UPDATE scale.run SET phase = 'numbering' WHERE company_id = ?", [$company]);
            });
        }

        $series = $this->connection->fetchAllAssociative("SELECT DISTINCT i.establishment_id, i.document_type FROM invoice i WHERE i.company_id = ? AND i.number LIKE '~%' ORDER BY 1, 2", [$company]);
        $numbered = 0;
        foreach ($series as $one) {
            $seriesId = $this->connection->fetchOne('SELECT id FROM numbering_series WHERE establishment_id = ? AND document_type = ? AND is_default', [$one['establishment_id'], $one['document_type']]);
            if (!\is_string($seriesId)) {
                throw new \RuntimeException(\sprintf('No default series numbers %s for an establishment of this company.', Rows::text($one['document_type'])));
            }
            while (true) {
                $rows = $this->connection->fetchAllAssociative(
                    "SELECT id, issue_date FROM invoice WHERE company_id = ? AND establishment_id = ? AND document_type = ? AND number LIKE '~%' ORDER BY issue_date, created_at, id LIMIT ".self::NUMBERING_CHUNK,
                    [$company, $one['establishment_id'], $one['document_type']],
                );
                if ([] === $rows) {
                    break;
                }
                $this->connection->transactional(function () use ($rows, $seriesId): void {
                    $this->entityManager->clear();
                    $series = $this->entityManager->find(NumberingSeries::class, Uuid::fromString($seriesId)) ?? throw new \LogicException('The series vanished.');
                    $now = $this->clock->now();
                    $ids = [];
                    $numbers = [];
                    foreach ($rows as $row) {
                        $ids[] = Rows::text($row['id']);
                        $numbers[] = $series->allocate(new \DateTimeImmutable(Rows::text($row['issue_date'])), $now);
                    }
                    $this->entityManager->flush();
                    $this->connection->executeStatement(
                        'UPDATE invoice i SET number = v.number FROM (SELECT unnest(?::uuid[]) AS id, unnest(?::text[]) AS number) v WHERE i.id = v.id',
                        [self::pgArray($ids), self::pgArray($numbers)],
                    );
                });
                $numbered += \count($rows);
                $say(\sprintf('numbered %d documents', $numbered));
            }
        }
        $this->entityManager->clear();
        $this->connection->executeStatement("UPDATE scale.run SET phase = 'numbered' WHERE company_id = ?", [$company]);
    }

    private function invoices(string $company): int
    {
        return Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM invoice WHERE company_id = ?', [$company]));
    }

    /** @param list<string> $values */
    private static function sqlArray(array $values): string
    {
        return 'ARRAY['.implode(', ', array_map(static fn (string $value): string => "'".str_replace("'", "''", $value)."'", $values)).']';
    }

    /** @param list<string> $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $values)).'}';
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A product carries several codes, in a table of their own (docs/SPEC.md § 7, 2026-09-22 11:05 and 22:26).
 *
 * The single `product.barcode` column becomes each product's first row, a `unit` code of one piece, with its match key:
 * a GTIN right-justified on fourteen digits, anything else as printed. The key is worked out here rather than by the
 * application's `Barcode`, so this migration means the same thing whatever that class becomes. Two products holding
 * two spellings of one GTIN (a UPC and its EAN-13) would collide on the new key; that aborts the migration naming both,
 * because choosing which product keeps the code is not a migration's to decide.
 *
 * Dropping the column also drops `idx_product_search`, an index on an expression over it, so the index is built again
 * on the reference and the name: a code is found whole through the new unique key, never as part of the words.
 */
final class Version20260922210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Products carry several barcodes, each with its role, quantity and supplier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_barcode (id UUID NOT NULL, role VARCHAR(16) NOT NULL, code VARCHAR(64) NOT NULL, match_key VARCHAR(64) NOT NULL, quantity INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, supplier_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_product_barcode_product ON product_barcode (product_id)');
        $this->addSql('CREATE INDEX idx_product_barcode_supplier ON product_barcode (supplier_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_barcode_key ON product_barcode (company_id, match_key)');
        $this->addSql('CREATE INDEX IDX_467719D6979B1AD6 ON product_barcode (company_id)');
        $this->addSql('ALTER TABLE product_barcode ADD CONSTRAINT FK_467719D6979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_barcode ADD CONSTRAINT FK_467719D64584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_barcode ADD CONSTRAINT FK_467719D62ADD6D8C FOREIGN KEY (supplier_id) REFERENCES vendor (id) NOT DEFERRABLE');
        // The roles the application knows, and the quantities each allows: a database the application did not write
        // keeps the same rules.
        $this->addSql("ALTER TABLE product_barcode ADD CONSTRAINT product_barcode_role CHECK (role IN ('unit', 'pack', 'supplier', 'internal'))");
        $this->addSql("ALTER TABLE product_barcode ADD CONSTRAINT product_barcode_quantity CHECK (quantity BETWEEN 1 AND 1000000 AND (role <> 'unit' OR quantity = 1) AND (role <> 'pack' OR quantity > 1))");
        $this->addSql("ALTER TABLE product_barcode ADD CONSTRAINT product_barcode_supplier CHECK ((role = 'supplier') = (supplier_id IS NOT NULL))");

        $held = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, company_id, reference, barcode, created_at FROM product WHERE barcode IS NOT NULL ORDER BY reference') as $row) {
            $key = self::keyOf((string) $row['barcode']);
            $taken = $held[$row['company_id']][$key] ?? null;
            $this->abortIf(null !== $taken, \sprintf('Products %s and %s hold two spellings of one code (%s); keep it on one of them first.', $taken, $row['reference'], $row['barcode']));
            $held[$row['company_id']][$key] = $row['reference'];
            $this->addSql(
                "INSERT INTO product_barcode (id, company_id, product_id, role, code, match_key, quantity, created_at) VALUES (gen_random_uuid(), :company, :product, 'unit', :code, :key, 1, :at)",
                ['company' => $row['company_id'], 'product' => $row['id'], 'code' => $row['barcode'], 'key' => $key, 'at' => $row['created_at']],
            );
        }

        $this->addSql('DROP INDEX uniq_product_barcode');
        $this->addSql('DROP INDEX idx_product_search');
        $this->addSql('ALTER TABLE product DROP barcode');
        $this->addSql('CREATE INDEX idx_product_search ON product USING gin (search_text(reference, name) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_search');
        $this->addSql('ALTER TABLE product ADD barcode VARCHAR(64) DEFAULT NULL');
        // Back to one code a product: its unit code, else the first of the rest. The others are lost, as the column
        // cannot hold them — which is why this goes one way in any database that has written a second code.
        $this->addSql(<<<'SQL'
            UPDATE product p SET barcode = b.code FROM (
                SELECT DISTINCT ON (product_id) product_id, code FROM product_barcode
                ORDER BY product_id, CASE role WHEN 'unit' THEN 0 WHEN 'pack' THEN 1 WHEN 'supplier' THEN 2 ELSE 3 END, code
            ) b WHERE b.product_id = p.id
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_product_barcode ON product (company_id, barcode) WHERE (barcode IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_product_search ON product USING gin (search_text(reference, name, barcode) gin_trgm_ops)');
        $this->addSql('DROP TABLE product_barcode');
    }

    /** A GTIN — 8, 12, 13 or 14 digits whose check digit holds — right-justified on fourteen; anything else as printed. */
    private static function keyOf(string $code): string
    {
        if (!\in_array(\strlen($code), [8, 12, 13, 14], true) || 1 !== preg_match('/^[0-9]+$/', $code)) {
            return $code;
        }
        $digits = array_map(intval(...), str_split($code));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $place => $digit) {
            $sum += $digit * (0 === $place % 2 ? 3 : 1);
        }

        return $check === (10 - $sum % 10) % 10 ? str_pad($code, 14, '0', \STR_PAD_LEFT) : $code;
    }
}

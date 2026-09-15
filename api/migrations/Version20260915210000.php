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
 * docs/SPEC.md § 3 Tenancy, review S5: every business table carries company_id, the rows a document owns included.
 * Each takes it from its document, a line's taxes from their line, so the lines are filled before their taxes.
 */
final class Version20260915210000 extends AbstractMigration
{
    /** @var list<array{table: string, parent: string, parentColumn: string, foreignKey: string}> in fill order */
    private const array TABLES = [
        ['table' => 'invoice_line', 'parent' => 'invoice', 'parentColumn' => 'invoice_id', 'foreignKey' => 'FK_D3D1D693979B1AD6'],
        ['table' => 'invoice_line_tax', 'parent' => 'invoice_line', 'parentColumn' => 'line_id', 'foreignKey' => 'FK_62E2D374979B1AD6'],
        ['table' => 'invoice_tax', 'parent' => 'invoice', 'parentColumn' => 'invoice_id', 'foreignKey' => 'FK_2670D5B5979B1AD6'],
        ['table' => 'payment', 'parent' => 'invoice', 'parentColumn' => 'invoice_id', 'foreignKey' => 'FK_6D28840D979B1AD6'],
        ['table' => 'delivery_note_line', 'parent' => 'delivery_note', 'parentColumn' => 'delivery_note_id', 'foreignKey' => 'FK_350BE93C979B1AD6'],
        ['table' => 'delivery_note_line_tax', 'parent' => 'delivery_note_line', 'parentColumn' => 'line_id', 'foreignKey' => 'FK_6A631252979B1AD6'],
    ];

    public function getDescription(): string
    {
        return 'company_id on the rows invoices and delivery notes own, taken from their document.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as ['table' => $table, 'parent' => $parent, 'parentColumn' => $parentColumn, 'foreignKey' => $foreignKey]) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD company_id UUID DEFAULT NULL', $table));
            $this->addSql(\sprintf('UPDATE %1$s child SET company_id = parent.company_id FROM %2$s parent WHERE parent.id = child.%3$s', $table, $parent, $parentColumn));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER company_id SET NOT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE', $table, $foreignKey));
            $this->addSql(\sprintf('CREATE INDEX idx_%1$s_company ON %1$s (company_id)', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::TABLES) as ['table' => $table, 'foreignKey' => $foreignKey]) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $foreignKey));
            $this->addSql(\sprintf('DROP INDEX idx_%s_company', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP company_id', $table));
        }
    }
}

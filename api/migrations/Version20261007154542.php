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
 * A line's discount as an amount, the line's whole, in place of a rate: an invoice line and a quote line hold one or the
 * other, never both, and never below zero, which PostgreSQL holds as the entities do. Every line written before is a
 * rate or none.
 */
final class Version20261007154542 extends AbstractMigration
{
    /** @var array<string, array<string, string>> table => check name => condition */
    private const array CHECKS = [
        'invoice_line' => [
            'ck_invoice_line_discount_once' => 'discount_rate IS NULL OR discount_amount IS NULL',
            'ck_invoice_line_discount_amount' => 'discount_amount IS NULL OR discount_amount >= 0',
        ],
        'quote_line' => [
            'ck_quote_line_discount_once' => 'discount_rate IS NULL OR discount_amount IS NULL',
            'ck_quote_line_discount_amount' => 'discount_amount IS NULL OR discount_amount >= 0',
        ],
    ];

    public function getDescription(): string
    {
        return 'A line discount as an amount on invoice and quote lines.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CHECKS as $table => $checks) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD discount_amount NUMERIC(14, 3) DEFAULT NULL', $table));
            foreach ($checks as $name => $condition) {
                $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $name, $condition));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                $this->addSql(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
            }
            $this->addSql(\sprintf('ALTER TABLE %s DROP discount_amount', $table));
        }
    }
}

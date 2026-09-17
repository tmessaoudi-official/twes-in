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
 * Lists at scale (docs/SPEC.md § 7, 2026-09-17): a customer is also found by its billing address and by the values of
 * its registration numbers, as the ruling lists. The index is rebuilt on the search expression the repository uses.
 */
final class Version20260917140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers are searched by address and registration numbers too.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_customer_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_customer_search ON customer USING gin (search_text(
                number, name, legal_name, email, billing_line1, billing_postal_code, billing_city,
                jsonb_path_query_array(identifiers, '$.*')::text
            ) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_customer_search');
        $this->addSql('CREATE INDEX idx_customer_search ON customer USING gin (search_text(number, name, legal_name, email, billing_city) gin_trgm_ops)');
    }
}

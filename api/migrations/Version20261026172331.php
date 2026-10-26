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
 * Who answered a quote for its customer: the name as the quote copied it, and the customer's contact it came from. No
 * foreign key: the name, not the contact, is who answered. The quotes list is searched by that name too, so its trigram
 * index is built again on the expression DoctrineQuoteRepository::MATCHES_WORDS asks for, character for character:
 * PostgreSQL uses the index only when the query repeats it exactly.
 */
final class Version20261026172331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A quote keeps who answered it, and its list is searched by that name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quote ADD received_by_name VARCHAR(201) DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD received_by_contact_id UUID DEFAULT NULL');
        $this->addSql('DROP INDEX idx_quote_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_quote_search ON quote USING gin (search_text(
                number, customer_reference, jsonb_path_query_array(customer_snapshot, '$.*')::text, received_by_name
            ) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_quote_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_quote_search ON quote USING gin (search_text(
                number, customer_reference, jsonb_path_query_array(customer_snapshot, '$.*')::text
            ) gin_trgm_ops)
            SQL);
        $this->addSql('ALTER TABLE quote DROP received_by_name');
        $this->addSql('ALTER TABLE quote DROP received_by_contact_id');
    }
}

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
 * Who received the goods an invoice hands over itself: the name as it prints it and the customer's contact it was copied
 * from. No foreign key: a contact's rows go and come back with its customer, and the name, not the contact, is what was
 * received. The list's search finds an invoice by it, so its trigram index is built again on the expression
 * DoctrineInvoiceRepository::MATCHES_WORDS now asks for, character for character.
 */
final class Version20261026190500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An invoice keeps who received its goods, and its search finds it by them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD received_by_name VARCHAR(201) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD received_by_contact_id UUID DEFAULT NULL');
        $this->addSql('DROP INDEX idx_invoice_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_invoice_search ON invoice USING gin (search_text(
                number, customer_reference, jsonb_path_query_array(customer_snapshot, '$.*')::text, received_by_name
            ) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_invoice_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_invoice_search ON invoice USING gin (search_text(
                number, customer_reference, jsonb_path_query_array(customer_snapshot, '$.*')::text
            ) gin_trgm_ops)
            SQL);
        $this->addSql('ALTER TABLE invoice DROP received_by_contact_id');
        $this->addSql('ALTER TABLE invoice DROP received_by_name');
    }
}

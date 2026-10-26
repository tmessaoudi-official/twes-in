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
 * The delivery notes' trigram index made again over who received, which the list's search now finds. Its expression is
 * DoctrineDeliveryNoteRepository::MATCHES_WORDS character for character, as PostgreSQL uses the index only when the
 * query repeats it exactly; SearchIndexesTest proves the pair.
 */
final class Version20261026184900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The delivery notes\' search index made again over who received.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_delivery_note_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_delivery_note_search ON delivery_note USING gin (search_text(
                number, customer_reference, jsonb_path_query_array(customer_snapshot, '$.*')::text, received_by_name
            ) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_delivery_note_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_delivery_note_search ON delivery_note USING gin (search_text(
                number, customer_reference, jsonb_path_query_array(customer_snapshot, '$.*')::text
            ) gin_trgm_ops)
            SQL);
    }
}

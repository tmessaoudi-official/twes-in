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
 * Where each series' chain ends, kept apart from its documents, so the last ones taken away are seen; filled from the
 * chains as they stand, which were sealed by the release before.
 */
final class Version20261012050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The recorded end of each series\' invoice chain';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice_chain_head (id UUID NOT NULL, document_type VARCHAR(16) NOT NULL, position INT NOT NULL, hash CHAR(64) NOT NULL, company_id UUID NOT NULL, establishment_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_chain_head_company ON invoice_chain_head (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_chain_head_series ON invoice_chain_head (establishment_id, document_type)');
        $this->addSql('CREATE INDEX IDX_CB5832848565851 ON invoice_chain_head (establishment_id)');
        $this->addSql('ALTER TABLE invoice_chain_head ADD CONSTRAINT FK_CB583284979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_chain_head ADD CONSTRAINT FK_CB5832848565851 FOREIGN KEY (establishment_id) REFERENCES establishment (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            INSERT INTO invoice_chain_head (id, company_id, establishment_id, document_type, position, hash)
            SELECT gen_random_uuid(), company_id, establishment_id, document_type, chain_position, chain_hash
            FROM (
                SELECT DISTINCT ON (company_id, establishment_id, document_type) company_id, establishment_id, document_type, chain_position, chain_hash
                FROM invoice
                WHERE chain_position IS NOT NULL
                ORDER BY company_id, establishment_id, document_type, chain_position DESC
            ) AS head
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_chain_head DROP CONSTRAINT FK_CB583284979B1AD6');
        $this->addSql('ALTER TABLE invoice_chain_head DROP CONSTRAINT FK_CB5832848565851');
        $this->addSql('DROP TABLE invoice_chain_head');
    }
}

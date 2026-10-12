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
 * Each issued document chained to the one before it in its series (`InvoiceChain`). The documents issued before this
 * release are sealed in their issue order by `app:invoices:seal-chain`, which the API runs at start: the hash is
 * written by the same code that checks it, which SQL here could not repeat.
 */
final class Version20261012020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The issued documents\' hash chain';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD chain_position INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD chain_previous CHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD chain_hash CHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD chain_version SMALLINT DEFAULT NULL');
        // A link is written whole or not at all, and only the first of a chain has nothing before it.
        $this->addSql(<<<'SQL'
            ALTER TABLE invoice ADD CONSTRAINT chk_invoice_chain_whole CHECK (
                (chain_position IS NULL AND chain_previous IS NULL AND chain_hash IS NULL AND chain_version IS NULL)
                OR (chain_position >= 1 AND chain_hash IS NOT NULL AND chain_version IS NOT NULL AND (chain_position = 1) = (chain_previous IS NULL))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_chain_position ON invoice (company_id, establishment_id, document_type, chain_position) WHERE (chain_position IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_invoice_chain_position');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT chk_invoice_chain_whole');
        $this->addSql('ALTER TABLE invoice DROP chain_position');
        $this->addSql('ALTER TABLE invoice DROP chain_previous');
        $this->addSql('ALTER TABLE invoice DROP chain_hash');
        $this->addSql('ALTER TABLE invoice DROP chain_version');
    }
}

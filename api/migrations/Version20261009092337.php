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
 * The files a company imported, kept by the SHA-256 of their content so the same file is known when it comes again,
 * and the stock movements a file wrote marked with the import they belong to.
 */
final class Version20261009092337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Committed imports, and the stock movements a file wrote';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_run (id UUID NOT NULL, subject VARCHAR(32) NOT NULL, content_hash VARCHAR(64) NOT NULL, mode VARCHAR(16) NOT NULL, imported_by UUID DEFAULT NULL, created INT NOT NULL, updated INT NOT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_import_run_content ON import_run (company_id, subject, content_hash)');
        $this->addSql('CREATE INDEX IDX_C41B0440979B1AD6 ON import_run (company_id)');
        $this->addSql('ALTER TABLE import_run ADD CONSTRAINT FK_C41B0440979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_movement ADD import_run_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_stock_movement_import_run ON stock_movement (import_run_id) WHERE (import_run_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_stock_movement_import_run');
        $this->addSql('ALTER TABLE stock_movement DROP import_run_id');
        $this->addSql('DROP TABLE import_run');
    }
}

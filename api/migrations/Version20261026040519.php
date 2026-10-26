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
 * A document's stock move that failed after the document was kept, until someone moves it again or says it is settled.
 */
final class Version20261026040519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A document\'s stock move that failed, kept until it is moved again or settled.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_move_failure (id UUID NOT NULL, move VARCHAR(32) NOT NULL, document_id UUID NOT NULL, number VARCHAR(64) NOT NULL, failed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_stock_move_failure_company ON stock_move_failure (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_move_failure ON stock_move_failure (company_id, move, document_id)');
        $this->addSql('ALTER TABLE stock_move_failure ADD CONSTRAINT FK_F23DFF85979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_move_failure DROP CONSTRAINT FK_F23DFF85979B1AD6');
        $this->addSql('DROP TABLE stock_move_failure');
    }
}

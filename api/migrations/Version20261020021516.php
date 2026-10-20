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
 * « Récemment supprimés »: a delete of records nothing names, and the rows it took, kept a day for their restore.
 */
final class Version20261020021516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deleted records are kept a day for their restore.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE record_removal (id UUID NOT NULL, subject VARCHAR(63) NOT NULL, items JSONB NOT NULL, removed_by UUID DEFAULT NULL, removed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, effective_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, state VARCHAR(16) NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_record_removal_due ON record_removal (state, effective_at)');
        $this->addSql('CREATE INDEX idx_record_removal_company_state ON record_removal (company_id, state)');
        $this->addSql('CREATE INDEX IDX_6304B736979B1AD6 ON record_removal (company_id)');
        $this->addSql('CREATE TABLE record_removal_row (id UUID NOT NULL, table_name VARCHAR(63) NOT NULL, step INT NOT NULL, snapshot JSONB NOT NULL, removal_id UUID NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_record_removal_row_removal ON record_removal_row (removal_id, step)');
        $this->addSql('CREATE INDEX IDX_6CDD552FA00B94E6 ON record_removal_row (removal_id)');
        $this->addSql('CREATE INDEX IDX_6CDD552F979B1AD6 ON record_removal_row (company_id)');
        $this->addSql('ALTER TABLE record_removal ADD CONSTRAINT FK_6304B736979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE record_removal_row ADD CONSTRAINT FK_6CDD552FA00B94E6 FOREIGN KEY (removal_id) REFERENCES record_removal (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE record_removal_row ADD CONSTRAINT FK_6CDD552F979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE record_removal DROP CONSTRAINT FK_6304B736979B1AD6');
        $this->addSql('ALTER TABLE record_removal_row DROP CONSTRAINT FK_6CDD552FA00B94E6');
        $this->addSql('ALTER TABLE record_removal_row DROP CONSTRAINT FK_6CDD552F979B1AD6');
        $this->addSql('DROP TABLE record_removal');
        $this->addSql('DROP TABLE record_removal_row');
    }
}

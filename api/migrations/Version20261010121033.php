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
 * « Effacer des données »: each erasure, at most one waiting for its end per company, and the copy of every row it took,
 * kept until then for its undo.
 */
final class Version20261010121033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data erasures and the copies they keep for their undo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE data_erasure (id UUID NOT NULL, parts JSONB NOT NULL, counts JSONB NOT NULL, erased_by UUID DEFAULT NULL, erased_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, effective_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, state VARCHAR(16) NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_data_erasure_due ON data_erasure (state, effective_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_data_erasure_pending ON data_erasure (company_id) WHERE ((state)::text = \'pending\'::text)');
        $this->addSql('CREATE INDEX IDX_C9303390979B1AD6 ON data_erasure (company_id)');
        $this->addSql('CREATE TABLE data_erasure_row (id UUID NOT NULL, table_name VARCHAR(63) NOT NULL, step INT NOT NULL, kind VARCHAR(8) NOT NULL, snapshot JSONB NOT NULL, erasure_id UUID NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_data_erasure_row_erasure ON data_erasure_row (erasure_id, step)');
        $this->addSql('CREATE INDEX IDX_6FC819AB86D5124C ON data_erasure_row (erasure_id)');
        $this->addSql('CREATE INDEX IDX_6FC819AB979B1AD6 ON data_erasure_row (company_id)');
        $this->addSql('ALTER TABLE data_erasure ADD CONSTRAINT FK_C9303390979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_erasure_row ADD CONSTRAINT FK_6FC819AB86D5124C FOREIGN KEY (erasure_id) REFERENCES data_erasure (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_erasure_row ADD CONSTRAINT FK_6FC819AB979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE data_erasure_row');
        $this->addSql('DROP TABLE data_erasure');
    }
}

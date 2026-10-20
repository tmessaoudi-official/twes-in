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
 * « Annuler cet import »: each import keeps the rows it created, and when and by whom it was taken back, with the
 * delete its undo made, which the bin's restore finds it by.
 */
final class Version20261020182129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An import keeps what it created, and whether it was taken back.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_run_record (id UUID NOT NULL, table_name VARCHAR(63) NOT NULL, record_id UUID NOT NULL, company_id UUID NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_import_run_record_run ON import_run_record (run_id)');
        $this->addSql('CREATE INDEX IDX_538290BF979B1AD6 ON import_run_record (company_id)');
        $this->addSql('ALTER TABLE import_run_record ADD CONSTRAINT FK_538290BF979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_run_record ADD CONSTRAINT FK_538290BF84E3FEC4 FOREIGN KEY (run_id) REFERENCES import_run (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_run ADD undone_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE import_run ADD undone_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE import_run ADD undo_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE import_run ADD undo_removal_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run_record DROP CONSTRAINT FK_538290BF979B1AD6');
        $this->addSql('ALTER TABLE import_run_record DROP CONSTRAINT FK_538290BF84E3FEC4');
        $this->addSql('DROP TABLE import_run_record');
        $this->addSql('ALTER TABLE import_run DROP undone_at');
        $this->addSql('ALTER TABLE import_run DROP undone_by');
        $this->addSql('ALTER TABLE import_run DROP undo_id');
        $this->addSql('ALTER TABLE import_run DROP undo_removal_id');
    }
}

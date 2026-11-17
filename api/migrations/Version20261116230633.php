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
 * What an import removed, for its undo: a file replacing who its products are bought from drops the vendors it no longer
 * names, and « Annuler cet import » puts them back with their codes. A table of its own, added: nothing existing changes.
 */
final class Version20261116230633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'import_run_removal: the rows an import removed, with what puts them back';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_run_removal (id UUID NOT NULL, table_name VARCHAR(63) NOT NULL, record_id UUID NOT NULL, what JSON NOT NULL, company_id UUID NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_import_run_removal_run ON import_run_removal (run_id)');
        $this->addSql('CREATE INDEX IDX_8C50C8B979B1AD6 ON import_run_removal (company_id)');
        $this->addSql('ALTER TABLE import_run_removal ADD CONSTRAINT FK_8C50C8B979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_run_removal ADD CONSTRAINT FK_8C50C8B84E3FEC4 FOREIGN KEY (run_id) REFERENCES import_run (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run_removal DROP CONSTRAINT FK_8C50C8B979B1AD6');
        $this->addSql('ALTER TABLE import_run_removal DROP CONSTRAINT FK_8C50C8B84E3FEC4');
        $this->addSql('DROP TABLE import_run_removal');
    }
}

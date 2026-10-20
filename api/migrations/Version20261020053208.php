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
 * A merge is kept a day beside the deletes: which record it kept, and every row it pointed at that record, so
 * « Annuler la fusion » points it back.
 */
final class Version20261020053208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merges are kept a day for their undo, with every row they moved.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE record_merge_move (id UUID NOT NULL, table_name VARCHAR(63) NOT NULL, column_name VARCHAR(63) NOT NULL, row_id UUID NOT NULL, from_value VARCHAR(255) NOT NULL, to_value VARCHAR(255) NOT NULL, removal_id UUID NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_record_merge_move_removal ON record_merge_move (removal_id, table_name, column_name)');
        $this->addSql('CREATE INDEX IDX_1746DDBDA00B94E6 ON record_merge_move (removal_id)');
        $this->addSql('CREATE INDEX IDX_1746DDBD979B1AD6 ON record_merge_move (company_id)');
        $this->addSql('ALTER TABLE record_merge_move ADD CONSTRAINT FK_1746DDBDA00B94E6 FOREIGN KEY (removal_id) REFERENCES record_removal (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE record_merge_move ADD CONSTRAINT FK_1746DDBD979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE record_removal ADD kind VARCHAR(8) DEFAULT \'delete\' NOT NULL');
        $this->addSql('ALTER TABLE record_removal ADD kept_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE record_removal ADD kept_label VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE record_merge_move DROP CONSTRAINT FK_1746DDBDA00B94E6');
        $this->addSql('ALTER TABLE record_merge_move DROP CONSTRAINT FK_1746DDBD979B1AD6');
        $this->addSql('DROP TABLE record_merge_move');
        $this->addSql('ALTER TABLE record_removal DROP kind');
        $this->addSql('ALTER TABLE record_removal DROP kept_id');
        $this->addSql('ALTER TABLE record_removal DROP kept_label');
    }
}

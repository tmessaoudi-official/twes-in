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
 * A vendor merged into another stays, pointing at it, so the documents and movements naming it keep naming it while
 * lists and pickers show the vendor kept. A merge notes that link's empty before-value for its undo.
 */
final class Version20261020063645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A vendor merged away points at the vendor kept; a merge notes an empty before-value.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE record_merge_move ALTER from_value DROP NOT NULL');
        $this->addSql('ALTER TABLE vendor ADD merged_into_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor ADD CONSTRAINT FK_F52233F6285E57BA FOREIGN KEY (merged_into_id) REFERENCES vendor (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE vendor ADD CONSTRAINT chk_vendor_not_merged_into_itself CHECK (merged_into_id IS DISTINCT FROM id)');
        $this->addSql('CREATE INDEX IDX_F52233F6285E57BA ON vendor (merged_into_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor DROP CONSTRAINT chk_vendor_not_merged_into_itself');
        $this->addSql('ALTER TABLE vendor DROP CONSTRAINT FK_F52233F6285E57BA');
        $this->addSql('DROP INDEX IDX_F52233F6285E57BA');
        $this->addSql('ALTER TABLE vendor DROP merged_into_id');
        $this->addSql('DELETE FROM record_merge_move WHERE from_value IS NULL');
        $this->addSql('ALTER TABLE record_merge_move ALTER from_value SET NOT NULL');
    }
}

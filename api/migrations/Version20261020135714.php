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
 * A customer merged into another stays, pointing at it, so what was sent or issued to it keeps naming it while lists,
 * pickers and reports show the customer kept.
 */
final class Version20261020135714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A customer merged away points at the customer kept.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD merged_into_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT FK_81398E09285E57BA FOREIGN KEY (merged_into_id) REFERENCES customer (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT chk_customer_not_merged_into_itself CHECK (merged_into_id IS DISTINCT FROM id)');
        $this->addSql('CREATE INDEX IDX_81398E09285E57BA ON customer (merged_into_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP CONSTRAINT chk_customer_not_merged_into_itself');
        $this->addSql('ALTER TABLE customer DROP CONSTRAINT FK_81398E09285E57BA');
        $this->addSql('DROP INDEX IDX_81398E09285E57BA');
        $this->addSql('ALTER TABLE customer DROP merged_into_id');
    }
}

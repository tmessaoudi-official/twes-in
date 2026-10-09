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
 * A photo a paired phone took, waiting a while for the tab that lent the phone to take it: its bytes are kept in the
 * row rather than among the company's files, which are kept for ever, and go when the pairing or the company does.
 */
final class Version20261009015239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The photos a paired phone took, waiting for their tab';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scan_photo (id UUID NOT NULL, mime VARCHAR(32) NOT NULL, contents BYTEA NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, pairing_id UUID NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_scan_photo_pairing ON scan_photo (pairing_id)');
        $this->addSql('CREATE INDEX idx_scan_photo_created ON scan_photo (created_at)');
        $this->addSql('CREATE INDEX IDX_739FB536979B1AD6 ON scan_photo (company_id)');
        $this->addSql('ALTER TABLE scan_photo ADD CONSTRAINT FK_739FB536DC8046BD FOREIGN KEY (pairing_id) REFERENCES scan_pairing (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scan_photo ADD CONSTRAINT FK_739FB536979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scan_photo ADD CONSTRAINT ck_scan_photo_contents CHECK (octet_length(contents) > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scan_photo');
    }
}

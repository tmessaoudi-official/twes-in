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
 * An invitation holds the role it was sent for by identity (docs/SPEC.md § 7, audit C-F1): resolved by name at the
 * link, a role renamed onto that name was the one joined, which let an editor hand themselves what they do not hold.
 * Each invitation is pointed at the role its name names in its company today, the company's own first, else the
 * built-in one (names are unique across both); one whose role is gone keeps no role and stays refused at the link.
 */
final class Version20261006122014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An invitation names its role by id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation ADD role_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F11D61A2D60322AC ON invitation (role_id)');
        $this->addSql('UPDATE invitation i SET role_id = r.id FROM role r WHERE r.name = i.role_name AND r.company_id = i.company_id');
        $this->addSql('UPDATE invitation i SET role_id = r.id FROM role r WHERE i.role_id IS NULL AND r.name = i.role_name AND r.company_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2D60322AC');
        $this->addSql('DROP INDEX IDX_F11D61A2D60322AC');
        $this->addSql('ALTER TABLE invitation DROP role_id');
    }
}

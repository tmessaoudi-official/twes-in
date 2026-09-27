<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The legal pages' versions (docs/SPEC.md § 8 row 148): only ever added, the latest per page and language shown. */
final class Version20260927132500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legal page versions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE legal_text (
                id UUID NOT NULL,
                page VARCHAR(20) NOT NULL,
                language VARCHAR(2) NOT NULL,
                body TEXT NOT NULL,
                published_on DATE NOT NULL,
                validated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                validated_by VARCHAR(180) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_by VARCHAR(180) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_legal_text_page_language ON legal_text (page, language, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE legal_text');
    }
}

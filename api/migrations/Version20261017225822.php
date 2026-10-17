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
 * The mails a demo sandbox would have sent, kept for « Courriers de la démo » instead: removing the sandbox removes them.
 */
final class Version20261017225822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The demo\'s mails, kept instead of sent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE demo_mail (id UUID NOT NULL, subject TEXT NOT NULL, html TEXT NOT NULL, captured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sandbox_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_demo_mail_sandbox_captured ON demo_mail (sandbox_id, captured_at)');
        $this->addSql('CREATE INDEX IDX_A66FD13D66A1C3E9 ON demo_mail (sandbox_id)');
        $this->addSql('ALTER TABLE demo_mail ADD CONSTRAINT FK_A66FD13D66A1C3E9 FOREIGN KEY (sandbox_id) REFERENCES demo_sandbox (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE demo_mail');
    }
}

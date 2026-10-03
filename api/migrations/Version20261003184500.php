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
 * The three choices of what customer view hid are gone, since the customer screen shows an allow-list instead. A stored
 * value of a key nobody declares is never read, so this only keeps the table honest.
 */
final class Version20261003184500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forget the stored choices of what customer view hid.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM setting WHERE key IN ('presentation.customer-view.cost', 'presentation.customer-view.supplier-codes', 'presentation.customer-view.other-customers')");
    }

    public function down(Schema $schema): void
    {
        // The deleted values were each a company's own boolean and the settings no longer exist to hold them, so there
        // is nothing to restore.
        $this->addSql('SELECT 1');
    }
}

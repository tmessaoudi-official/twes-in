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
 * An order keeps how it prints when it is sent, as a quote does. An order sent before has neither column filled and
 * prints as the settings say, as it did; the release before this one reads neither column.
 */
final class Version20261106220534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A sent purchase order keeps its language, formats, design and unit names, as a sent quote does.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order ADD print_settings JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order ADD printed_names JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order DROP printed_names');
        $this->addSql('ALTER TABLE purchase_order DROP print_settings');
    }
}

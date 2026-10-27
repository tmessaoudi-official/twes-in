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
 * Who received an invoice's goods as its print as issued names them, kept beside the name put right since. An invoice
 * issued before this named whoever it names now, since nothing put its receiver right until then.
 */
final class Version20261027004500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An issued invoice keeps who received its goods as it was issued naming them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD printed_receiver_name VARCHAR(201) DEFAULT NULL');
        $this->addSql("UPDATE invoice SET printed_receiver_name = received_by_name WHERE received_by_name IS NOT NULL AND status IN ('issued', 'partially_paid', 'paid')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP printed_receiver_name');
    }
}

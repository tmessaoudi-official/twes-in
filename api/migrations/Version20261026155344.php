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
 * Who received a delivery note's goods: the name as the note says it now, the customer's contact it was copied from, and
 * the name its issued print carries, as it was when the note was validated. No foreign key: a contact's rows go and
 * come back with its customer, and the name, not the contact, is what was received.
 */
final class Version20261026155344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A delivery note keeps who received its goods.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note ADD received_by_name VARCHAR(201) DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_note ADD received_by_contact_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_note ADD printed_receiver_name VARCHAR(201) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note DROP received_by_name');
        $this->addSql('ALTER TABLE delivery_note DROP received_by_contact_id');
        $this->addSql('ALTER TABLE delivery_note DROP printed_receiver_name');
    }
}

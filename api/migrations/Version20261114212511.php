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
 * Wrong guesses lock a password from the address they came from; the account's own count, kept on "user", now locks it
 * everywhere only past a far higher ceiling. A lock already set on an account holds until it ends, as before; the release
 * before this one reads neither the table nor any new column.
 */
final class Version20261114212511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A password locked from one address by the wrong guesses made from it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_address_lock (id UUID NOT NULL, address VARCHAR(45) NOT NULL, failed_count INT NOT NULL, locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_failed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_password_address_lock ON password_address_lock (user_id, address)');
        $this->addSql('ALTER TABLE password_address_lock ADD CONSTRAINT FK_PASSWORD_ADDRESS_LOCK_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_address_lock');
    }
}

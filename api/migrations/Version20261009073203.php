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
 * Where a company's generated product references stand: one counter per company, made with its first generated
 * reference, locked by the save that takes one.
 */
final class Version20261009073203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The counter a company\'s generated product references take their number from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_reference_sequence (id UUID NOT NULL, next_number INT NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_reference_sequence_company ON product_reference_sequence (company_id)');
        $this->addSql('ALTER TABLE product_reference_sequence ADD CONSTRAINT FK_785A0928979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_reference_sequence ADD CONSTRAINT ck_product_reference_sequence_next CHECK (next_number >= 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_reference_sequence');
    }
}

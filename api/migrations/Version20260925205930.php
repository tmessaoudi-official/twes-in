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
 * What an expense's payment is to Tunisia's TEJ platform, the operation code its withholding is declared under
 * (docs/research/tax-data-tunisia.md § 2.2). Nothing is backfilled: the code depends on the supplier, not on the rate,
 * so a payment already made is given one by a person, and until then the monthly declaration names it as missing one.
 */
final class Version20260925205930 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The TEJ operation code of an expense\'s withholding.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense ADD withholding_operation_code VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense DROP withholding_operation_code');
    }
}

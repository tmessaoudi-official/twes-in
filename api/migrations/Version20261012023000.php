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
 * The issued documents not chained yet, which every issue asks its series for while holding its lock: an index of
 * only those, almost always empty, so the question never reads the series.
 */
final class Version20261012023000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The index of the issued documents not chained yet';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_invoice_unsealed ON invoice (company_id, establishment_id, document_type) WHERE ((chain_hash IS NULL) AND (number IS NOT NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_invoice_unsealed');
    }
}

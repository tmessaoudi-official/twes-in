<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Lists at scale (docs/SPEC.md § 7): products are searched through a trigram index, as customers and vendors are. */
final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The trigram index products are searched with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_product_search ON product USING gin (search_text(reference, name, barcode) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_search');
    }
}

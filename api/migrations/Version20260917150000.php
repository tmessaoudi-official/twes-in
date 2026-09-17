<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Lists at scale (docs/SPEC.md § 7): vendors are searched through a trigram index, as customers are. */
final class Version20260917150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The trigram index vendors are searched with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_vendor_search ON vendor USING gin (search_text(
                number, name, legal_name, email, address_line1, address_postal_code, address_city,
                jsonb_path_query_array(identifiers, '$.*')::text
            ) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_vendor_search');
    }
}

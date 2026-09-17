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
 * Lists at scale (docs/SPEC.md § 7): a list is searched in the database, whatever the case and the accents typed.
 * unaccent() is only STABLE, since its dictionary can change, so an index cannot use it; search_text() names the
 * dictionary and is IMMUTABLE, as PostgreSQL's own documentation of unaccent suggests. A trigram GIN index over it
 * answers `LIKE '%words%'` without reading the whole table. Doctrine does not see an expression index, so the mapping
 * does not declare it.
 */
final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'search_text() and the trigram index customers are searched with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION search_text(VARIADIC parts text[]) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE
                RETURN public.unaccent('public.unaccent'::regdictionary, lower(array_to_string(parts, ' ')))
            SQL);
        $this->addSql('CREATE INDEX idx_customer_search ON customer USING gin (search_text(number, name, legal_name, email, billing_city) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_customer_search');
        $this->addSql('DROP FUNCTION search_text(text[])');
        $this->addSql('DROP EXTENSION pg_trgm');
        $this->addSql('DROP EXTENSION unaccent');
    }
}

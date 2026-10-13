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
 * The application's role may only add to the journal. These two functions are the only ways an address leaves it,
 * each run with the rights of the schema's owner and doing exactly one thing: replacing one person's address once their
 * account is deleted, and the addresses typed at failed sign-ins (with the address they came from) once they are at
 * least a year old. Nothing else of a journal entry changes. Run with the owner's rights, so the search path is fixed
 * and every name qualified: a table or operator the caller made can never stand in for the real one.
 */
final class Version20261013104100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The two functions that take addresses out of the journal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.audit_erase_address(address text) RETURNS integer
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
                AS $$
            DECLARE
                wanted text := pg_catalog.lower(pg_catalog.btrim(address));
                erased integer;
            BEGIN
                IF wanted IS NULL OR wanted !~ '^[^@[:space:]]+@[^@[:space:]]+$' THEN
                    RETURN 0;
                END IF;
                UPDATE public.audit_log
                    SET changes = pg_catalog.jsonb_set(changes, '{email}', '"erased"')
                    WHERE changes OPERATOR(pg_catalog.?) 'email'
                      AND pg_catalog.lower(pg_catalog.btrim(changes OPERATOR(pg_catalog.->>) 'email')) = wanted;
                GET DIAGNOSTICS erased = ROW_COUNT;
                RETURN erased;
            END
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.audit_purge_failed_sign_in_addresses(months integer) RETURNS integer
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
                AS $$
            DECLARE
                purged integer;
            BEGIN
                IF months IS NULL OR months < 12 THEN
                    RAISE EXCEPTION 'failed sign-in addresses are kept at least 12 months, not %', months;
                END IF;
                UPDATE public.audit_log
                    SET changes = CASE WHEN changes OPERATOR(pg_catalog.?) 'email'
                            THEN pg_catalog.jsonb_set(changes, '{email}', '"erased"') ELSE changes END,
                        ip = NULL
                    WHERE action = 'auth.login_failed'
                      AND at < (pg_catalog.now() AT TIME ZONE 'UTC') - pg_catalog.make_interval(months => months)
                      AND (ip IS NOT NULL OR changes OPERATOR(pg_catalog.->>) 'email' IS DISTINCT FROM 'erased');
                GET DIAGNOSTICS purged = ROW_COUNT;
                RETURN purged;
            END
            $$
            SQL);
        // A new function is executable by everyone until this is taken back; app:database:app-role grants it again to
        // the application's role alone.
        $this->addSql('REVOKE EXECUTE ON FUNCTION public.audit_erase_address(text) FROM PUBLIC');
        $this->addSql('REVOKE EXECUTE ON FUNCTION public.audit_purge_failed_sign_in_addresses(integer) FROM PUBLIC');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION public.audit_purge_failed_sign_in_addresses(integer)');
        $this->addSql('DROP FUNCTION public.audit_erase_address(text)');
    }
}

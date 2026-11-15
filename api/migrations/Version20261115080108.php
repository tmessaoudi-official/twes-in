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
 * An invoice written already issued is listed in its transaction so that its lines and taxes may follow; a transaction
 * already released lists nothing, since its rows pass the guard anyway and the list was read again by every later row.
 */
final class Version20261115080108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The fiscal guard lists no invoice written issued in a transaction already released';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fiscal_guard_invoice() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
                AS $$
            DECLARE
                -- FREE COLUMNS:
                free CONSTANT text[] := ARRAY['status', 'amount_paid', 'amount_due', 'amount_credited', 'updated_at', 'version', 'received_by_name', 'received_by_contact_id', 'notes_internal'];
                -- END FREE COLUMNS
                sealed CONSTANT text[] := ARRAY['pdf_file_id', 'chain_position', 'chain_previous', 'chain_hash', 'chain_version'];
                before jsonb;
                after jsonb;
                changed text;
                due numeric;
                settled text;
            BEGIN
                -- An invoice written already issued (a use case creating and issuing it at once) writes its lines next.
                IF TG_OP = 'INSERT' THEN
                    -- A transaction already released writes past the guard anyway: listing its rows would lengthen one
                    -- setting every later row reads, which made a chunk of the scale generator's clones take minutes.
                    IF NEW.issued_at IS NOT NULL AND NOT public.fiscal_released(NEW.company_id) THEN
                        PERFORM pg_catalog.set_config('twes.issuing', pg_catalog.concat_ws(',', nullif(pg_catalog.current_setting('twes.issuing', true), ''), NEW.id::text), true);
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.issued_at IS NULL THEN
                    -- A draft is the company's to change; the one becoming issued may write its lines and taxes until it commits.
                    IF TG_OP = 'UPDATE' AND NEW.issued_at IS NOT NULL THEN
                        PERFORM pg_catalog.set_config('twes.issuing', pg_catalog.concat_ws(',', nullif(pg_catalog.current_setting('twes.issuing', true), ''), NEW.id::text), true);
                    END IF;
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF public.fiscal_released(OLD.company_id) THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'fiscal guard: the issued invoice % is never deleted', OLD.number USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                before := pg_catalog.to_jsonb(OLD) OPERATOR(pg_catalog.-) free OPERATOR(pg_catalog.-) sealed;
                after := pg_catalog.to_jsonb(NEW) OPERATOR(pg_catalog.-) free OPERATOR(pg_catalog.-) sealed;
                IF before IS DISTINCT FROM after THEN
                    SELECT o.key INTO changed FROM pg_catalog.jsonb_each(before) o
                        WHERE o.value IS DISTINCT FROM after OPERATOR(pg_catalog.->) o.key ORDER BY o.key LIMIT 1;
                    RAISE EXCEPTION 'fiscal guard: % of the issued invoice % is never rewritten', coalesce(changed, 'a column'), OLD.number USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                SELECT s INTO changed FROM pg_catalog.unnest(sealed) s
                    WHERE pg_catalog.to_jsonb(OLD) OPERATOR(pg_catalog.->) s <> 'null'::jsonb
                      AND pg_catalog.to_jsonb(OLD) OPERATOR(pg_catalog.->) s IS DISTINCT FROM pg_catalog.to_jsonb(NEW) OPERATOR(pg_catalog.->) s
                    LIMIT 1;
                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION 'fiscal guard: % of the issued invoice % is written once', changed, OLD.number USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                -- The settlement is read only when it is written, so a row a past bug left astray still takes its PDF and seal.
                IF (OLD.status, OLD.amount_paid, OLD.amount_due, OLD.amount_credited) IS DISTINCT FROM (NEW.status, NEW.amount_paid, NEW.amount_due, NEW.amount_credited) THEN
                    IF OLD.document_type = 'credit_note' THEN
                        RAISE EXCEPTION 'fiscal guard: the settlement of the issued credit note % never changes', OLD.number USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                    due := greatest(0, NEW.total_gross - NEW.withholding_amount - NEW.amount_paid - NEW.amount_credited);
                    settled := CASE
                        WHEN NEW.amount_paid = 0 AND NEW.amount_credited = 0 THEN 'issued'
                        WHEN due = 0 THEN 'paid'
                        ELSE 'partially_paid'
                    END;
                    IF NEW.amount_due IS DISTINCT FROM due OR NEW.status IS DISTINCT FROM settled THEN
                        RAISE EXCEPTION 'fiscal guard: the issued invoice % would read % with % due, where its figures give % with % due', OLD.number, NEW.status, NEW.amount_due, settled, due USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fiscal_guard_invoice() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
                AS $$
            DECLARE
                -- FREE COLUMNS:
                free CONSTANT text[] := ARRAY['status', 'amount_paid', 'amount_due', 'amount_credited', 'updated_at', 'version', 'received_by_name', 'received_by_contact_id', 'notes_internal'];
                -- END FREE COLUMNS
                sealed CONSTANT text[] := ARRAY['pdf_file_id', 'chain_position', 'chain_previous', 'chain_hash', 'chain_version'];
                before jsonb;
                after jsonb;
                changed text;
                due numeric;
                settled text;
            BEGIN
                -- An invoice written already issued (a use case creating and issuing it at once) writes its lines next.
                IF TG_OP = 'INSERT' THEN
                    IF NEW.issued_at IS NOT NULL THEN
                        PERFORM pg_catalog.set_config('twes.issuing', pg_catalog.concat_ws(',', nullif(pg_catalog.current_setting('twes.issuing', true), ''), NEW.id::text), true);
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.issued_at IS NULL THEN
                    -- A draft is the company's to change; the one becoming issued may write its lines and taxes until it commits.
                    IF TG_OP = 'UPDATE' AND NEW.issued_at IS NOT NULL THEN
                        PERFORM pg_catalog.set_config('twes.issuing', pg_catalog.concat_ws(',', nullif(pg_catalog.current_setting('twes.issuing', true), ''), NEW.id::text), true);
                    END IF;
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF public.fiscal_released(OLD.company_id) THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'fiscal guard: the issued invoice % is never deleted', OLD.number USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                before := pg_catalog.to_jsonb(OLD) OPERATOR(pg_catalog.-) free OPERATOR(pg_catalog.-) sealed;
                after := pg_catalog.to_jsonb(NEW) OPERATOR(pg_catalog.-) free OPERATOR(pg_catalog.-) sealed;
                IF before IS DISTINCT FROM after THEN
                    SELECT o.key INTO changed FROM pg_catalog.jsonb_each(before) o
                        WHERE o.value IS DISTINCT FROM after OPERATOR(pg_catalog.->) o.key ORDER BY o.key LIMIT 1;
                    RAISE EXCEPTION 'fiscal guard: % of the issued invoice % is never rewritten', coalesce(changed, 'a column'), OLD.number USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                SELECT s INTO changed FROM pg_catalog.unnest(sealed) s
                    WHERE pg_catalog.to_jsonb(OLD) OPERATOR(pg_catalog.->) s <> 'null'::jsonb
                      AND pg_catalog.to_jsonb(OLD) OPERATOR(pg_catalog.->) s IS DISTINCT FROM pg_catalog.to_jsonb(NEW) OPERATOR(pg_catalog.->) s
                    LIMIT 1;
                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION 'fiscal guard: % of the issued invoice % is written once', changed, OLD.number USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                -- The settlement is read only when it is written, so a row a past bug left astray still takes its PDF and seal.
                IF (OLD.status, OLD.amount_paid, OLD.amount_due, OLD.amount_credited) IS DISTINCT FROM (NEW.status, NEW.amount_paid, NEW.amount_due, NEW.amount_credited) THEN
                    IF OLD.document_type = 'credit_note' THEN
                        RAISE EXCEPTION 'fiscal guard: the settlement of the issued credit note % never changes', OLD.number USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                    due := greatest(0, NEW.total_gross - NEW.withholding_amount - NEW.amount_paid - NEW.amount_credited);
                    settled := CASE
                        WHEN NEW.amount_paid = 0 AND NEW.amount_credited = 0 THEN 'issued'
                        WHEN due = 0 THEN 'paid'
                        ELSE 'partially_paid'
                    END;
                    IF NEW.amount_due IS DISTINCT FROM due OR NEW.status IS DISTINCT FROM settled THEN
                        RAISE EXCEPTION 'fiscal guard: the issued invoice % would read % with % due, where its figures give % with % due', OLD.number, NEW.status, NEW.amount_due, settled, due USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END
            $$
            SQL);
    }
}

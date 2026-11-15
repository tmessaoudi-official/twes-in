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
 * The database keeps an issued invoice as it was issued, whoever sends the statement: its figures, number, days,
 * customer and seller as printed, lines and taxes are never rewritten and the row is never deleted. What an issued
 * invoice still lives through stays writable: its settlement, only as its figures give it (a credit note's never
 * changes), who received its goods, its internal note, and its stored PDF and seal from nothing to a value, once.
 *
 * A company whose keeping time has run out, or a demo sandbox, goes whole through fiscal_release_company, the one way
 * past the guard: it writes a release for the company and the transaction into a table the application's role may read
 * and never write. The owner's identity could not be that way, as a cascade from the company runs as the table's owner.
 *
 * The issue itself writes the lines and taxes after the invoice's row: the row's change from draft to issued, or the row
 * written already issued, names the invoice in a setting of that transaction alone, which lets its lines be written until
 * it commits.
 */
final class Version20261114183314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The database refuses to rewrite or delete an issued invoice, its lines and taxes, but through a company released at the end of its keeping time.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE fiscal_release (
                transaction_id xid8 NOT NULL,
                company_id UUID DEFAULT NULL,
                reason VARCHAR(40) NOT NULL,
                at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_fiscal_release_transaction ON fiscal_release (transaction_id)');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.fiscal_released(company uuid) RETURNS boolean
                LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp
                AS $$
            SELECT EXISTS (
                SELECT 1 FROM public.fiscal_release r
                WHERE r.transaction_id OPERATOR(pg_catalog.=) pg_catalog.pg_current_xact_id()
                  AND (r.company_id IS NULL OR r.company_id OPERATOR(pg_catalog.=) company)
            )
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.fiscal_guard_invoice() RETURNS trigger
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
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.fiscal_guard_invoice_part() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
                AS $$
            DECLARE
                parents uuid[] := ARRAY[]::uuid[];
                parent uuid;
                owner record;
            BEGIN
                IF TG_TABLE_NAME = 'invoice_line_tax' THEN
                    IF TG_OP <> 'INSERT' THEN
                        parents := parents || (SELECT l.invoice_id FROM public.invoice_line l WHERE l.id = OLD.line_id);
                    END IF;
                    IF TG_OP <> 'DELETE' THEN
                        parents := parents || (SELECT l.invoice_id FROM public.invoice_line l WHERE l.id = NEW.line_id);
                    END IF;
                ELSE
                    IF TG_OP <> 'INSERT' THEN
                        parents := parents || OLD.invoice_id;
                    END IF;
                    IF TG_OP <> 'DELETE' THEN
                        parents := parents || NEW.invoice_id;
                    END IF;
                END IF;
                FOREACH parent IN ARRAY parents LOOP
                    -- A line whose invoice is already gone was taken along by the invoice's own delete, which the invoice's guard ruled on.
                    CONTINUE WHEN parent IS NULL;
                    SELECT i.issued_at, i.company_id, i.number INTO owner FROM public.invoice i WHERE i.id = parent;
                    CONTINUE WHEN NOT FOUND OR owner.issued_at IS NULL;
                    CONTINUE WHEN parent::text = ANY (pg_catalog.string_to_array(pg_catalog.current_setting('twes.issuing', true), ','));
                    CONTINUE WHEN public.fiscal_released(owner.company_id);
                    RAISE EXCEPTION 'fiscal guard: the lines and taxes of the issued invoice % are never rewritten', owner.number USING ERRCODE = 'integrity_constraint_violation';
                END LOOP;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.fiscal_release_company(company uuid, today date) RETURNS void
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
                AS $$
            DECLARE
                reason text;
            BEGIN
                IF EXISTS (SELECT 1 FROM public.company c WHERE c.id = company AND c.status = 'closed' AND c.retain_until < today) THEN
                    reason := 'keeping time ended';
                ELSIF EXISTS (SELECT 1 FROM public.demo_sandbox s WHERE s.company_id = company) THEN
                    reason := 'demo sandbox';
                ELSE
                    RAISE EXCEPTION 'fiscal guard: the company % is still kept, so its issued invoices stay', company USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                INSERT INTO public.fiscal_release (transaction_id, company_id, reason, at)
                    VALUES (pg_catalog.pg_current_xact_id(), company, reason, localtimestamp(0));
            END
            $$
            SQL);
        // A release serves only the delete it was written for: once the company row is gone, so is its release, and a
        // removed company leaves not even its id behind. Run by the table's owner, as the application's role never writes it.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.fiscal_release_spent() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp
                AS $$
            BEGIN
                DELETE FROM public.fiscal_release WHERE company_id = OLD.id;
                RETURN OLD;
            END
            $$
            SQL);
        // The release before neither writes nor reads fiscal_release; its company removal is unchanged.
        // Triggers of one event fire in name order, so the foreign keys' cascades (RI_…) have run when this one does.
        $this->addSql('CREATE TRIGGER fiscal_release_spent AFTER DELETE ON company FOR EACH ROW EXECUTE FUNCTION public.fiscal_release_spent()'); // contract: deletes only a release, which the release before never writes
        $this->addSql('REVOKE EXECUTE ON FUNCTION public.fiscal_release_spent() FROM PUBLIC');
        // The release before removes a closed company or a demo sandbox holding issued invoices without a release, which these triggers refuse; run on this schema by a rollback, its removal rolls back whole and is done once the update is in again. It never rewrites an issued invoice's guarded columns.
        $this->addSql('CREATE TRIGGER fiscal_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice FOR EACH ROW EXECUTE FUNCTION public.fiscal_guard_invoice()'); // contract: refuses a rewrite of an issued invoice, which the release before never makes, and the removal of a company holding one, which it makes only for a company past its keeping time or a demo sandbox: refused there after a rollback, the company stays
        // As above; the release before writes an invoice's lines and taxes only while it is a draft or in the transaction issuing it.
        $this->addSql('CREATE TRIGGER fiscal_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice_line FOR EACH ROW EXECUTE FUNCTION public.fiscal_guard_invoice_part()'); // contract: as on invoice
        $this->addSql('CREATE TRIGGER fiscal_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice_line_tax FOR EACH ROW EXECUTE FUNCTION public.fiscal_guard_invoice_part()'); // contract: as on invoice
        $this->addSql('CREATE TRIGGER fiscal_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice_tax FOR EACH ROW EXECUTE FUNCTION public.fiscal_guard_invoice_part()'); // contract: as on invoice
        // A new function is executable by everyone until this is taken back; app:database:app-role grants it again to
        // the application's role alone.
        $this->addSql('REVOKE EXECUTE ON FUNCTION public.fiscal_release_company(uuid, date) FROM PUBLIC');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER fiscal_guard ON invoice_tax');
        $this->addSql('DROP TRIGGER fiscal_guard ON invoice_line_tax');
        $this->addSql('DROP TRIGGER fiscal_guard ON invoice_line');
        $this->addSql('DROP TRIGGER fiscal_guard ON invoice');
        $this->addSql('DROP TRIGGER fiscal_release_spent ON company');
        $this->addSql('DROP FUNCTION public.fiscal_release_spent()');
        $this->addSql('DROP FUNCTION public.fiscal_release_company(uuid, date)');
        $this->addSql('DROP FUNCTION public.fiscal_guard_invoice_part()');
        $this->addSql('DROP FUNCTION public.fiscal_guard_invoice()');
        $this->addSql('DROP FUNCTION public.fiscal_released(uuid)');
        $this->addSql('DROP TABLE fiscal_release');
    }
}

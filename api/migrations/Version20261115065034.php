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
 * A TRUNCATE fires no row's trigger, so the fiscal guard on each row never sees one. The application's role holds no
 * TRUNCATE; the owner's (the migrations' role, a hand at a console) is refused here while any issued invoice is kept.
 * TRUNCATE company CASCADE reaches these tables too, and each refuses it. A database holding only drafts is emptied.
 */
final class Version20261115065034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The fiscal guard refuses a TRUNCATE of the invoices, their lines and their taxes while an issued invoice is kept';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.fiscal_guard_truncate() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp
                AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM public.invoice WHERE issued_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'fiscal guard: % is never emptied while an issued invoice is kept', TG_TABLE_NAME USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NULL;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER fiscal_guard_truncate BEFORE TRUNCATE ON invoice FOR EACH STATEMENT EXECUTE FUNCTION public.fiscal_guard_truncate()'); // contract: refuses only a TRUNCATE, which the release before never sends
        $this->addSql('CREATE TRIGGER fiscal_guard_truncate BEFORE TRUNCATE ON invoice_line FOR EACH STATEMENT EXECUTE FUNCTION public.fiscal_guard_truncate()'); // contract: as on invoice
        $this->addSql('CREATE TRIGGER fiscal_guard_truncate BEFORE TRUNCATE ON invoice_line_tax FOR EACH STATEMENT EXECUTE FUNCTION public.fiscal_guard_truncate()'); // contract: as on invoice
        $this->addSql('CREATE TRIGGER fiscal_guard_truncate BEFORE TRUNCATE ON invoice_tax FOR EACH STATEMENT EXECUTE FUNCTION public.fiscal_guard_truncate()'); // contract: as on invoice
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER fiscal_guard_truncate ON invoice_tax');
        $this->addSql('DROP TRIGGER fiscal_guard_truncate ON invoice_line_tax');
        $this->addSql('DROP TRIGGER fiscal_guard_truncate ON invoice_line');
        $this->addSql('DROP TRIGGER fiscal_guard_truncate ON invoice');
        $this->addSql('DROP FUNCTION public.fiscal_guard_truncate()');
    }
}

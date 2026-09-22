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
 * A barcode is unique within the company when set (docs/SPEC.md § 7, 2026-09-17), so a scan finds exactly one
 * product — which is what lets the scanner add a line without asking which one was meant.
 *
 * PARTIAL, on `barcode IS NOT NULL`: many products have no barcode at all, and a plain unique index would let
 * exactly one of them exist. In Postgres a plain unique index would in fact permit them all, since NULLs never
 * collide there — but that is a property of one engine's null handling rather than a stated intent, and a reader
 * cannot tell "we relied on NULL not colliding" from "we forgot". The `WHERE` says which it is.
 *
 * `ManageProducts` already refuses the duplicate with a 409 naming the product that holds the code, which is the
 * answer a person gets. This index is for the case that guard cannot see: two requests creating the same barcode
 * at once, each reading before the other writes.
 */
final class Version20260922050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product barcode is unique within its company when set.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_product_barcode ON product (company_id, barcode) WHERE barcode IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_product_barcode');
    }
}

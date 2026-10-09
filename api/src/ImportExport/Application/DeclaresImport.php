<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * A module says what of its own can be imported, from inside its own directory, the way it already declares its
 * settings and its manifest. Every implementation is collected into the one catalogue, so adding customers, products,
 * vendors or opening stock never touches the import engine.
 */
#[AutoconfigureTag('app.import.declarations')]
interface DeclaresImport
{
    /** Stable, lowercase, plural: what the URL and the file name say — `customers`, `products`. */
    public function key(): string;

    /**
     * The permission that writes what is imported: the subject's own (`customer.write`), never a separate import
     * permission, so whoever may add one customer by hand may add many from a file, and nobody else.
     */
    public function permission(): string;

    /** The key of the module the subject belongs to: switched off, its subject cannot be imported either. */
    public function module(): string;

    /** The columns as they are for THIS company: its preset's registration numbers, its own custom fields. */
    public function subjectFor(Company $company): ImportSubject;

    /**
     * The columns a row is found again by — a customer's number, a product's reference, or a PAIR, as a quantity of
     * stock is found again by its product AND the place it sits in. Two rows of one file naming the same values are
     * one thing twice, and the second is rejected before it reaches import().
     *
     * @return non-empty-list<string>
     */
    public function identityColumns(): array;

    /**
     * What this row names, for finding the same thing twice in one file: usually its identity columns as written
     * (`RowIdentity::ofColumns`), but a subject that finds a row by one column OR another (a reference, else a unit
     * code) says so here, so a row naming its product by code alone is still caught the second time. Null when the row
     * names nothing, which the subject then refuses for itself.
     */
    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity;

    /**
     * Creates or, in upsert mode, updates what one row describes, through the same use case a person's form uses.
     * Runs inside the import's unit of work, which RunImport rolls back for a preview or a file with a rejected row.
     *
     * What the person should know about a row it imports, it notes in $notes; the switches ticked and the run's id are
     * in $context.
     *
     * @throws RowRejected naming the column at fault, for anything the row asks that the subject's rules refuse
     */
    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported;

    /**
     * Once every row is written and the file is about to be committed — never for a preview or a refused file: what is
     * said once for the whole file rather than once per row, such as one notification for the stock it moved.
     */
    public function finished(Company $company, ImportContext $context, ?Uuid $actorUserId): void;
}

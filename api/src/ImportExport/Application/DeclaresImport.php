<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A module says what of its own can be imported, from inside its own directory, the way it already declares its
 * settings and its manifest. Every implementation is collected into the one catalogue, so adding customers, products,
 * vendors or opening stock never touches the import engine.
 */
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
     * Creates or, in upsert mode, updates what one row describes, through the same use case a person's form uses.
     * Runs inside the import's unit of work, which RunImport rolls back for a preview or a file with a rejected row.
     *
     * @throws RowRejected naming the column at fault, for anything the row asks that the subject's rules refuse
     */
    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId): RowImported;
}

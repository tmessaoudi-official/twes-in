<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Tenancy\Domain\Company;

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
}

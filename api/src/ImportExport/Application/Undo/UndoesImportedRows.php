<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * A module takes back for itself the rows an import created that are no record a person deletes (a product's link to
 * a vendor, the vendor's code the file wrote), through its own rules, and says what it kept and why.
 */
#[AutoconfigureTag('app.import.undoes_rows')]
interface UndoesImportedRows
{
    /** @return non-empty-list<string> the tables whose created rows it takes back */
    public function tables(): array;

    /**
     * @param array<string, list<Uuid>> $created the rows of its tables the import created, by table
     *
     * @return list<array{label: string, reason: string}> what it kept
     */
    public function undo(Company $company, array $created, ?Uuid $actorUserId): array;
}

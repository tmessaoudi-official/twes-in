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
 * A module puts back for itself the rows an import removed (ImportContext::removed: a vendor a file replacing a
 * product's vendors dropped, with its codes), through its own rules, and says what it could not put back and why.
 */
#[AutoconfigureTag('app.import.puts_back_removals')]
interface PutsBackImportRemovals
{
    /** @return non-empty-list<string> the tables whose removed rows it puts back */
    public function tables(): array;

    /**
     * @param array<string, list<array{id: Uuid, what: array<string, mixed>}>> $removed the rows of its tables the import
     *                                                                                  removed, by table, in order
     *
     * @return list<array{label: string, reason: string}> what it could not put back
     */
    public function putBack(Company $company, array $removed, ?Uuid $actorUserId): array;
}

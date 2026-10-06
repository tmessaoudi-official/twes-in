<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Application\SourceDeliveryNoteLine;
use App\Module\Invoices\Application\SourceDeliveryNoteLines;
use Symfony\Component\Uid\Uuid;

/** The delivery note lines a test declares, for any company. InvoicesFromDeliveryNotesTest runs the real one. */
final class InMemorySourceDeliveryNoteLines implements SourceDeliveryNoteLines
{
    /** @var list<list<Uuid>> the line ids each lockedOfIds call was asked for, in order */
    public array $locked = [];

    /** @param array<string, SourceDeliveryNoteLine> $lines by delivery note line id */
    public function __construct(public array $lines = [])
    {
    }

    public function lockedOfIds(array $lineIds, Uuid $companyId): array
    {
        $this->locked[] = $lineIds;

        return $this->ofIds($lineIds, $companyId);
    }

    public function ofIds(array $lineIds, Uuid $companyId): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $lineIds);

        return array_filter($this->lines, static fn (string $id): bool => \in_array($id, $wanted, true), \ARRAY_FILTER_USE_KEY);
    }
}

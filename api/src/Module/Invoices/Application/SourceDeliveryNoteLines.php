<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use Symfony\Component\Uid\Uuid;

/**
 * The delivery note lines a draft's lines were taken from, as this module needs them to keep a revision within what the
 * note delivered (docs/SPEC.md § 7, audit 2026-10-06 A-16). A port this module owns, answered by the delivery notes', so
 * neither calls into the other (§ 7, audit C-4).
 */
interface SourceDeliveryNoteLines
{
    /**
     * @param list<Uuid> $lineIds
     *
     * @return array<string, SourceDeliveryNoteLine> the company's delivery note lines among those, by id; another
     *                                               company's line is absent
     */
    public function ofIds(array $lineIds, Uuid $companyId): array;
}

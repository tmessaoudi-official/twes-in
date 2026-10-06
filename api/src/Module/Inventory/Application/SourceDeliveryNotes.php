<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Which delivery notes an invoice's lines came from, as this module needs it: what a note took out is that invoice's
 * sale, and a credit note returns against it (docs/SPEC.md § 7, audit 2026-10-06 E-5). A port this module owns, answered
 * by the delivery notes', so neither calls into the other (§ 7, audit C-4).
 */
interface SourceDeliveryNotes
{
    /**
     * @param list<Uuid> $lineIds delivery note lines, as an invoice's lines name them
     *
     * @return list<Uuid> the company's delivery notes those lines belong to, each once
     */
    public function ofLines(array $lineIds, Uuid $companyId): array;
}

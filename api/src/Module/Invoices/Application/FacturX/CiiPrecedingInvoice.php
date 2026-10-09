<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/** EN 16931 BG-3: an invoice this one refers to, by its number (BT-25) and its issue day (BT-26). */
final readonly class CiiPrecedingInvoice
{
    public function __construct(public string $number, public ?\DateTimeImmutable $issueDate)
    {
    }
}

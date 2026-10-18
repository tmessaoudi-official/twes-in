<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Module\Purchases\Domain\ValueObject\PurchaseOrderHeader;
use Symfony\Component\Uid\Uuid;

/** An order as it is written: its vendor and establishment by id (none: the company's default), its header and its lines. */
final readonly class PurchaseOrderInput
{
    /** @param list<PurchaseOrderLineInput> $lines */
    public function __construct(
        public Uuid $vendorId,
        public ?Uuid $establishmentId,
        public PurchaseOrderHeader $header,
        public array $lines,
    ) {
    }
}

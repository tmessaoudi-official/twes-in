<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use Symfony\Component\Uid\Uuid;

/**
 * An invoice notes may be added to, as far as the notes need to know: whose it is, where it is numbered, whether it is
 * still a draft, and whether it takes other documents' lines at all, which a credit note or a deposit invoice does not.
 */
final readonly class InvoiceToAddTo
{
    public function __construct(public Uuid $id, public Uuid $customerId, public Uuid $establishmentId, public bool $draft, public bool $takesLines)
    {
    }
}

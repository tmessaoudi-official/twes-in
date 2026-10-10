<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasureReference;

/** A recurring invoice copies an invoice, a draft as well as an issued one: erasing drafts leaves that one in place. */
final readonly class RecurringErasureReferences implements DeclaresErasure
{
    public function steps(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            ErasureReference::keep('recurring_invoice', 'model_invoice_id', 'invoice', 'The invoice a recurring invoice copies stays while it does.'),
            ErasureReference::keep('recurring_invoice', 'last_invoice_id', 'invoice', 'The last invoice a recurring invoice made stays while it names it.'),
        ];
    }

    public function files(): array
    {
        return [];
    }
}

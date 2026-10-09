<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Invoices\Domain\OperationCategory;

/** What issuing a draft now would give it, said before it is done: its number and, where the law asks, its operations. */
final readonly class IssuePreview
{
    /** @param OperationCategory|null $operations null where the law asks none, or when nothing says what they are yet */
    public function __construct(public string $number, public ?OperationCategory $operations)
    {
    }
}

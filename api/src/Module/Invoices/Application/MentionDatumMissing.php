<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * A legal mention the document must print cannot be written, because what it states is not given: issuing is refused
 * rather than numbering a document that drops it (docs/SPEC.md § 7, 2026-09-21 18:30). `datum` is the setting to give,
 * or `mention.<placeholder>` for a wording that waits for something no setting gives.
 */
final class MentionDatumMissing extends \DomainException
{
    public function __construct(public readonly string $mention, public readonly string $datum)
    {
        parent::__construct(\sprintf('The mention %s cannot be printed without %s.', $mention, $datum));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain\Exception;

/** A move a delivery note's status does not allow: only a validated note is delivered, a delivered one never cancelled. */
final class DeliveryNoteTransitionRefused extends \DomainException
{
    /**
     * @param string                    $reason a stable code the screen says in its own words, the English message being for logs
     * @param array<string, string|int> $params that text's parameters, such as the note's `reference`
     */
    public function __construct(string $message, public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Receivers;

/** Why a typed receiver's name could not become a contact: its stable reason, that text's parameters, the English message. */
final readonly class ReceiverContactRefused
{
    /** @param array<string, string|int> $params */
    public function __construct(public string $reason, public string $message, public array $params = [])
    {
    }
}

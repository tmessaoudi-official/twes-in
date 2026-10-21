<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * A consequence of a request beyond what it was asked to do (a receipt that moved a price, a count that booked a
 * difference), said to the person who made the request in its own answer, so their screen says it at once whichever
 * page sent it. The kind names what happened and the screen words it; the params carry only what the sentence or its
 * « Annuler » needs, and nothing the person could not read through the API.
 */
final readonly class Effect
{
    /**
     * @param array<string, string|int|bool|null> $params
     */
    public function __construct(
        public string $kind,
        public ?Uuid $id,
        public array $params = [],
    ) {
    }
}

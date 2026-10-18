<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use Symfony\Component\Uid\Uuid;

/** A place goods of an order may be put away at, and the place it sits under, so a screen can say its path. */
final readonly class ReceivingPlace
{
    public function __construct(
        public Uuid $id,
        public ?Uuid $parentId,
        public string $code,
        public string $name,
        public bool $isDefault,
    ) {
    }
}

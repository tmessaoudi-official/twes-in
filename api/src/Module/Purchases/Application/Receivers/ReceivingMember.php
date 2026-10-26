<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application\Receivers;

use Symfony\Component\Uid\Uuid;

/** A member of the company as a receipt offers them to name who received: their account and their name, nothing else. */
final readonly class ReceivingMember
{
    public function __construct(public Uuid $userId, public string $name)
    {
    }
}

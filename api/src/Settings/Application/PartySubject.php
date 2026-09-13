<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use Symfony\Component\Uid\Uuid;

/** A customer of the company, with the group whose defaults it inherits when it is in one. */
final readonly class PartySubject
{
    public function __construct(public Uuid $customerId, public ?Uuid $customerGroupId)
    {
    }
}

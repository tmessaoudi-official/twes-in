<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use Symfony\Component\Uid\Uuid;

/** A company the person is the only owner of, named as its members know it. */
final readonly class OwnedAlone
{
    public function __construct(public Uuid $companyId, public string $name)
    {
    }
}

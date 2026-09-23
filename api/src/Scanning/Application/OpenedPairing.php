<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Application;

use Symfony\Component\Uid\Uuid;

final readonly class OpenedPairing
{
    public function __construct(public Uuid $id, public string $link)
    {
    }
}

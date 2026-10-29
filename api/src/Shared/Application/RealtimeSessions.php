<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/** The sessions of an account a realtime connection may still be open for, each known to Centrifugo by its own subject. */
interface RealtimeSessions
{
    /** @return list<Uuid> */
    public function of(Uuid $userId): array;
}

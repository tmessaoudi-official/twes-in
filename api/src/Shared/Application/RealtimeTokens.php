<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * What a browser needs to open its real-time connection. The token decides which channels the connection hears:
 * the user's own and, when one is being worked in, the company's. The browser never names a channel itself.
 */
interface RealtimeTokens
{
    public function issue(Uuid $userId, ?Uuid $workingCompanyId): RealtimeToken;
}

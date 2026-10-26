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
 * the user's own, the company's when one is being worked in, and the platform's for an operator. The browser never
 * names a channel itself.
 */
interface RealtimeTokens
{
    public const string PLATFORM = 'platform';

    public function issue(Uuid $userId, ?Uuid $workingCompanyId, bool $platformOperator = false): RealtimeToken;

    /**
     * A token for a connection that is not a person's: a paired phone hears its pairing's channel alone.
     *
     * @param list<string> $channels
     */
    public function issueFor(string $subject, array $channels): RealtimeToken;
}

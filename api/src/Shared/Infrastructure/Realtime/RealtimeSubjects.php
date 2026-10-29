<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use Symfony\Component\Uid\Uuid;

/**
 * What Centrifugo knows a connection's person by: the account and the session that asked its token, so a session's
 * end closes its connections and no other's. A token asked with no session recorded is the account's alone. The
 * channels a connection hears come from the token's claim, never from this subject.
 */
final class RealtimeSubjects
{
    public static function of(Uuid $userId, ?Uuid $sessionId): string
    {
        return null === $sessionId ? $userId->toRfc4122() : $userId->toRfc4122().'.'.$sessionId->toRfc4122();
    }
}

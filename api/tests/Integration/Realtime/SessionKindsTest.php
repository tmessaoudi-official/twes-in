<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Realtime;

use App\Identity\Infrastructure\ApiPlatform\Output\MeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The shell reads the session again on the kinds the web lists as `SESSION_KINDS`, which must be the kinds the API
 * says move the session (`MeFactory::CHANGED_BY`): a kind missing from the web left the members' notice bar on an old
 * subscription until they signed out (workflow hunt, B3).
 */
final class SessionKindsTest extends TestCase
{
    public function testTheShellReadsTheSessionAgainOnEveryKindThatMovesIt(): void
    {
        $web = (string) file_get_contents(\dirname(__DIR__, 4).'/web/src/app/shared/session/session-kinds.ts');
        if (1 !== preg_match('/SESSION_KINDS: readonly string\[\] = \[(.*?)\];/s', $web, $list)) {
            self::fail('SESSION_KINDS is not found in session-kinds.ts');
        }
        preg_match_all("/'([a-z_]+)'/", $list[1], $heard);
        $kinds = MeFactory::CHANGED_BY;
        sort($kinds);
        $listed = $heard[1];
        sort($listed);

        self::assertGreaterThanOrEqual(7, \count($kinds));
        self::assertSame($kinds, $listed);
    }
}

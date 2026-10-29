<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Inbox;

use App\Inbox\Application\NotificationKind;
use App\Inbox\Application\NotificationKinds;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bell knows exactly the kinds the API tells, both ways: a kind it does not know reads « Nouvelle notification », and
 * one it knows that nothing tells is a sentence nobody will ever read.
 */
final class NotificationTypesOnScreenTest extends KernelTestCase
{
    private const string TYPES = __DIR__.'/../../../../web/src/app/notifications/notifications-types.ts';

    public function testTheBellKnowsEveryKindTheApiTellsAndNoOther(): void
    {
        $source = (string) file_get_contents(self::TYPES);
        if (1 !== preg_match('/KNOWN_NOTIFICATION_TYPES = \[(.*?)\] as const/s', $source, $list)) {
            self::fail('KNOWN_NOTIFICATION_TYPES was not found in '.self::TYPES);
        }
        preg_match_all("/'([a-z_]+\\.[a-z_.]+)'/", $list[1], $known);
        $onScreen = $known[1];
        sort($onScreen);
        self::assertGreaterThan(30, \count($onScreen), 'the list was read');

        $told = array_map(static fn (NotificationKind $kind): string => $kind->type, static::getContainer()->get(NotificationKinds::class)->all());
        sort($told);

        self::assertSame([], array_values(array_diff($told, $onScreen)), 'told by the API, unknown to the bell');
        self::assertSame([], array_values(array_diff($onScreen, $told)), 'known to the bell, told by nothing');
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Inbox\Application;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Inbox\Application\NotificationKinds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Every kind of notification a context publishes is declared once, with who may choose how it is told. */
#[CoversClass(NotificationKinds::class)]
final class NotificationKindsTest extends TestCase
{
    public function testAKindIsOfferedToAMemberWhoseRoleReceivesItWhileItsModuleIsOn(): void
    {
        $kinds = self::catalogue([
            new NotificationKind('stock.low', NotificationAudience::Company, module: 'inventory', permission: 'stock.write'),
            new NotificationKind('invitation.accepted', NotificationAudience::Company),
            new NotificationKind('subscription.payment_decided', NotificationAudience::Company, role: 'owner'),
            new NotificationKind('invitation.received', NotificationAudience::Personal),
            new NotificationKind('subscription.payment_declared', NotificationAudience::Platform),
        ]);

        $everything = static fn (string $permission): bool => true;
        $nothing = static fn (string $permission): bool => false;
        $on = static fn (string $module): bool => true;
        $off = static fn (string $module): bool => false;

        self::assertSame(['invitation.accepted', 'stock.low', 'subscription.payment_decided'], self::types($kinds->ofCompany('owner', $everything, $on)));
        self::assertSame(['invitation.accepted', 'stock.low'], self::types($kinds->ofCompany('admin', $everything, $on)), 'only an owner is told how a payment was decided');
        self::assertSame(['invitation.accepted'], self::types($kinds->ofCompany('member', $nothing, $on)), 'a role that never receives a kind is not offered it');
        self::assertSame(['invitation.accepted', 'subscription.payment_decided'], self::types($kinds->ofCompany('owner', $everything, $off)), 'a module switched off tells nothing');
        self::assertSame(['invitation.received'], self::types($kinds->personal()));
        self::assertSame(['subscription.payment_declared'], self::types($kinds->ofPlatform()));
        self::assertTrue($kinds->has('stock.low'));
        self::assertFalse($kinds->has('membership.removed'));
    }

    public function testAKindDeclaredTwiceIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stock.low');

        self::catalogue([new NotificationKind('stock.low', NotificationAudience::Company), new NotificationKind('stock.low', NotificationAudience::Personal)]);
    }

    /**
     * @param list<NotificationKind> $kinds
     *
     * @return list<string>
     */
    private static function types(array $kinds): array
    {
        return array_map(static fn (NotificationKind $kind): string => $kind->type, $kinds);
    }

    /** @param list<NotificationKind> $kinds */
    private static function catalogue(array $kinds): NotificationKinds
    {
        return new NotificationKinds([new readonly class($kinds) implements DeclaresNotificationKinds {
            /** @param list<NotificationKind> $kinds */
            public function __construct(private array $kinds)
            {
            }

            public function notificationKinds(): array
            {
                return $this->kinds;
            }
        }]);
    }
}

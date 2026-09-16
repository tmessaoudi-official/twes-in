<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Module\Inventory\Application\TellStockKeepers;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryNotifications;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TellStockKeepersTest extends TestCase
{
    public function testOnlyTheMembersWhoKeepTheCompanysStockAreTold(): void
    {
        $memberships = new InMemoryMemberships();
        $notifications = new InMemoryNotifications();
        $acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $owner = $this->member($memberships, 'owner@acme.test', $acme, [Permission::WILDCARD]);
        $keeper = $this->member($memberships, 'keeper@acme.test', $acme, ['stock.read', 'stock.write']);
        $this->member($memberships, 'reader@acme.test', $acme, ['stock.read', 'delivery_note.validate']);
        $this->member($memberships, 'keeper@globex.test', $globex, ['stock.write']);
        $tell = new TellStockKeepers($memberships, $notifications);
        $noteId = Uuid::v7();

        $tell->deliveryNoteLeftLinesOut($acme->getId(), $noteId, 'BL-2026-00007');
        $tell->deliveryNoteMovedNoStock($acme->getId(), $noteId, 'BL-2026-00008');

        self::assertSame([
            ['user:'.$owner->toRfc4122(), TellStockKeepers::LINES_LEFT_OUT, 'BL-2026-00007'],
            ['user:'.$keeper->toRfc4122(), TellStockKeepers::LINES_LEFT_OUT, 'BL-2026-00007'],
            ['user:'.$owner->toRfc4122(), TellStockKeepers::MOVED_NO_STOCK, 'BL-2026-00008'],
            ['user:'.$keeper->toRfc4122(), TellStockKeepers::MOVED_NO_STOCK, 'BL-2026-00008'],
        ], array_map(static fn ($n) => [$n->channel, $n->type, $n->payload['number']], $notifications->published));
        self::assertSame(['delivery_note_id' => $noteId->toRfc4122(), 'number' => 'BL-2026-00008', 'company' => 'Acme'], $notifications->published[3]->payload);
    }

    /** @param list<string> $permissions */
    private function member(InMemoryMemberships $memberships, string $email, Company $company, array $permissions): Uuid
    {
        $user = new User(Email::fromString($email), 'Someone');
        $memberships->save(new Membership($user, $company, new Role(Role::MEMBER, $permissions, $company)));

        return $user->getId();
    }
}

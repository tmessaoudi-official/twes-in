<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Account\AccountNotFound;
use App\Identity\Application\Account\ManageAccounts;
use App\Identity\Application\Account\OwnAccount;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ManageAccounts::class)]
final class ManageAccountsTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryAuditTrail $audit;
    private ManageAccounts $accounts;
    private User $operator;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->audit = new InMemoryAuditTrail();
        $this->accounts = new ManageAccounts($this->users, $this->audit);
        $this->operator = $this->account('op@twes.local', 'Operator');
    }

    public function testAccountsAreFoundByPartOfTheirAddressOrNameInAddressOrder(): void
    {
        $this->account('zoe@elsewhere.test', 'Zoé Acme');
        $this->account('nadia@acme.test', 'Nadia');
        $this->account('sami@other.test', 'Sami');

        $found = $this->accounts->find('ACME', 50);

        self::assertSame(['nadia@acme.test', 'zoe@elsewhere.test'], array_map(static fn ($a) => $a->email, $found));
        self::assertSame('Nadia', $found[0]->displayName);
        self::assertTrue($found[0]->active);
        self::assertFalse($found[0]->platformOperator);
        self::assertCount(2, $this->accounts->find('', 2));
    }

    public function testEndingAnAccountsSessionsRotatesItsStampAndIsAudited(): void
    {
        $user = $this->account('nadia@acme.test', 'Nadia');
        $stamp = $user->getSecurityStamp();

        $this->accounts->endSessions($user->getId(), $this->operator->getId());

        self::assertNotSame($stamp, $user->getSecurityStamp());
        self::assertTrue($user->isActive());
        $this->assertAudited([ManageAccounts::SESSIONS_ENDED], $user);
    }

    public function testDeactivatingEndsTheSessionsAndIsAuditedOnce(): void
    {
        $user = $this->account('nadia@acme.test', 'Nadia');
        $stamp = $user->getSecurityStamp();

        $view = $this->accounts->deactivate($user->getId(), $this->operator->getId());
        $this->accounts->deactivate($user->getId(), $this->operator->getId());

        self::assertFalse($view->active);
        self::assertFalse($user->isActive());
        self::assertNotSame($stamp, $user->getSecurityStamp());
        $this->assertAudited([ManageAccounts::DEACTIVATED], $user);
    }

    public function testAnOperatorCannotDeactivateTheirOwnAccount(): void
    {
        try {
            $this->accounts->deactivate($this->operator->getId(), $this->operator->getId());
            self::fail('an operator deactivated their own account');
        } catch (OwnAccount) {
        }

        self::assertTrue($this->operator->isActive());
        self::assertSame([], $this->audit->entries);
    }

    public function testReactivatingOpensTheAccountAgainAndIsAuditedOnce(): void
    {
        $user = $this->account('nadia@acme.test', 'Nadia');
        $user->setActive(false);

        $view = $this->accounts->reactivate($user->getId(), $this->operator->getId());
        $this->accounts->reactivate($user->getId(), $this->operator->getId());

        self::assertTrue($view->active);
        self::assertTrue($user->isActive());
        $this->assertAudited([ManageAccounts::REACTIVATED], $user);
    }

    public function testAnUnknownAccountIsNotFoundWhateverIsAsked(): void
    {
        foreach (['endSessions', 'deactivate', 'reactivate'] as $action) {
            try {
                $this->accounts->{$action}(Uuid::v7(), $this->operator->getId());
                self::fail($action.' reached an account that does not exist');
            } catch (AccountNotFound) {
            }
        }

        self::assertSame([], $this->audit->entries);
    }

    private function account(string $email, string $name): User
    {
        $user = new User(Email::fromString($email), $name);
        $this->users->save($user);

        return $user;
    }

    /** @param list<string> $actions */
    private function assertAudited(array $actions, User $user): void
    {
        self::assertSame($actions, array_map(static fn ($e) => $e->action, $this->audit->entries));
        foreach ($this->audit->entries as $entry) {
            self::assertSame('user', $entry->entityType);
            self::assertTrue($user->getId()->equals($entry->entityId));
            self::assertSame($this->operator->getId(), $entry->actorUserId);
            self::assertNull($entry->companyId);
        }
    }
}

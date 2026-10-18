<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Account\AccountNotFound;
use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Application\Account\ManageAccounts;
use App\Identity\Application\Account\OwnAccount;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\AccountSearch;
use App\Identity\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tests\Support\FakeTransactions;
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
    /** @var list<Uuid> */
    private array $demos = [];

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $demos = new class(fn (): array => $this->demos) implements DemoAccounts {
            /** @param \Closure(): list<Uuid> $demos */
            public function __construct(private \Closure $demos)
            {
            }

            public function isDemo(Uuid $userId): bool
            {
                return array_any($this->all(), static fn (Uuid $id): bool => $id->equals($userId));
            }

            public function all(): array
            {
                return ($this->demos)();
            }
        };
        $this->accounts = new ManageAccounts($this->users, $this->audit, $transactions, $demos);
        $this->operator = $this->account('op@twes.local', 'Operator');
    }

    public function testAccountsAreFoundByPartOfTheirAddressOrNameInAddressOrder(): void
    {
        $this->account('zoe@elsewhere.test', 'Zoé Acme');
        $this->account('nadia@acme.test', 'Nadia');
        $this->account('sami@other.test', 'Sami');

        $found = $this->accounts->search(new AccountSearch('ACME'), new PageRequest(1, 50));

        self::assertSame(['nadia@acme.test', 'zoe@elsewhere.test'], array_map(static fn ($a) => $a->email, $found->items));
        self::assertSame(2, $found->total);
        self::assertSame('Nadia', $found->items[0]->displayName);
        self::assertTrue($found->items[0]->active);
        self::assertFalse($found->items[0]->platformOperator);
        $page = $this->accounts->search(new AccountSearch(), new PageRequest(1, 2));
        self::assertCount(2, $page->items);
        self::assertSame(4, $page->total, 'a page says how many rows the whole list holds');
    }

    public function testADemosThrowawayOwnerIsNeverFoundNorCounted(): void
    {
        $this->account('nadia@acme.test', 'Nadia');
        $this->demos[] = $this->account('demo-1@demo.twes.invalid', 'Visiteur ACME')->getId();

        $found = $this->accounts->search(new AccountSearch('acme'), new PageRequest(1, 50));

        self::assertSame(['nadia@acme.test'], array_map(static fn ($a) => $a->email, $found->items));
        self::assertSame(1, $found->total);
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
        $user = new User(Email::fromString($email), $name, 'fr');
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

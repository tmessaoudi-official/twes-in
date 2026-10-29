<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Account\ManageAccounts;
use App\Identity\Application\Login\FailedLoginAttempt;
use App\Identity\Application\Login\LiftPasswordLock;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Application\Password\PasswordResetMailer;
use App\Identity\Application\Password\RequestPasswordReset;
use App\Identity\Application\Password\ResetPassword;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Shared\Application\RealtimePublisher;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\InMemoryPasswordResetMailer;
use App\Tests\Support\RecordingRealtimePublisher;

/**
 * What an account's row shows on a company's members list and on the platform's accounts (its password lock, whether it
 * may sign in) changes outside any company: a guess that locks it, an owner or the operator lifting it, the mailed link,
 * the operator deactivating it. Each is said to every company the account belongs to and to the platform, so a page left
 * open there shows it at once rather than at the next reload.
 */
final class AccountChangesSaidTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private RecordingRealtimePublisher $publisher;

    private InMemoryPasswordResetMailer $mailer;

    private Company $acme;

    private Company $globex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->acme = $this->createCompany('Acme');
        $this->globex = $this->createCompany('Globex');
    }

    public function testTheGuessThatLocksAPasswordIsSaidToEachCompanyOfTheAccountAndToThePlatform(): void
    {
        $clerk = $this->clerkOfBoth();
        $after = static::getContainer()->getParameter('app.auth.lock_after_failures');
        $this->listening();

        for ($i = 1; $i < $after; ++$i) {
            $this->guess($clerk);
        }
        self::assertSame([], $this->said($clerk), 'a guess that sets no lock changes nothing a list shows');

        $this->guess($clerk);

        self::assertSame($this->everywhere(), $this->said($clerk));
    }

    public function testALockLiftedByAnOwnerOrTheOperatorOrTheMailedLinkIsSaidWhereTheAccountIsShown(): void
    {
        $clerk = $this->clerkOfBoth();
        $owner = $this->createUser('owner@twes.local', self::PASSWORD, $this->acme, ['*'], 'owner');
        $this->listening();
        $this->locked($clerk);
        $lift = static::getContainer()->get(LiftPasswordLock::class);

        self::assertTrue($lift->lift($clerk->getId(), $owner->getId(), $this->acme->getId(), SecurityChange::LockLiftedByOwner));
        self::assertSame($this->everywhere(), $this->said($clerk, LiftPasswordLock::LIFTED));

        $this->publisher->pushed = [];
        $this->locked($clerk);
        self::assertTrue($lift->lift($clerk->getId(), $owner->getId(), null, SecurityChange::LockLiftedByOperator));
        self::assertSame($this->everywhere(), $this->said($clerk, LiftPasswordLock::LIFTED));

        $this->publisher->pushed = [];
        $this->locked($clerk);
        $mailer = $this->mailer;
        static::getContainer()->get(RequestPasswordReset::class)->handle(Email::fromString('clerk@twes.local'), 'fr');
        self::assertCount(1, $mailer->sent);
        static::getContainer()->get(ResetPassword::class)->handle(basename($mailer->sent[0]->resetUrl), 'a-new-password-5678');
        self::assertSame($this->everywhere(), $this->said($clerk, ResetPassword::RESET));
    }

    public function testAnAccountTheOperatorDeactivatesOrReactivatesIsSaidWhereItIsShown(): void
    {
        $clerk = $this->clerkOfBoth();
        $operator = $this->createUser('operator@twes.local', self::PASSWORD);
        $this->listening();
        $accounts = static::getContainer()->get(ManageAccounts::class);

        $accounts->deactivate($clerk->getId(), $operator->getId());
        $accounts->reactivate($clerk->getId(), $operator->getId());

        self::assertSame($this->everywhere(), $this->said($clerk, ManageAccounts::DEACTIVATED));
        self::assertSame($this->everywhere(), $this->said($clerk, ManageAccounts::REACTIVATED));
    }

    /** An account in no company is listed on the platform all the same: it hears it there, and on the account's own tabs. */
    public function testALockOnAnAccountInNoCompanyIsSaidToThePlatform(): void
    {
        $loner = $this->createUser('loner@twes.local', self::PASSWORD);
        $this->listening();

        $this->locked($loner);

        self::assertSame(['platform', 'user:'.$loner->getId()->toRfc4122()], $this->said($loner));
    }

    private function clerkOfBoth(): User
    {
        $clerk = $this->createUser('clerk@twes.local', self::PASSWORD, $this->acme, roleName: 'member');
        $this->addMembership($clerk, $this->globex, 'member');

        return $clerk;
    }

    /** A kernel of its own, as the worker's, whose live changes the recorder hears. */
    private function listening(): void
    {
        static::ensureKernelShutdown();
        $this->publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $this->publisher);
        $this->mailer = new InMemoryPasswordResetMailer();
        static::getContainer()->set(PasswordResetMailer::class, $this->mailer);
    }

    private function guess(User $user): void
    {
        static::getContainer()->get(RecordFailedLogin::class)->handle(new FailedLoginAttempt($user->getId(), $user->getEmail()->value, 'invalid_credentials', true));
    }

    private function locked(User $user): void
    {
        $after = static::getContainer()->getParameter('app.auth.lock_after_failures');
        for ($i = 0; $i < $after; ++$i) {
            $this->guess($user);
        }
    }

    /** @return list<string> the channels the account's change was said on, sorted */
    private function everywhere(): array
    {
        $channels = ['company:'.$this->acme->getId()->toRfc4122(), 'company:'.$this->globex->getId()->toRfc4122(), 'platform'];
        sort($channels);

        return $channels;
    }

    /** @return list<string> the channels a change of this account's kind was said on, sorted and once each */
    private function said(User $user, ?string $action = null): array
    {
        $channels = [];
        foreach ($this->publisher->pushed as $push) {
            $data = $push['data'];
            if ('account' === ($data['kind'] ?? null) && $user->getId()->toRfc4122() === ($data['id'] ?? null) && (null === $action || $action === ($data['action'] ?? null))) {
                $channels[] = $push['channel'];
            }
        }
        $channels = array_values(array_unique($channels));
        sort($channels);

        return $channels;
    }
}

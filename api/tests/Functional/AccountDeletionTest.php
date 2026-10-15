<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Account\FinalizeAccountDeletions;
use App\Identity\Domain\User;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * A person deletes their own account (docs/plans/threat-model.plan.md, Decisions Log 2026-10-13 12:22 to 12:39). The
 * account closes at once and its sessions end, a mail says so, and signing in again within the days the platform sets
 * (7 by default) cancels it; after them the nightly run deletes it and takes its address out of the journal. The only
 * owner of a company is refused and told which companies, for they would be left with no one to run them.
 */
final class AccountDeletionTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';
    private const string LEAVING = 'leaving@twes.local';

    private Company $company;
    private User $leaving;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', self::PASSWORD, $this->company);
        $this->leaving = $this->createUser(self::LEAVING, self::PASSWORD, $this->company, ['customer.read'], Role::MEMBER);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testNothingHappensUntilThePersonProvesWhoTheyAreAgain(): void
    {
        $this->login(self::LEAVING, self::PASSWORD);

        $this->sendJson('DELETE', '/api/auth/account');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));
        self::assertFalse($this->reload($this->leaving)->isDeletionPending());
    }

    public function testTheOnlyOwnerOfACompanyIsRefusedAndToldWhichCompanies(): void
    {
        $alone = $this->createCompany('Seule SARL');
        $this->addMembership($this->leaving, $alone, Role::OWNER);
        $shared = $this->createCompany('Partagée SARL');
        $this->createUser('partner@twes.local', self::PASSWORD, $shared);
        $this->em()->persist(new Membership($this->leaving, $shared, $this->em()->getRepository(Role::class)->findOneBy(['company' => $shared]) ?? throw new \LogicException('no owner role')));
        $this->em()->flush();
        $this->login(self::LEAVING, self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->sendJson('DELETE', '/api/auth/account');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $body = $this->json();
        self::assertSame('sole_owner', $this->stringAt($body, 'error'));
        self::assertSame([['id' => $alone->getId()->toRfc4122(), 'name' => 'Seule SARL']], $body['companies'] ?? null);
        self::assertFalse($this->reload($this->leaving)->isDeletionPending());
    }

    public function testAPlatformOperatorIsRefused(): void
    {
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);
        $this->login('operator@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->sendJson('DELETE', '/api/auth/account');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('platform_operator', $this->stringAt($this->json(), 'error'));
    }

    public function testTheOnlyOwnerOfAClosedCompanyMayGo(): void
    {
        $closed = $this->createCompany('Fermée SARL');
        $this->addMembership($this->leaving, $closed, Role::OWNER);
        $this->em()->getConnection()->executeStatement("UPDATE company SET status = 'closed', closed_down_at = now(), retain_until = '2036-12-31' WHERE id = ?", [$closed->getId()->toRfc4122()]);
        $this->login(self::LEAVING, self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->sendJson('DELETE', '/api/auth/account');

        self::assertResponseIsSuccessful('a closed company needs no one to run it');
    }

    public function testTheAccountClosesAtOnceAndAMailSaysWhenItIsFinal(): void
    {
        $this->login(self::LEAVING, self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->sendJson('DELETE', '/api/auth/account');

        self::assertResponseIsSuccessful();
        $finalAt = new \DateTimeImmutable($this->stringAt($this->json(), 'finalAt'));
        self::assertEqualsWithDelta((new \DateTimeImmutable('+7 days'))->getTimestamp(), $finalAt->getTimestamp(), 60);
        self::assertTrue($this->reload($this->leaving)->isDeletionPending());
        self::assertSame(1, $this->journalCount('account.deletion_requested'));
        // The mailer's record is the last request's, so it is read before the next one.
        $mails = array_values(array_filter(self::getMailerMessages(), static fn ($mail): bool => $mail instanceof MimeEmail));
        self::assertCount(1, $mails);
        self::assertSame(self::LEAVING, $mails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('7', (string) $mails[0]->getHtmlBody());

        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'the session that asked is over too');
    }

    public function testTheMailWritesTheFinalDayInThePersonsOwnOrder(): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(userId: $this->leaving->getId()), 'presentation.date-format', SettingLevel::User, 'mdy', $this->leaving->getId());
        $this->login(self::LEAVING, self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->sendJson('DELETE', '/api/auth/account');

        self::assertResponseIsSuccessful();
        $finalAt = new \DateTimeImmutable($this->stringAt($this->json(), 'finalAt'));
        $mail = array_values(array_filter(self::getMailerMessages(), static fn ($mail): bool => $mail instanceof MimeEmail))[0] ?? null;
        self::assertInstanceOf(MimeEmail::class, $mail);
        self::assertStringContainsString($finalAt->format('m/d/Y'), (string) $mail->getHtmlBody());
    }

    public function testSigningInAgainWithinTheDaysCancelsIt(): void
    {
        $this->askToGo();

        $this->login(self::LEAVING, self::PASSWORD);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload($this->leaving)->isDeletionPending());
        self::assertSame(1, $this->journalCount('account.deletion_cancelled'));
    }

    public function testAWrongPasswordNeitherCancelsItNorSaysSo(): void
    {
        $this->askToGo();

        $this->postJson('/api/auth/login', ['email' => self::LEAVING, 'password' => 'not-the-password']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('invalid_credentials', $this->stringAt($this->json(), 'error'));
        self::assertTrue($this->reload($this->leaving)->isDeletionPending());
    }

    public function testTheNightlyRunDeletesItOnlyOnceItsDaysHavePassed(): void
    {
        $this->askToGo();
        $id = $this->leaving->getId();

        Clock::set(new MockClock(new \DateTimeImmutable('+7 days -5 minutes')));
        self::assertSame(0, $this->finalize());
        self::assertNotNull($this->em()->find(User::class, $id));

        Clock::set(new MockClock(new \DateTimeImmutable('+7 days +5 minutes')));
        self::assertSame(1, $this->finalize());
        $this->em()->clear();
        self::assertNull($this->em()->find(User::class, $id));
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM membership WHERE user_id = ?', [$id->toRfc4122()]));
        self::assertSame(1, $this->journalCount('account.deleted'));
    }

    public function testItsAddressLeavesTheJournalAndNothingElseOfIt(): void
    {
        $this->askToGo();
        $before = $this->journalRows();
        self::assertContains(self::LEAVING, array_column($before, 'email'));

        Clock::set(new MockClock(new \DateTimeImmutable('+8 days')));
        $this->finalize();

        $after = $this->journalRows();
        self::assertNotContains(self::LEAVING, array_column($after, 'email'));
        self::assertSame(array_column($before, 'action'), \array_slice(array_column($after, 'action'), 0, \count($before)), 'every entry kept, in its place');
        foreach ($after as $row) {
            self::assertContains($row['email'], [null, 'erased']);
        }
    }

    public function testTheDaysAreThePlatformsToSet(): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(), 'account.deletion_days', SettingLevel::Platform, 3, null);
        $this->askToGo();

        Clock::set(new MockClock(new \DateTimeImmutable('+3 days +5 minutes')));

        self::assertSame(1, $this->finalize());
    }

    public function testAnAccountThatHasBecomeACompanysOnlyOwnerMeanwhileIsKept(): void
    {
        $this->askToGo();
        $owner = $this->em()->getConnection()->fetchOne("SELECT id FROM \"user\" WHERE email = 'owner@twes.local'");
        self::assertIsString($owner);
        $this->em()->getConnection()->executeStatement('UPDATE membership SET role_id = (SELECT role_id FROM membership WHERE user_id = ?) WHERE user_id = ?', [$owner, $this->leaving->getId()->toRfc4122()]);
        $this->em()->getConnection()->executeStatement('DELETE FROM membership WHERE user_id = ?', [$owner]);

        Clock::set(new MockClock(new \DateTimeImmutable('+8 days')));

        self::assertSame(0, $this->finalize());
        $this->em()->clear();
        self::assertTrue($this->reload($this->leaving)->isDeletionPending());
    }

    private function askToGo(): void
    {
        $this->login(self::LEAVING, self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->sendJson('DELETE', '/api/auth/account');
        self::assertResponseIsSuccessful();
    }

    private function finalize(): int
    {
        // The worker starts each run on an empty unit of work; this kernel still holds what the requests loaded.
        $this->em()->clear();

        return static::getContainer()->get(FinalizeAccountDeletions::class)->handle()['deleted'];
    }

    private function journalCount(string $action): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?', [$action, $this->leaving->getId()->toRfc4122()]);
        self::assertIsInt($count);

        return $count;
    }

    /** @return list<array{action: string, email: ?string}> the journal's entries about the leaving account, oldest first */
    private function journalRows(): array
    {
        $rows = [];
        foreach ($this->em()->getConnection()->fetchAllAssociative("SELECT action, changes->>'email' AS email FROM audit_log WHERE entity_id = ? OR changes->>'email' = ? ORDER BY at, id", [$this->leaving->getId()->toRfc4122(), self::LEAVING]) as $row) {
            self::assertIsString($row['action']);
            self::assertTrue(null === $row['email'] || \is_string($row['email']));
            $rows[] = ['action' => $row['action'], 'email' => $row['email']];
        }

        return $rows;
    }
}

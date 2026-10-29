<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Shared\Application\RealtimePublisher;
use App\Shared\Infrastructure\Realtime\LiveChangesAnswer;
use App\Tests\Support\RecordingRealtimePublisher;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session ended anywhere is said at once to the person's own open screens, which ask again whether theirs still
 * stands, rather than finding out at their next click; when every session of theirs ended, their realtime connection
 * is closed too, so nothing more reaches a screen nobody is signed in on (docs/SPEC.md § 7, 2026-10-27 21:37).
 */
final class SessionEndsSaidTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private RecordingRealtimePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $this->publisher);
    }

    public function testAnOperatorEndingAnAccountsSessionsTellsItsScreensAndClosesItsConnection(): void
    {
        $nadia = $this->createUser('nadia@acme.test', self::PASSWORD);
        $operator = $this->signedInAsOperator();

        $this->postJson($this->accountPath($nadia, 'end-sessions'), []);

        self::assertResponseIsSuccessful();
        self::assertSame([$this->sessionEnded()], $this->saidTo($nadia));
        self::assertSame([], $this->saidTo($operator), 'the operator signed nobody of theirs out');
        self::assertSame([], $this->sessionsSaidElsewhere($nadia), 'no company and nobody else hears it');
        self::assertSame([$nadia->getId()->toRfc4122()], $this->publisher->disconnected);
    }

    public function testADeactivationIsSaidTheSameWayAndARefusedOneSaysNothing(): void
    {
        $nadia = $this->createUser('nadia@acme.test', self::PASSWORD);
        $operator = $this->signedInAsOperator();

        $this->postJson($this->accountPath($operator, 'deactivate'), []);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame([], $this->saidTo($operator));
        self::assertSame([], $this->publisher->disconnected);

        $this->postJson($this->accountPath($nadia, 'deactivate'), []);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->sessionEnded()], $this->saidTo($nadia));
        self::assertSame([$nadia->getId()->toRfc4122()], $this->publisher->disconnected);
    }

    /**
     * Ending one's other sessions leaves this one standing: the other screens hear it and find themselves signed out,
     * and the connection stays open for this one, which is not told what it did.
     */
    public function testEndingOnesOtherSessionsTellsTheOtherScreensAndKeepsTheConnection(): void
    {
        $nadia = $this->createUser('nadia@acme.test', self::PASSWORD);
        $this->login('nadia@acme.test', self::PASSWORD);
        $this->getJson('/api/auth/me');
        $this->client->getCookieJar()->clear();
        $this->login('nadia@acme.test', self::PASSWORD);
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();

        [$other, $here] = $this->sessionsOf($nadia);

        $this->sendJson('DELETE', '/api/auth/sessions', server: ['HTTP_X_TAB' => 'tab-here']);

        self::assertResponseIsSuccessful();
        $answered = $this->client->getResponse()->headers->get(LiveChangesAnswer::HEADER) ?? '[]';
        self::assertStringNotContainsString('"session"', $answered, 'the tab that asked is not told what it did');
        self::assertSame([$this->sessionEnded('tab-here')], $this->saidTo($nadia));
        self::assertSame([], $this->sessionsSaidElsewhere($nadia), 'no company and nobody else hears it');
        self::assertSame([$this->subject($nadia, $other)], $this->publisher->disconnected, 'the other session\'s connections closed, this one\'s kept');
        self::assertNotContains($this->subject($nadia, $here), $this->publisher->disconnected);
    }

    /** Signing out closes that session's connections, and nobody else's: not the account's other sessions, not another account's. */
    public function testSigningOutClosesThatSessionsConnectionsAlone(): void
    {
        $this->createUser('mallory@acme.test', self::PASSWORD);
        $this->login('mallory@acme.test', self::PASSWORD);
        $this->getJson('/api/auth/me');
        $this->client->getCookieJar()->clear();
        $nadia = $this->createUser('nadia@acme.test', self::PASSWORD);
        $this->login('nadia@acme.test', self::PASSWORD);
        $this->getJson('/api/auth/me');
        $this->client->getCookieJar()->clear();
        $this->login('nadia@acme.test', self::PASSWORD);
        $this->getJson('/api/auth/me');
        [, $here] = $this->sessionsOf($nadia);

        $this->postJson('/api/auth/logout', null);

        self::assertSame([$this->subject($nadia, $here)], $this->publisher->disconnected);
    }

    /**
     * Wrong guesses that lock a password close every connection of that account, each session's, and nobody else's:
     * whoever reconnects asks a new token through a session that still stands.
     */
    public function testAPasswordLockClosesEveryConnectionOfThatAccountAlone(): void
    {
        $this->createUser('mallory@acme.test', self::PASSWORD);
        $this->login('mallory@acme.test', self::PASSWORD);
        $this->getJson('/api/auth/me');
        $this->client->getCookieJar()->clear();
        $nadia = $this->createUser('nadia@acme.test', self::PASSWORD);
        $this->login('nadia@acme.test', self::PASSWORD);
        $this->getJson('/api/auth/me');
        $this->client->getCookieJar()->clear();
        $after = static::getContainer()->getParameter('app.auth.lock_after_failures');
        for ($i = 0; $i < $after; ++$i) {
            $this->login('nadia@acme.test', 'not-the-password');
        }

        self::assertSame($this->everySubjectOf($nadia), $this->publisher->disconnected);
    }

    /** A new password ends every session, this one too, however the account ends them: said and closed alike. */
    public function testANewPasswordEndsEverySessionAndSaysSo(): void
    {
        $nadia = $this->createUser('nadia@acme.test', self::PASSWORD);
        $this->login('nadia@acme.test', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->getJson('/api/auth/me');

        $this->sendJson('PUT', '/api/auth/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'another-long-password']);

        self::assertResponseIsSuccessful();
        self::assertSame([$this->sessionEnded()], $this->saidTo($nadia));
        self::assertSame($this->everySubjectOf($nadia), $this->publisher->disconnected);
    }

    /**
     * The tab that ended the sessions is named as their origin, so it alone, still signed in, does not ask again.
     *
     * @return array<string, mixed>
     */
    private function sessionEnded(?string $origin = null): array
    {
        return ['type' => 'changed', 'kind' => 'session', 'id' => null, 'action' => 'session.ended', 'actor' => null, 'origin' => $origin];
    }

    /** @return list<string> every other channel that heard of this person's sessions: a company's, someone else's */
    private function sessionsSaidElsewhere(User $user): array
    {
        $channel = 'user:'.$user->getId()->toRfc4122();

        return array_values(array_map(
            static fn (array $push): string => $push['channel'],
            array_filter($this->publisher->pushed, static fn (array $push): bool => $channel !== $push['channel'] && 'session' === ($push['data']['kind'] ?? null)),
        ));
    }

    /** @return list<array<string, mixed>> what the person's own channel heard of their sessions */
    private function saidTo(User $user): array
    {
        $channel = 'user:'.$user->getId()->toRfc4122();

        return array_values(array_map(
            static fn (array $push): array => $push['data'],
            array_filter($this->publisher->pushed, static fn (array $push): bool => $channel === $push['channel'] && 'session' === ($push['data']['kind'] ?? null)),
        ));
    }

    private function signedInAsOperator(): User
    {
        $operator = $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        self::assertResponseIsSuccessful();

        return $operator;
    }

    /** @return list<string> the account's sessions as recorded, oldest first */
    private function sessionsOf(User $user): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM user_session WHERE user_id = ? ORDER BY created_at, id', [$user->getId()->toRfc4122()]);

        return array_map(static fn (mixed $id): string => \is_string($id) ? $id : throw new \LogicException('A session id is a string.'), $ids);
    }

    /** What Centrifugo knows a session's connections by. */
    private function subject(User $user, string $session): string
    {
        return $user->getId()->toRfc4122().'.'.$session;
    }

    /** @return list<string> the account's own subject, for a token issued with no session recorded, then each session's */
    private function everySubjectOf(User $user): array
    {
        return [$user->getId()->toRfc4122(), ...array_map(fn (string $session): string => $this->subject($user, $session), $this->sessionsOf($user))];
    }

    private function accountPath(User $user, string $action): string
    {
        return '/api/platform/accounts/'.$user->getId()->toRfc4122().'/'.$action;
    }
}

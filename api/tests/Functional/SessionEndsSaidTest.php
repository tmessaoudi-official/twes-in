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

        $this->sendJson('DELETE', '/api/auth/sessions', server: ['HTTP_X_TAB' => 'tab-here']);

        self::assertResponseIsSuccessful();
        $answered = $this->client->getResponse()->headers->get(LiveChangesAnswer::HEADER) ?? '[]';
        self::assertStringNotContainsString('"session"', $answered, 'the tab that asked is not told what it did');
        self::assertSame([$this->sessionEnded('tab-here')], $this->saidTo($nadia));
        self::assertSame([], $this->sessionsSaidElsewhere($nadia), 'no company and nobody else hears it');
        self::assertSame([], $this->publisher->disconnected);
    }

    /** A new password ends every session, this one too, however the account ends them: said and closed alike. */
    public function testANewPasswordEndsEverySessionAndSaysSo(): void
    {
        $nadia = $this->createUser('nadia@acme.test', self::PASSWORD);
        $this->login('nadia@acme.test', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', '/api/auth/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'another-long-password']);

        self::assertResponseIsSuccessful();
        self::assertSame([$this->sessionEnded()], $this->saidTo($nadia));
        self::assertSame([$nadia->getId()->toRfc4122()], $this->publisher->disconnected);
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

    private function accountPath(User $user, string $action): string
    {
        return '/api/platform/accounts/'.$user->getId()->toRfc4122().'/'.$action;
    }
}

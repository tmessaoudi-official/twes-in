<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** A person sees where they are signed in and ends a session that is not the one they are using. */
final class ConnectedDevicesTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string PATH = '/api/auth/sessions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->createUser('someone@twes.local', self::PASSWORD);
    }

    /** @return list<Cookie> */
    private function signInAs(string $device): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_USER_AGENT', $device);
        $this->login('someone@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $this->getJson(self::PATH); // the first signed-in request records the session

        return array_values($this->client->getCookieJar()->all());
    }

    /** @param list<Cookie> $cookies */
    private function actAs(array $cookies): void
    {
        $this->client->getCookieJar()->clear();
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
    }

    public function testTheListShowsEachBrowserAndMarksTheOneAsking(): void
    {
        $first = $this->signInAs('Firefox on Linux');
        $second = $this->signInAs('Safari on iPhone');

        $this->getJson(self::PATH);
        $devices = $this->jsonList();

        self::assertCount(2, $devices);
        self::assertSame(['Safari on iPhone'], array_values(array_map(fn (array $d): string => $this->stringAt($d, 'device'), array_filter($devices, fn (array $d): bool => $this->boolAt($d, 'current')))));
        $this->actAs($first);
        $this->getJson(self::PATH);
        self::assertSame(['Firefox on Linux'], array_values(array_map(fn (array $d): string => $this->stringAt($d, 'device'), array_filter($this->jsonList(), fn (array $d): bool => $this->boolAt($d, 'current')))));
        self::assertNotSame($first, $second);
    }

    public function testAnEndedSessionIsSignedOutAtItsNextRequestAndTheOtherStaysIn(): void
    {
        $first = $this->signInAs('Firefox on Linux');
        $this->signInAs('Safari on iPhone');
        $this->getJson(self::PATH);
        $target = array_values(array_filter($this->jsonList(), fn (array $d): bool => !$this->boolAt($d, 'current')))[0];

        $this->sendJson('DELETE', self::PATH.'/'.$this->stringAt($target, 'id'));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful('the session that ended the other one stays in');

        $this->actAs($first);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testASignedOutSessionOrOneAPasswordChangeEndedIsNoLongerListed(): void
    {
        // A logout ends its session and a new password ends them all; the list says so at once, not twelve hours later
        // (audit 2026-10-06, C-F7).
        $firefox = $this->signInAs('Firefox on Linux');
        $safari = $this->signInAs('Safari on iPhone');
        $this->actAs($firefox);
        $this->postJson('/api/auth/logout', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->actAs($safari);
        $this->getJson(self::PATH);
        self::assertSame(['Safari on iPhone'], array_map(fn (array $d): string => $this->stringAt($d, 'device'), $this->jsonList()));

        $this->sendJson('PUT', '/api/auth/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'another-long-password']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_USER_AGENT', 'Chrome on Android');
        $this->login('someone@twes.local', 'another-long-password');
        self::assertResponseIsSuccessful();
        $this->getJson(self::PATH);
        self::assertSame(['Chrome on Android'], array_map(fn (array $d): string => $this->stringAt($d, 'device'), $this->jsonList()));
    }

    public function testEndingTheOthersLeavesTheCurrentOne(): void
    {
        $first = $this->signInAs('Firefox on Linux');
        $this->signInAs('Safari on iPhone');
        $this->signInAs('Edge on Windows');

        $this->sendJson('DELETE', self::PATH);
        self::assertSame(2, $this->json()['ended'] ?? null);

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        $this->actAs($first);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testThisSessionCannotBeEndedFromHereAndAnUnknownOneIsNotFound(): void
    {
        $this->signInAs('Firefox on Linux');
        $this->getJson(self::PATH);
        $mine = $this->stringAt($this->jsonList()[0], 'id');

        $this->sendJson('DELETE', self::PATH.'/'.$mine);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('DELETE', self::PATH.'/018f0000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherAccountsSessionCannotBeEnded(): void
    {
        $this->createUser('other@twes.local', self::PASSWORD);
        $this->signInAs('Firefox on Linux');
        $this->getJson(self::PATH);
        $theirs = $this->stringAt($this->jsonList()[0], 'id');

        $this->client->getCookieJar()->clear();
        $this->login('other@twes.local', self::PASSWORD);
        $this->sendJson('DELETE', self::PATH.'/'.$theirs);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testNobodyNotSignedInReadsOrEndsAnything(): void
    {
        $this->client->getCookieJar()->clear();

        $this->getJson(self::PATH);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->sendJson('DELETE', self::PATH);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}

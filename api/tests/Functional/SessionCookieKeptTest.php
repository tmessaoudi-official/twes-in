<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * No answer deletes the session cookie. A request the browser sent before a sign-in, answered after it, carries the
 * session the sign-in replaced, which the server no longer holds; Symfony answers such a request by deleting the cookie,
 * and the browser then drops the session the sign-in had just given, by its name. The address field's passkey signs in
 * the moment the page loads, beside the page's first reads: one read slower than the sign-in signed the person out.
 */
final class SessionCookieKeptTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testAnAnswerToTheSessionASignInReplacedLeavesTheNewSessionsCookie(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        // The login page's own session: the passkey offer keeps its challenge there.
        $this->postJson('/api/auth/passkey-sign-in/options', null);
        self::assertResponseIsSuccessful();
        $before = $this->sessionCookie();

        $this->login('owner@example.test', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $signedIn = $this->sessionCookie();
        self::assertNotSame($before->getValue(), $signedIn->getValue(), 'the sign-in replaces the session');

        // A read the page sent before the sign-in, answered after it.
        $this->client->getCookieJar()->set($before);
        $this->client->request('GET', '/api/auth/demo');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->sessionCookieCleared(), 'the late answer leaves the cookie to the session that replaced it');

        $this->client->getCookieJar()->set($signedIn);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testNoReadDeletesTheCookieOfASessionTheServerNoLongerHolds(): void
    {
        $this->postJson('/api/auth/passkey-sign-in/options', null);
        $held = $this->sessionCookie();
        $gone = new Cookie($held->getName(), str_repeat('0123456789abcdef', 2), null, $held->getPath(), $held->getDomain(), $held->isSecure(), $held->isHttpOnly(), false, $held->getSameSite());
        foreach (['/api/health', '/api/signup', '/api/auth/demo'] as $path) {
            $this->client->getCookieJar()->set($gone);
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSame([], $this->sessionCookieCleared(), $path.' sends no deletion of the session cookie');
        }
    }

    private function sessionCookie(): Cookie
    {
        $cookie = $this->client->getCookieJar()->get(self::SESSION_COOKIE);
        self::assertNotNull($cookie, 'a session cookie is held');

        return $cookie;
    }

    /** @return list<string> each Set-Cookie of the last answer deleting the session cookie */
    private function sessionCookieCleared(): array
    {
        $cleared = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if (self::SESSION_COOKIE === $cookie->getName() && $cookie->isCleared()) {
                $cleared[] = (string) $cookie;
            }
        }

        return $cleared;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\UserChecker;

/**
 * On a shop's own computer the platform's side answers only from the shop's network or its tailnet: the operators'
 * endpoints and the deep health are not there from anywhere else, and an operator's right password is told so. A
 * server's operators reach it from anywhere. Addresses: 192.168.1.20 the shop's network, 100.101.102.103 and
 * fd7a:115c:a1e0::7 the tailnet, 8.8.4.4 and 2a00:1450::1 anywhere else (documentation ranges are private to Symfony's
 * other list, so a real public address stands in).
 */
final class PlatformReachTest extends ApiTestCase
{
    private const string OUTSIDE = '8.8.4.4';

    private const string SERVER = 'testAServersOperatorsReachItFromAnywhere';

    private const string MONITOR = 'monitor-token-for-the-reach-0123456789';

    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $before = [];

    protected function setUp(): void
    {
        $values = ['APP_INSTALLATION' => self::SERVER === $this->name() ? 'server' : 'onprem', 'MONITOR_TOKEN' => self::MONITOR];
        foreach ($values as $name => $value) {
            $this->before[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->before as $name => $before) {
            unset($_ENV[$name], $_SERVER[$name]);
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
        }
    }

    public function testFromOutsideTheShopThePlatformIsNotThere(): void
    {
        $this->signedInAsOperatorFrom('192.168.1.20');

        // 2002:808:404::1 is 8.8.4.4 over 6to4, a prefix Symfony's wider private list counts as private.
        foreach ([self::OUTSIDE, '2a00:1450::1', '2002:808:404::1'] as $address) {
            $this->from($address);
            foreach (['/api/platform/companies', '/%61pi/platform/companies', '/api/%70latform/companies'] as $path) {
                $this->getJson($path);
                self::assertResponseStatusCodeSame(404, $address.' reached the operators\' list as '.$path);
            }
            $this->deepHealth();
            self::assertResponseStatusCodeSame(404, $address.' reached the deep health');
        }
    }

    public function testFromTheShopsNetworkOrItsTailnetItIs(): void
    {
        $this->signedInAsOperatorFrom('192.168.1.20');

        foreach (['192.168.1.20', '100.101.102.103', 'fd7a:115c:a1e0::7', '127.0.0.1'] as $address) {
            $this->from($address);
            $this->getJson('/api/platform/companies');
            self::assertResponseIsSuccessful($address.' is the shop\'s own');
            $this->deepHealth();
            self::assertNotSame(404, $this->client->getResponse()->getStatusCode(), $address.' is the monitor\'s way in');
        }
    }

    /** The address is the one the edge forwarded, never the hop's own: every request reaches the API from a private one. */
    public function testTheAddressJudgedIsTheForwardedOne(): void
    {
        $this->signedInAsOperatorFrom('192.168.1.20');

        $this->from('172.18.0.4');
        $this->client->setServerParameter('HTTP_X_FORWARDED_FOR', self::OUTSIDE);
        $this->getJson('/api/platform/companies');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnOperatorsRightPasswordFromOutsideIsToldWhere(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->from(self::OUTSIDE);

        $this->postJson('/api/auth/login', ['email' => 'op@twes.local', 'password' => 'password-1234']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => UserChecker::OPERATOR_OUTSIDE], $this->json());
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAWrongOneHearsNothingMore(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->from(self::OUTSIDE);

        $this->postJson('/api/auth/login', ['email' => 'op@twes.local', 'password' => 'password-12345']);

        self::assertResponseStatusCodeSame(401);
        self::assertNotSame(['error' => UserChecker::OPERATOR_OUTSIDE], $this->json());
    }

    public function testTheShopsOwnStaffSignInFromOutside(): void
    {
        $this->createUser('owner@example.test', 'password-1234', $this->createCompany('Boutique'));
        $this->from(self::OUTSIDE);

        $this->login('owner@example.test', 'password-1234');

        self::assertResponseIsSuccessful();
    }

    public function testAServersOperatorsReachItFromAnywhere(): void
    {
        $this->signedInAsOperatorFrom(self::OUTSIDE);

        $this->getJson('/api/platform/companies');

        self::assertResponseIsSuccessful();
    }

    /** What the monitor reads, with its bearer: without one it is not there from anywhere (DeepHealthTest). */
    private function deepHealth(): void
    {
        $this->client->request('GET', '/api/health/deep', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::MONITOR]);
    }

    private function from(string $address): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', $address);
    }

    private function signedInAsOperatorFrom(string $address): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->from($address);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}

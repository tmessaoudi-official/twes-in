<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Mfa\Passkey\PasskeyHostSettings;
use App\Identity\Application\Mfa\Passkey\PasskeysFollowTheirName;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\InvalidSettingValue;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tests\Support\FakeAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the installation's passkeys are bound (docs/SPEC.md § 7, 2026-10-31 21:14, Q1 a): the installation's own name as
 * shipped, the shop's Tailscale name once its operators switch them there. A passkey works only on the name it was made
 * on, so the switch is refused while an account's only second factor is a passkey, and once it moves, whoever holds a
 * key made on the other name is told, and sees which of their keys no longer work.
 */
final class PasskeyHostTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string TAILNET = 'shop-pc.tail1234.ts.net';
    private const string SETTING = '/api/platform/settings/'.PasskeyHostSettings::ON_TAILNET_NAME;

    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $before = [];

    protected function setUp(): void
    {
        $names = [
            'TAILNET_HOST' => 'testOnWithNoTailscaleNameIsRefused' === $this->name() ? '' : self::TAILNET,
            'APP_WEBAUTHN_ORIGINS' => 'http://localhost:8090,https://'.self::TAILNET,
        ];
        foreach ($names as $name => $value) {
            $this->before[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        parent::setUp();
        $this->seedBuiltInRoles();
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

    public function testAsShippedPasskeysAreBoundToTheInstallationsOwnNameEvenWithATailscaleName(): void
    {
        self::assertSame(['host' => 'localhost', 'address' => 'http://localhost:8090'], $this->healthPasskeys());

        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        self::assertSame('localhost', $this->stringAt($this->section($this->json(), 'rp'), 'id'));
    }

    public function testAPasskeyMadeBeforeItsNameWasKeptIsReadAsMadeOnTheInstallationsOwn(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->withPasskey('someone@twes.local', $authenticator, authenticatorApp: false);
        $this->em()->getConnection()->executeStatement('UPDATE passkey SET host = NULL');

        $this->getJson('/api/auth/mfa/passkeys');
        self::assertSame([false], $this->elsewhere());
        $this->signOut();
        $this->login('someone@twes.local', self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkey-login', ['credential' => $authenticator->assert($this->requestOptions())]);
        self::assertResponseIsSuccessful();
    }

    public function testRefusedWhileAnAccountsOnlySecondFactorIsAPasskey(): void
    {
        $this->withPasskey('someone@twes.local', new FakeAuthenticator(), authenticatorApp: false);
        $this->signOut();

        $this->asOperator();
        $this->sendJson('PUT', self::SETTING, ['value' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => PasskeyHostSettings::ON_TAILNET_NAME, 'reason' => PasskeysFollowTheirName::PASSKEY_ONLY_FACTOR, 'params' => ['count' => 1]]], $this->refusals());
        self::assertSame(['host' => 'localhost', 'address' => 'http://localhost:8090'], $this->healthPasskeys(), 'nothing moved');
    }

    public function testAPageSaveIsRefusedAsOneKeyIs(): void
    {
        $this->withPasskey('someone@twes.local', new FakeAuthenticator(), authenticatorApp: false);
        $this->signOut();

        $this->asOperator();
        $this->sendJson('PUT', '/api/platform/settings', ['changes' => [['key' => PasskeyHostSettings::ON_TAILNET_NAME, 'value' => true, 'version' => 0]]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(PasskeysFollowTheirName::PASSKEY_ONLY_FACTOR, $this->refusals()[0]['reason'] ?? null);
        self::assertSame(['host' => 'localhost', 'address' => 'http://localhost:8090'], $this->healthPasskeys(), 'nothing moved');
    }

    public function testSayingAgainWhereThePasskeysAlreadyAreMovesNothingAndIsNotRefused(): void
    {
        $this->withPasskey('someone@twes.local', new FakeAuthenticator(), authenticatorApp: false);
        $this->signOut();

        $this->asOperator();
        $this->sendJson('PUT', self::SETTING, ['value' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->securityNotices());
    }

    public function testOnWithNoTailscaleNameIsRefused(): void
    {
        $this->asOperator();
        $this->sendJson('PUT', self::SETTING, ['value' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(PasskeysFollowTheirName::NO_TAILNET_NAME, $this->refusals()[0]['reason'] ?? null);
    }

    public function testSwitchedThePasskeysMoveToTheTailscaleNameAndTheirHoldersAreTold(): void
    {
        $old = new FakeAuthenticator();
        $this->withPasskey('someone@twes.local', $old, authenticatorApp: true);
        $this->signOut();

        $this->asOperator();
        $this->sendJson('PUT', self::SETTING, ['value' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(['passkeys_moved'], $this->securityNotices(), 'the one account holding a key made on the old name');
        self::assertSame(['host' => self::TAILNET, 'address' => 'https://'.self::TAILNET], $this->healthPasskeys());
        $this->signOut();

        $this->login('someone@twes.local', self::PASSWORD);
        $this->getJson('/api/auth/me');
        self::assertSame(1, $this->section($this->json(), 'mfa')['passkeysElsewhere'] ?? null);
        $this->getJson('/api/auth/mfa/passkeys');
        self::assertSame([true], $this->elsewhere());

        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $options = $this->json();
        self::assertSame(self::TAILNET, $this->stringAt($this->section($options, 'rp'), 'id'));
        $new = new FakeAuthenticator('https://'.self::TAILNET, self::TAILNET);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Phone', 'credential' => $new->register($options)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson('/api/auth/mfa/passkeys');
        self::assertSame([true, false], $this->elsewhere());

        // Only the key made on the name in force is asked for: the old one could not answer.
        $this->postJson('/api/auth/step-up/passkey/options', null);
        self::assertResponseIsSuccessful();
        self::assertSame([$new->id()], array_map(static fn (mixed $c): mixed => \is_array($c) ? $c['id'] ?? null : null, $this->arrayAt($this->json(), 'allowCredentials')));
    }

    public function testGoingBackToTheDefaultIsRefusedAsAChangeIs(): void
    {
        $this->asOperator();
        $this->sendJson('PUT', self::SETTING, ['value' => true]);
        self::assertResponseIsSuccessful();
        $this->signOut();

        // A passkey made on the Tailscale name, the account's only factor: moving back would leave it with none.
        $this->createUser('someone@twes.local', self::PASSWORD);
        $this->login('someone@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Phone', 'credential' => (new FakeAuthenticator('https://'.self::TAILNET, self::TAILNET))->register($this->json())]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        try {
            static::getContainer()->get(ChangeSettings::class)->reset(new SettingContext(), PasskeyHostSettings::ON_TAILNET_NAME, SettingLevel::Platform, null);
            self::fail('going back would leave an account with no second factor');
        } catch (InvalidSettingValue $refused) {
            self::assertSame([PasskeyHostSettings::ON_TAILNET_NAME], array_keys($refused->refusals));
            self::assertSame(PasskeysFollowTheirName::PASSKEY_ONLY_FACTOR, $refused->refusals[PasskeyHostSettings::ON_TAILNET_NAME]->reason);
        }
    }

    private function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function asOperator(): void
    {
        $this->createUser('op@twes.local', self::PASSWORD, operator: true, authenticator: true);
        $this->login('op@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
    }

    private function withPasskey(string $email, FakeAuthenticator $authenticator, bool $authenticatorApp): void
    {
        $this->createUser($email, self::PASSWORD, authenticator: $authenticatorApp ?: null);
        $this->login($email, self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->json())]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function requestOptions(): array
    {
        $this->postJson('/api/auth/mfa/passkey-login/options', null);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return list<mixed> each listed passkey's « elsewhere », in the list's order */
    private function elsewhere(): array
    {
        return array_map(static fn (mixed $p): mixed => \is_array($p) ? $p['elsewhere'] ?? null : null, array_values($this->arrayAt($this->json(), 'passkeys')));
    }

    /** @return mixed what /api/health says of passkeys */
    private function healthPasskeys(): mixed
    {
        $this->client->request('GET', '/api/health', server: ['HTTP_ACCEPT' => 'application/json']);

        return $this->json()['passkeys'] ?? null;
    }
}

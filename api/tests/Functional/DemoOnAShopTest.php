<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Application\DemoSettings;
use App\Demo\Infrastructure\Http\DemoEntryController;

/**
 * On a shop's own computer the API itself keeps the demo closed until an operator opens it: a visitor there would own a
 * sandbox on the shop's network, and the install step that switches it off may never have run or may have failed. The
 * installation's kind is the API's own value, APP_INSTALLATION, which compose.onprem.yaml sets.
 */
final class DemoOnAShopTest extends ApiTestCase
{
    use MakesSandboxes;

    /** @var array{env: mixed, server: mixed} */
    private array $before;

    protected function setUp(): void
    {
        $this->before = ['env' => $_ENV['APP_INSTALLATION'] ?? null, 'server' => $_SERVER['APP_INSTALLATION'] ?? null];
        $_ENV['APP_INSTALLATION'] = $_SERVER['APP_INSTALLATION'] = 'onprem';
        parent::setUp();
        // Its own client address, so no other test's entries count toward this one's budget.
        $this->client->setServerParameters(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_ENV['APP_INSTALLATION'], $_SERVER['APP_INSTALLATION']);
        if (null !== $this->before['env']) {
            $_ENV['APP_INSTALLATION'] = $this->before['env'];
        }
        if (null !== $this->before['server']) {
            $_SERVER['APP_INSTALLATION'] = $this->before['server'];
        }
    }

    public function testWithNothingChosenAShopOffersNoDemoAndRefusesAnEntry(): void
    {
        $this->getJson('/api/auth/demo');
        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => false, 'countries' => []], $this->json());

        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(DemoEntryController::SWITCHED_OFF, $this->json()['error'] ?? null);
    }

    public function testAnOperatorWhoOpensItThereIsHeard(): void
    {
        $this->platform(DemoSettings::ENABLED, true);

        $this->getJson('/api/auth/demo');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['enabled'] ?? null);
    }
}

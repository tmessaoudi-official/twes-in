<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;

/**
 * How a company reaches the people who run the platform: a phone, a WhatsApp number and an address the operators set,
 * which every signed-in person reads with their signed-in state and the help panel offers. A channel left empty is
 * not there at all.
 */
final class SupportContactTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    public function testNoContactIsGivenUntilAnOperatorSetsOne(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);

        self::assertSame(['phone' => null, 'whatsapp' => null, 'email' => null], $this->json()['support']);
    }

    public function testWhatTheOperatorSetsReachesEveryMemberAndTheLoginAnswer(): void
    {
        $this->signedInAsOperator();
        $this->sendJson('PUT', '/api/platform/settings/support.phone', ['value' => '+216 71 000 000']);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', '/api/platform/settings/support.whatsapp', ['value' => '+216 20 123 456']);
        self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->clear();

        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);
        $expected = ['phone' => '+216 71 000 000', 'whatsapp' => '+216 20 123 456', 'email' => null];
        self::assertSame($expected, $this->json()['support'], 'the login answer');

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame($expected, $this->json()['support'], 'the signed-in state');
    }

    public function testAChannelNoOneCouldUseIsRefused(): void
    {
        $this->signedInAsOperator();

        // WhatsApp opens a chat only from the number in its international form.
        foreach (['support.whatsapp' => ['20 123 456', 'call me'], 'support.phone' => ['phone'], 'support.email' => ['support', 'a@b']] as $key => $values) {
            foreach ($values as $value) {
                $this->sendJson('PUT', '/api/platform/settings/'.$key, ['value' => $value]);
                self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $key.' '.$value);
            }
        }

        // Empty again is how a channel is taken away.
        $this->sendJson('PUT', '/api/platform/settings/support.email', ['value' => 'help@twes.test']);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', '/api/platform/settings/support.email', ['value' => '']);
        self::assertResponseIsSuccessful();
    }

    private function signedInAsOperator(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}

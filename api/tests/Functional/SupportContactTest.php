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

    /**
     * The footer of every page links to the support phone, signed out too: a value of signs alone made a `tel:` link
     * that called nothing. A phone holds 6 to 15 digits, the most an international number has; the e-mail follows the
     * one rule every e-mail of the application does.
     */
    public function testASupportChannelIsANumberOrAnAddressSomeoneCanReach(): void
    {
        $this->signedInAsOperator();

        foreach (['------', '((( )))', '12 34 5', '+216 71 900 868 99999'] as $phone) {
            $this->sendJson('PUT', '/api/platform/settings/support.phone', ['value' => $phone]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $phone);
            self::assertSame('invalid_phone_digits', $this->refusalOf('support.phone')[0], $phone);
        }
        foreach (['a@b..c', '<x>@y.z', 'é@exemple.tn'] as $email) {
            $this->sendJson('PUT', '/api/platform/settings/support.email', ['value' => $email]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $email);
            self::assertSame('invalid_email', $this->refusalOf('support.email')[0], $email);
        }

        foreach (['support.phone' => ['+216 71 900 868', '71.900.868', '+33 6 12 34 56 78'], 'support.email' => ['support@exemple.tn']] as $key => $values) {
            foreach ($values as $value) {
                $this->sendJson('PUT', '/api/platform/settings/'.$key, ['value' => $value]);
                self::assertResponseIsSuccessful($key.' '.$value);
            }
        }
    }

    /**
     * The monitor's public status page, which the footer of every page links to, signed out too: the public health
     * answer carries it. Only an https address is taken, the one form a status page has.
     */
    public function testTheStatusPageTheOperatorSetsReachesThePublicHealthAnswer(): void
    {
        $this->client->request('GET', '/api/health');
        self::assertNull($this->json()['statusUrl'], 'none until an operator sets it');

        $this->signedInAsOperator();
        foreach (['http://status.twes.test', 'status.twes.test', 'javascript:alert(1)', 'https://status twes'] as $value) {
            $this->sendJson('PUT', '/api/platform/settings/support.status_url', ['value' => $value]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $value);
        }
        $this->sendJson('PUT', '/api/platform/settings/support.status_url', ['value' => 'https://status.twes.test']);
        self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/health');
        self::assertResponseIsSuccessful();
        self::assertSame('https://status.twes.test', $this->json()['statusUrl']);
    }

    private function signedInAsOperator(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        $this->stepUp('password-1234');
        self::assertResponseIsSuccessful();
    }
}

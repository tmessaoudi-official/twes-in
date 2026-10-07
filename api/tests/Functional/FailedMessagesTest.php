<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Password\PasswordResetAsked;
use App\Tenancy\Infrastructure\Invitation\InvitationToMail;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * What the worker gave up on, for the platform operator (docs/SPEC.md § 7, Messenger transport, row 56): a signup ask or a
 * password reset belongs to no company, so only the operator can see that it never left, and retry it from the worker.
 */
final class FailedMessagesTest extends ApiTestCase
{
    public function testTheOperatorReadsHowManyMessagesFailedForGoodByWhatTheyWere(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');

        $this->getJson('/api/platform/failed-messages');
        self::assertResponseIsSuccessful();
        self::assertSame(['total' => 0, 'kinds' => []], $this->failed());

        $failed = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $failed);
        $failed->send(new Envelope(new InvitationToMail('0199a1b2-0000-7000-8000-000000000001')));
        $failed->send(new Envelope(new PasswordResetAsked('someone@example.tn', null)));
        $failed->send(new Envelope(new PasswordResetAsked('someone-else@example.tn', 'fr')));

        $this->getJson('/api/platform/failed-messages');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['total' => 3, 'kinds' => [['kind' => 'PasswordResetAsked', 'count' => 2], ['kind' => 'InvitationToMail', 'count' => 1]]],
            $this->failed(),
            'the most first, each by the name of what it was',
        );
    }

    public function testNobodyButAnOperatorReadsIt(): void
    {
        $this->createUser('owner@twes.local', 'password-1234');
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson('/api/platform/failed-messages');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** @return array{total: mixed, kinds: mixed} */
    private function failed(): array
    {
        $body = $this->json();

        return ['total' => $body['total'] ?? null, 'kinds' => $body['kinds'] ?? null];
    }
}

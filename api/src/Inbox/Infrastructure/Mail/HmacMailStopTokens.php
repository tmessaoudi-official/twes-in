<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Mail;

use App\Inbox\Application\MailStop;
use App\Inbox\Application\MailStopTokens;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * A stop link's token: what it stops, readable, then an HMAC-SHA256 of it under the application secret, so nobody can
 * write one for another person or another kind. It names ids and a type only, never an address or a name, since a mail
 * is forwarded and its links are logged. The version lets a later shape be told from this one.
 */
#[AsAlias(MailStopTokens::class)]
final readonly class HmacMailStopTokens implements MailStopTokens
{
    private const int VERSION = 1;
    /** Keeps this signature from ever matching one the same secret makes for something else. */
    private const string PURPOSE = 'notification-mail-stop.';

    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    public function issue(MailStop $stop): string
    {
        $payload = self::encode(json_encode([
            'v' => self::VERSION,
            'u' => $stop->userId->toRfc4122(),
            'c' => $stop->companyId?->toRfc4122(),
            't' => $stop->type,
        ], \JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->signature($payload);
    }

    public function read(string $token): ?MailStop
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts) || !hash_equals($this->signature($parts[0]), $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $body = false === $json ? null : json_decode($json, true);
        if (!\is_array($body) || self::VERSION !== ($body['v'] ?? null)) {
            return null;
        }
        $user = $body['u'] ?? null;
        $company = $body['c'] ?? null;
        $type = $body['t'] ?? null;
        if (!\is_string($user) || !Uuid::isValid($user) || (null !== $company && (!\is_string($company) || !Uuid::isValid($company))) || !\is_string($type) || '' === $type) {
            return null;
        }

        return new MailStop(Uuid::fromString($user), null === $company ? null : Uuid::fromString($company), $type);
    }

    private function signature(string $payload): string
    {
        return self::encode(hash_hmac('sha256', self::PURPOSE.$payload, $this->secret, true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

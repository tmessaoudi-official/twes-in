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
 * A stop link's token: what it stops, readable, then an HMAC-SHA256 of it under the operator's first stop key
 * (app.notification.stop_keys, the application secret unless the operator gives one of its own), so nobody can write one
 * for another person or another kind. Every key listed still reads a link, since a stop link never expires: a new key
 * put first keeps the links already sent working until the operator drops the old one. It names ids and a type only, never an address or a name, since a mail
 * is forwarded and its links are logged. The version lets a later shape be told from this one.
 */
#[AsAlias(MailStopTokens::class)]
final readonly class HmacMailStopTokens implements MailStopTokens
{
    private const int VERSION = 1;
    /** Keeps this signature from ever matching one the same secret makes for something else. */
    private const string PURPOSE = 'notification-mail-stop.';

    /** @param list<string> $keys the first signs, every one reads */
    public function __construct(#[Autowire('%app.notification.stop_keys%')] private array $keys)
    {
        if ([] === $keys) {
            throw new \InvalidArgumentException('A stop link needs a key to be signed under.');
        }
    }

    public function issue(MailStop $stop): string
    {
        $payload = self::encode(json_encode([
            'v' => self::VERSION,
            'u' => $stop->userId->toRfc4122(),
            'c' => $stop->companyId?->toRfc4122(),
            't' => $stop->type,
        ], \JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->signature($payload, $this->keys[0]);
    }

    public function read(string $token): ?MailStop
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts) || !$this->signedHere($parts[0], $parts[1])) {
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

    private function signedHere(string $payload, string $signature): bool
    {
        foreach ($this->keys as $key) {
            if (hash_equals($this->signature($payload, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function signature(string $payload, string $key): string
    {
        return self::encode(hash_hmac('sha256', self::PURPOSE.$payload, $key, true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

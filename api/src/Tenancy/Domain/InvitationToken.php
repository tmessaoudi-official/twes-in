<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * The secret in an invitation link. The raw value exists only in the mail that carries it: what is stored is
 * its SHA-256, so a leaked database backup yields no usable link. It is 32 random bytes, which is far past
 * anything guessable, and it is compared by hash so an unknown token costs the same as a known one.
 */
final readonly class InvitationToken
{
    private const int RAW_BYTES = 32;

    private function __construct(public string $raw)
    {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(self::RAW_BYTES)));
    }

    /** @throws \InvalidArgumentException when the value cannot be one this class ever generated */
    public static function fromRaw(string $raw): self
    {
        if (1 !== preg_match('/^[0-9a-f]{'.(2 * self::RAW_BYTES).'}$/', $raw)) {
            throw new \InvalidArgumentException('That is not an invitation token.');
        }

        return new self($raw);
    }

    public function hash(): string
    {
        return self::hashOf($this->raw);
    }

    public static function hashOf(string $raw): string
    {
        return hash('sha256', $raw);
    }
}

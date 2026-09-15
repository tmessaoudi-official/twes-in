<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * The secret in a signup link, shaped like an invitation's: 32 random bytes, of which only the SHA-256 is stored, so a
 * leaked backup yields no usable link, and looked up by hash, so an unknown token costs what a known one does.
 */
final readonly class SignupToken
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
            throw new \InvalidArgumentException('That is not a signup token.');
        }

        return new self($raw);
    }

    public function hash(): string
    {
        return hash('sha256', $this->raw);
    }
}

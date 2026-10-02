<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The secret in a reset link. The raw value exists only in the mail that carries it: what is stored is its SHA-256, so a
 * leaked database backup yields no usable link. It is 32 random bytes, compared by hash.
 */
final readonly class PasswordResetToken
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
            throw new \InvalidArgumentException('That is not a reset token.');
        }

        return new self($raw);
    }

    public function hash(): string
    {
        return hash('sha256', $this->raw);
    }
}

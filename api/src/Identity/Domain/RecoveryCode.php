<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A single-use way back in when the authenticator is gone. Ten are issued at enrolment and shown once
 * (ruling of 2026-09-10).
 *
 * Stored as SHA-256, like the invitation token and unlike a password: these are high-entropy values, so a
 * slow hash buys nothing against guessing and would turn one verification into ten argon2 passes. The
 * alphabet drops the characters people misread off a screen, because that is exactly how these are used.
 */
final readonly class RecoveryCode
{
    public const int PER_SET = 10;

    private const string ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const int GROUP = 5;

    private function __construct(public string $raw)
    {
    }

    public static function generate(): self
    {
        return new self(self::group().'-'.self::group());
    }

    /** @return list<self> */
    public static function generateSet(): array
    {
        $codes = [];

        while (\count($codes) < self::PER_SET) {
            $code = self::generate();
            // Distinct by construction rather than by luck: a duplicate would be a code that silently
            // burns two rows at once.
            $codes[$code->raw] = $code;
        }

        return array_values($codes);
    }

    public function hash(): string
    {
        return self::hashOf($this->raw);
    }

    /** Normalises the way a person types it back: off paper, capitalised, with a stray space. */
    public static function hashOf(string $raw): string
    {
        return hash('sha256', strtolower(trim($raw)));
    }

    private static function group(): string
    {
        $out = '';

        for ($i = 0; $i < self::GROUP; ++$i) {
            $out .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}

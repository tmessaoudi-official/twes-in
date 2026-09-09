<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/** An address as the user identifier: lower-cased, trimmed, well-formed, at most 254 characters (RFC 5321). */
final readonly class Email implements \Stringable
{
    private const int MAX_LENGTH = 254;

    /** @param non-empty-string $value */
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $value = mb_strtolower(trim($raw));
        if ('' === $value || \strlen($value) > self::MAX_LENGTH || false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Not an email address.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

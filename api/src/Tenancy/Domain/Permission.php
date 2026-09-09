<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * A permission string: dotted, lower-case segments ("invoice.issue", "platform.company.create"). The
 * "platform." family belongs to platform operators and to nobody else: operator scope sits outside memberships,
 * and a company owner is not an operator. Everything else is granted by a role in a company.
 */
final readonly class Permission implements \Stringable
{
    public const string WILDCARD = '*';
    private const string PLATFORM_PREFIX = 'platform.';

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!self::isWellFormed($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a permission string.', $value));
        }

        return new self($value);
    }

    /** Anything else ("ROLE_USER", "edit") is not a permission and is left to other voters. */
    public static function isWellFormed(string $value): bool
    {
        return 1 === preg_match("/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/", $value);
    }

    public function isPlatformScoped(): bool
    {
        return str_starts_with($this->value, self::PLATFORM_PREFIX);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Tenancy;

/**
 * Which tenant a request belongs to.
 *
 * Lives in `Infrastructure/` and not `Domain/` on purpose. Tenancy is an infrastructure concern here:
 * the domain never asks who the tenant is, never mentions `company_id`, and never touches a
 * connection. That is precisely what makes it possible to switch between one shared database and one
 * database per tenant by configuration — see {@see TenantIsolationStrategy}. A `company_id` reaching
 * `Domain/` would end that, and is a P0 for tenancy-security-reviewer.
 */
final readonly class TenantId
{
    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private function __construct(private string $value) {}

    /**
     * Accepts any RFC 9562 UUID rather than only version 7.
     *
     * twes-in *generates* v7 — see the id generator port — but validating the version here would
     * reject a legitimately imported or migrated identifier, and a tenant id's job is to identify, not
     * to certify how it was minted.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $normalised = strtolower($value);

        if (1 !== preg_match(self::UUID_PATTERN, $normalised)) {
            throw new \InvalidArgumentException(\sprintf(
                'Tenant id "%s" is not a UUID. Tenant ids are never sequential integers, which are '
                . 'ENUMERABLE — a counter turns any missing authorisation check into a walk across every '
                . 'tenant. Note the claim stops there deliberately (2026-08-05 ruling): a UUID is not a '
                . 'SECRET either, so isolation rests on row-level security rather than on this format.',
                $value,
            ));
        }

        return new self($normalised);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

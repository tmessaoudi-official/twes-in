<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure;

/**
 * What production refuses to start on (docs/SPEC.md § 8 row 22, review S10).
 *
 * `api/.env` carries working keys, not placeholders, and `compose.yaml` hands the containers the same literals:
 * that is what makes a clone run with one command, and it is exactly why they are public. Whoever reads the
 * repository holds them, and with them can forge a realtime token, drive Centrifugo's HTTP API and decrypt every
 * TOTP secret stored. A deployment cannot be relied on to notice it never set its own, because nothing would go
 * wrong visibly: it would simply run, signed and encrypted with keys the world already has.
 *
 * So the check is a refusal to boot rather than a warning, and it is scoped to production, because development
 * and the test suite are meant to run on these values.
 */
final class DevelopmentKeys
{
    /** The literals `api/.env` commits and `compose.yaml` defaults to, by the variable carrying each. */
    private const array COMMITTED = [
        'APP_MFA_KEY' => 'ojCIqjd0KzQ4JrX9wyEpy58LdtCAQ56MTwM8CWA0XOc=',
        'REALTIME_TOKEN_KEY' => 'twes-in-development-realtime-token-key-0001',
        'REALTIME_API_KEY' => 'twes-in-development-realtime-api-key',
    ];

    /** Every secret production must hold, `APP_SECRET` included: `api/.env` ships it empty. */
    private const array REQUIRED = ['APP_SECRET', 'APP_MFA_KEY', 'REALTIME_TOKEN_KEY', 'REALTIME_API_KEY'];

    /**
     * @param array<mixed> $values the environment, as the kernel was handed it
     *
     * @throws \RuntimeException naming the one variable at fault, so a failed deployment says what to set
     */
    public static function check(string $environment, array $values): void
    {
        if ('prod' !== $environment) {
            return;
        }

        foreach (self::REQUIRED as $name) {
            $value = $values[$name] ?? null;

            if (!\is_string($value) || '' === $value) {
                throw new \RuntimeException(\sprintf('%s is not set: production will not start without a secret of its own.', $name));
            }

            if (isset(self::COMMITTED[$name]) && hash_equals(self::COMMITTED[$name], $value)) {
                throw new \RuntimeException(\sprintf('%s still carries the development value committed in api/.env, which anyone can read: production will not start on it.', $name));
            }
        }
    }
}

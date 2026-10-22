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
    /** The literals `api/.env`, `api/.env.dev` and `api/.env.test` commit and `compose.yaml` defaults to, by the variable carrying each. */
    private const array COMMITTED = [
        'APP_SECRET' => ['09485d6948635a24ac59a2c004f96c76', 'test-secret-not-used-in-production'],
        'APP_MFA_KEY' => ['ojCIqjd0KzQ4JrX9wyEpy58LdtCAQ56MTwM8CWA0XOc='],
        'REALTIME_TOKEN_KEY' => ['twes-in-development-realtime-token-key-0001'],
        'REALTIME_API_KEY' => ['twes-in-development-realtime-api-key'],
        // Not a key, but where a relay left blank in the values file falls back to: nothing listens there, so every mail
        // would fail in the queue unseen.
        'MAILER_DSN' => ['smtp://127.0.0.1:8093'],
    ];

    /** `APP_SECRET` signs the links mails carry: one short enough to guess lets anyone forge them. */
    private const int SECRET_MINIMUM_LENGTH = 32;

    /** Every secret production must hold, `APP_SECRET` included: `api/.env` ships it empty. */
    private const array REQUIRED = ['APP_SECRET', 'APP_MFA_KEY', 'REALTIME_TOKEN_KEY', 'REALTIME_API_KEY', 'MAILER_DSN'];

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

            foreach (self::COMMITTED[$name] as $committed) {
                if (hash_equals($committed, $value)) {
                    throw new \RuntimeException(\sprintf('%s still carries a development value committed in this repository, which anyone can read: production will not start on it.', $name));
                }
            }

            if ('APP_SECRET' === $name && \strlen($value) < self::SECRET_MINIMUM_LENGTH) {
                throw new \RuntimeException(\sprintf('APP_SECRET is shorter than %d characters: production will not start on a secret that can be guessed.', self::SECRET_MINIMUM_LENGTH));
            }
        }
    }
}

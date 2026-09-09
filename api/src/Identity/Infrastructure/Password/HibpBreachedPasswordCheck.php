<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\BreachedPasswordCheck;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Have I Been Pwned's range API, queried the way it is meant to be: the password is SHA-1'd locally, only
 * the first five hex characters of that digest leave the machine, and the answer is scanned for the rest.
 * The service never learns the password, nor even its full digest.
 *
 * Padding is requested so every answer is a similar size, which stops the response length itself hinting at
 * how many suffixes came back. An unreachable service answers null, and the caller audits the skip rather
 * than blocking somebody from setting a password (docs/SPEC.md § 7, 2026-09-09).
 */
final readonly class HibpBreachedPasswordCheck implements BreachedPasswordCheck
{
    private const string ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private HttpClientInterface $http,
        private bool $enabled,
    ) {
    }

    public function isBreached(string $plainPassword): ?bool
    {
        if (!$this->enabled) {
            // Switched off in this environment; no test or offline build should reach out over the network.
            return false;
        }

        $digest = strtoupper(sha1($plainPassword));
        $prefix = substr($digest, 0, 5);
        $suffix = substr($digest, 5);

        try {
            $body = $this->http->request('GET', self::ENDPOINT.$prefix, [
                'headers' => ['Add-Padding' => 'true'],
                'timeout' => 3,
            ])->getContent();
        } catch (\Throwable) {
            return null;
        }

        foreach (explode("\n", $body) as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            // Padding entries are real suffixes with a count of zero; they are not matches.
            if (hash_equals($suffix, $candidate) && 0 !== (int) $count) {
                return true;
            }
        }

        return false;
    }
}

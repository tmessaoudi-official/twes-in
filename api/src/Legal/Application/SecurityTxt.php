<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

/**
 * The platform's `/.well-known/security.txt` (RFC 9116), written from the security contact the operator filled in
 * (`legal.security.email`) and pointing at the Security page for the rest.
 *
 * Contact is the one field the RFC requires, so without a usable address there is no file at all rather than an
 * invalid one. The file is written on each request from the current settings, so it cannot go stale the way a copied
 * file would; Expires still moves only once a day, to midnight UTC thirty days ahead, well inside the RFC's advice of
 * less than a year.
 */
final readonly class SecurityTxt
{
    private const string EXPIRES_AFTER = '+30 days';

    private string $origin;

    public function __construct(string $origin)
    {
        $this->origin = rtrim($origin, '/');
    }

    /** @param array<string, string> $values the filled-in facts, by placeholder (`LegalIdentity::values()`) */
    public function render(array $values, \DateTimeImmutable $now): ?string
    {
        $contact = $values['security.email'] ?? '';
        // A validated address holds no whitespace, so no line break can smuggle a field of its own into the file.
        if (false === filter_var($contact, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $expires = $now->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0)->modify(self::EXPIRES_AFTER);

        return 'Contact: mailto:'.$contact."\n"
            .'Expires: '.$expires->format('Y-m-d\TH:i:s\Z')."\n"
            ."Preferred-Languages: fr, en, ar\n"
            .'Canonical: '.$this->origin."/.well-known/security.txt\n"
            .'Policy: '.$this->origin."/legal/security\n";
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * How a document number is written: literal text around the tokens `{YYYY}`, `{YY}`, `{MM}`, `{EST}` (the issuing
 * establishment's code, which keeps the numbers of two establishments apart) and exactly one sequence, `{SEQ}` or `{SEQ:n}` padded with zeros to n digits (1 to 12) and never cut when it grows wider. The
 * literal text is letters, digits, spaces and `_ . / -`, because a number is printed, typed back by customers and
 * carried in e-invoice identifiers.
 */
final readonly class NumberFormat
{
    public const int MAX_LENGTH = 64;
    public const int MAX_PADDING = 12;

    private const string TOKEN = '/(\{[^{}]*\})/';
    private const string LITERAL = '#^[A-Za-z0-9 _./-]*$#';

    public string $pattern;

    /** @throws InvalidNumbering */
    public function __construct(string $pattern)
    {
        if ('' === trim($pattern)) {
            throw new InvalidNumbering('format', 'A numbering format cannot be blank.');
        }
        if (\strlen($pattern) > self::MAX_LENGTH) {
            throw new InvalidNumbering('format', \sprintf('A numbering format holds at most %d characters.', self::MAX_LENGTH));
        }

        $sequences = 0;
        foreach (self::parts($pattern) as $part) {
            if (!str_starts_with($part, '{')) {
                if (1 !== preg_match(self::LITERAL, $part)) {
                    throw new InvalidNumbering('format', \sprintf('"%s" may only carry letters, digits, spaces, "_", ".", "/" and "-" around its tokens.', $pattern));
                }
                continue;
            }
            $token = substr($part, 1, -1);
            if (\in_array($token, ['YYYY', 'YY', 'MM', 'EST'], true)) {
                continue;
            }
            if (1 !== preg_match('/^SEQ(?::([0-9]{1,2}))?$/', $token, $match)) {
                throw new InvalidNumbering('format', \sprintf('"%s" is not a numbering token: use {YYYY}, {YY}, {MM}, {EST}, {SEQ} or {SEQ:n}.', $part));
            }
            $padding = isset($match[1]) ? (int) $match[1] : 1;
            if ($padding < 1 || $padding > self::MAX_PADDING) {
                throw new InvalidNumbering('format', \sprintf('A sequence is padded to 1 to %d digits.', self::MAX_PADDING));
            }
            ++$sequences;
        }
        if (1 !== $sequences) {
            throw new InvalidNumbering('format', 'A numbering format carries exactly one sequence, {SEQ} or {SEQ:n}.');
        }

        $this->pattern = $pattern;
    }

    public function render(int $number, \DateTimeImmutable $date, string $establishmentCode): string
    {
        $out = '';
        foreach (self::parts($this->pattern) as $part) {
            $out .= match (true) {
                '{YYYY}' === $part => $date->format('Y'),
                '{YY}' === $part => $date->format('y'),
                '{MM}' === $part => $date->format('m'),
                '{EST}' === $part => $establishmentCode,
                str_starts_with($part, '{SEQ') => str_pad((string) $number, '{SEQ}' === $part ? 1 : (int) substr($part, 5, -1), '0', \STR_PAD_LEFT),
                default => $part,
            };
        }

        return $out;
    }

    /** @return list<string> literal runs and `{…}` tokens, in order */
    private static function parts(string $pattern): array
    {
        return array_values(array_filter(
            preg_split(self::TOKEN, $pattern, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [],
            static fn (string $part): bool => '' !== $part,
        ));
    }
}

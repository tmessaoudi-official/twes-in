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

    /** Whether a number printed with this format names its year, `{YYYY}` or `{YY}`. */
    public function printsYear(): bool
    {
        return [] !== array_intersect(['{YYYY}', '{YY}'], self::parts($this->pattern));
    }

    /** Whether a number printed with this format names its month, `{MM}`. */
    public function printsMonth(): bool
    {
        return \in_array('{MM}', self::parts($this->pattern), true);
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

    /**
     * Whether a number this format prints for an establishment coded `$code` could be one `$other` prints for one coded
     * `$otherCode`, whatever the sequences and days come to: two series of one document type must never share one. Both
     * are walked together a character at a time, each a row of characters and runs of digits. A month is taken as any
     * two digits and a sequence as any digits from its padding on, so a pair kept apart by that difference alone is
     * taken as sharing: a refusal to spare, never a collision let through.
     */
    public function mayPrintAsOf(self $other, string $code, string $otherCode): bool
    {
        $one = self::shape($this->pattern, $code);
        $two = self::shape($other->pattern, $otherCode);
        $alphabet = array_unique([...str_split('0123456789'), ...array_filter(array_column([...$one, ...$two], 0), is_string(...))]);
        /** @var list<array{list<array{int, int}>, list<array{int, int}>}> $pending */
        $pending = [[self::settled($one, [[0, 0]]), self::settled($two, [[0, 0]])]];
        $seen = [];
        while ([] !== $pending) {
            [$here, $there] = array_pop($pending);
            if (self::ended($one, $here) && self::ended($two, $there)) {
                return true;
            }
            foreach ($alphabet as $char) {
                $next = [self::step($one, $here, $char), self::step($two, $there, $char)];
                $key = json_encode($next, \JSON_THROW_ON_ERROR);
                if ([] !== $next[0] && [] !== $next[1] && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $pending[] = $next;
                }
            }
        }

        return false;
    }

    /**
     * A format as the characters it prints: each a character it must be, or null for a digit, with how many times at
     * least and at most (no bound for a sequence).
     *
     * @return list<array{string|null, int, int}>
     */
    private static function shape(string $pattern, string $code): array
    {
        $shape = [];
        foreach (self::parts($pattern) as $part) {
            $digits = match (true) {
                '{YYYY}' === $part => [4, 4],
                '{YY}' === $part, '{MM}' === $part => [2, 2],
                '{SEQ}' === $part => [1, \PHP_INT_MAX],
                str_starts_with($part, '{SEQ:') => [(int) substr($part, 5, -1), \PHP_INT_MAX],
                default => null,
            };
            if (null !== $digits) {
                $shape[] = [null, ...$digits];
                continue;
            }
            foreach (str_split('{EST}' === $part ? $code : $part) as $char) {
                $shape[] = [$char, 1, 1];
            }
        }

        return $shape;
    }

    /**
     * Where a walk may stand once it has read a character more: past a character, or one digit further into a run,
     * counted up to its least only when it has no bound, so the walk stays finite.
     *
     * @param list<array{string|null, int, int}> $shape
     * @param list<array{int, int}>              $states
     *
     * @return list<array{int, int}>
     */
    private static function step(array $shape, array $states, string $char): array
    {
        $next = [];
        foreach ($states as [$at, $read]) {
            if (!isset($shape[$at])) {
                continue;
            }
            [$wanted, $least, $most] = $shape[$at];
            if (null !== $wanted) {
                if ($char === $wanted) {
                    $next[] = [$at + 1, 0];
                }
            } elseif (ctype_digit($char) && $read < $most) {
                $next[] = [$at, \PHP_INT_MAX === $most ? min($read + 1, $least) : $read + 1];
            }
        }

        return self::settled($shape, $next);
    }

    /**
     * The places a walk may stand, with every run read to its least also left behind.
     *
     * @param list<array{string|null, int, int}> $shape
     * @param list<array{int, int}>              $states
     *
     * @return list<array{int, int}>
     */
    private static function settled(array $shape, array $states): array
    {
        $settled = [];
        while ([] !== $states) {
            [$at, $read] = array_pop($states);
            $key = $at.':'.$read;
            if (isset($settled[$key])) {
                continue;
            }
            $settled[$key] = [$at, $read];
            if (isset($shape[$at]) && null === $shape[$at][0] && $read >= $shape[$at][1]) {
                $states[] = [$at + 1, 0];
            }
        }
        ksort($settled);

        return array_values($settled);
    }

    /**
     * Whether a walk may stand at the end of what it reads.
     *
     * @param list<array{string|null, int, int}> $shape
     * @param list<array{int, int}>              $states
     */
    private static function ended(array $shape, array $states): bool
    {
        return \in_array([\count($shape), 0], $states, true);
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

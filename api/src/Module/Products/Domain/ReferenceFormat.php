<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * How a reference the company did not type is written: letters, digits and `. _ / -` around exactly one sequence,
 * `{SEQ}` or `{SEQ:n}` padded with zeros to n digits (1 to 12) and never cut when it grows wider. Nothing else is a
 * token: a reference is not dated and not an establishment's, and it must stay a product reference, so a space or an
 * accent is refused here rather than at the first product. Twenty characters keep the widest number within a
 * reference's thirty-two.
 */
final readonly class ReferenceFormat
{
    public const int MAX_LENGTH = 20;
    /** The whole grammar, which the settings engine also hands to the screen's field. */
    public const string PATTERN = '/^([A-Za-z0-9][A-Za-z0-9._\/-]*)?\{SEQ(:([1-9]|1[0-2]))?\}[A-Za-z0-9._\/-]*$/';

    public string $pattern;

    /** @throws InvalidProduct */
    public function __construct(string $pattern)
    {
        if (\strlen($pattern) > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $pattern)) {
            throw new InvalidProduct('reference', \sprintf('"%s" is not a reference format: up to %d letters, digits, ".", "_", "/" or "-" around one {SEQ} or {SEQ:n}.', $pattern, self::MAX_LENGTH));
        }
        $this->pattern = $pattern;
    }

    public function render(int $number): string
    {
        return (string) preg_replace_callback(
            '/\{SEQ(?::(\d+))?\}/',
            static fn (array $match): string => str_pad((string) $number, isset($match[1]) ? (int) $match[1] : 1, '0', \STR_PAD_LEFT),
            $this->pattern,
        );
    }
}

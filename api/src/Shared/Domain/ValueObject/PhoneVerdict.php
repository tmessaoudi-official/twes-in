<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * What a typed phone number is, judged by its country's numbering plan: kept (in E.164) when it is one, kept and
 * marked to check when its length is right but its country hands out no such range, refused when its length is wrong,
 * when nothing says which country reads a national number, or when it is no number at all. A refusal carries the
 * country's example and calling code, so the person is shown what is expected.
 */
final readonly class PhoneVerdict
{
    public const string VALID = 'valid';
    public const string TO_CHECK = 'to_check';
    public const string WRONG_LENGTH = 'wrong_length';
    public const string NEEDS_COUNTRY = 'needs_country';
    public const string NOT_A_NUMBER = 'not_a_number';

    /**
     * @param string      $verdict     one of the constants above
     * @param string|null $e164        the number kept, for VALID and TO_CHECK only
     * @param string|null $example     the country's example, written internationally, for a refusal that has a country
     * @param string|null $callingCode the country's « +NNN », when there is a country
     */
    public function __construct(
        public string $verdict,
        public ?string $e164 = null,
        public ?string $example = null,
        public ?string $callingCode = null,
    ) {
    }

    /** Kept: a number of its country, or one of the right length to check. */
    public function kept(): bool
    {
        return null !== $this->e164;
    }
}

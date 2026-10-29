<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Intl;

use App\Shared\Application\PhoneNumbers;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\PhoneVerdict;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\ValidationResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The numbering plans of Google's libphonenumber, through giggsey's lite port: the data, not our own guess, says which
 * lengths and ranges each country hands out.
 */
final readonly class LibPhoneNumbers implements PhoneNumbers
{
    /** Only the digits, a leading « + », and what a person puts between the groups. */
    private const string WRITTEN = '/^\+?[0-9 ().\-\/]+$/D';

    /** The « À vérifier » words, beside an import's set-aside cells. */
    private const string DOMAIN = 'imports';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function judge(string $typed, ?string $country): PhoneVerdict
    {
        $util = PhoneNumberUtil::getInstance();
        $region = null !== $country && \in_array($country, $util->getSupportedRegions(), true) ? $country : null;
        $callingCode = null === $region ? null : '+'.$util->getCountryCodeForRegion($region);
        $example = null === $region ? null : $this->example($region);
        // Every space a copy brings reads as a space, and « 00 » as « + », whatever the country's own exit code.
        $written = preg_replace('/[\p{Z}\p{Cc}]+/u', ' ', TypedText::trimmed($typed)) ?? '';
        $written = preg_replace('/^00/', '+', $written) ?? $written;
        if ('' === $written || 1 !== preg_match(self::WRITTEN, $written) || 1 !== preg_match('/[0-9]/', $written)) {
            return new PhoneVerdict(PhoneVerdict::NOT_A_NUMBER, null, $example, $callingCode);
        }
        if (!str_starts_with($written, '+') && null === $region) {
            return new PhoneVerdict(PhoneVerdict::NEEDS_COUNTRY);
        }
        try {
            $number = $util->parse($written, $region ?? 'ZZ');
        } catch (NumberParseException) {
            return new PhoneVerdict(PhoneVerdict::NOT_A_NUMBER, null, $example, $callingCode);
        }
        $named = $util->getRegionCodeForNumber($number);
        $ownExample = null === $named || 'ZZ' === $named ? $example : $this->example($named);
        $ownCode = '+'.$number->getCountryCode();
        if (ValidationResult::IS_POSSIBLE !== $util->isPossibleNumberWithReason($number)) {
            return new PhoneVerdict(PhoneVerdict::WRONG_LENGTH, null, $ownExample, $ownCode);
        }
        $e164 = $util->format($number, PhoneNumberFormat::E164);

        return new PhoneVerdict($util->isValidNumber($number) ? PhoneVerdict::VALID : PhoneVerdict::TO_CHECK, $e164, $ownExample, $ownCode);
    }

    public function grouped(string $stored): string
    {
        if (1 !== preg_match('/^\+[1-9][0-9]{6,14}$/D', $stored)) {
            return $stored;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            return $util->format($util->parse($stored, 'ZZ'), PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $stored;
        }
    }

    public function doubt(string $stored): string
    {
        return $this->translator->trans('doubt.phone', ['%value%' => $this->grouped($stored)], self::DOMAIN);
    }

    private function example(string $region): ?string
    {
        $util = PhoneNumberUtil::getInstance();
        $example = $util->getExampleNumber($region);

        return null === $example ? null : $util->format($example, PhoneNumberFormat::INTERNATIONAL);
    }
}

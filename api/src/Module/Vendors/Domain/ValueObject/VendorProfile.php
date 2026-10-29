<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain\ValueObject;

use App\Module\Vendors\Domain\Exception\InvalidVendor;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\RegistrationNumbers;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Refusal;
use Symfony\Component\Uid\Uuid;

/**
 * What a company knows of a vendor (docs/SPEC.md § 4 vendor): names, registration numbers, contact, an address, the
 * bank account it is paid on, its payment terms and internal notes. Values are kept without stray spaces, bank codes
 * uppercase without any space, and an empty value is no value; which registration numbers a vendor may carry is its
 * company's preset's to say, checked by the use case.
 */
final readonly class VendorProfile
{
    public const int NAME_MAX = 200;
    public const int PAYMENT_TERMS_MAX = 365;
    public const int LEGAL_NAME_MAX = 200;
    public const int NOTES_MAX = 5000;
    /** An address part as the form names it. */
    private const array ADDRESS_FIELDS = ['line1' => 'addressLine1', 'line2' => 'addressLine2', 'postalCode' => 'postalCode', 'city' => 'city', 'countryCode' => 'countryCode'];

    public string $name;
    public ?string $legalName;
    /** @var array<string, string> by identifier key, in key order */
    public array $identifiers;
    public ?string $email;
    public ?string $phone;
    public ?string $website;
    public ?string $iban;
    public ?string $bic;
    /** Days after the vendor's invoice its payment is due, from 0 to a year; null when the vendor says nothing. */
    public ?int $paymentTermsDays;
    public ?string $notes;

    /**
     * @param array<string, string|null> $identifiers
     *
     * @throws InvalidVendor
     */
    public function __construct(
        string $name,
        ?string $legalName = null,
        array $identifiers = [],
        ?string $email = null,
        ?string $phone = null,
        ?string $website = null,
        public PostalAddress $address = new PostalAddress(),
        ?string $iban = null,
        ?string $bic = null,
        ?int $paymentTermsDays = null,
        ?string $notes = null,
        /** The expense category its expenses usually go to; checked against the company by the use case. */
        public ?Uuid $defaultExpenseCategoryId = null,
    ) {
        $name = TypedText::trimmed($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidVendor('name', \sprintf('A vendor is named in 1 to %d characters.', self::NAME_MAX), 'invalid_text', ['min' => 1, 'max' => self::NAME_MAX]);
        }
        $this->name = $name;
        $this->legalName = self::text($legalName);
        $this->identifiers = RegistrationNumbers::kept($identifiers);
        $this->email = self::text($email);
        $this->phone = self::text($phone);
        $this->website = self::text($website);
        $this->iban = self::code(null === $iban ? null : ContactRules::account($iban));
        $this->bic = self::code($bic);
        if (null !== $paymentTermsDays && ($paymentTermsDays < 0 || $paymentTermsDays > self::PAYMENT_TERMS_MAX)) {
            throw new InvalidVendor('paymentTermsDays', \sprintf('Payment terms run from 0 to %d days.', self::PAYMENT_TERMS_MAX), 'invalid_payment_terms', ['max' => self::PAYMENT_TERMS_MAX]);
        }
        $this->paymentTermsDays = $paymentTermsDays;
        $this->notes = self::text($notes);
    }

    /**
     * The same profile carrying these registration numbers, as the preset stores them (`IdentifierRules::normalised`).
     *
     * @param array<string, string> $identifiers
     */
    public function withIdentifiers(array $identifiers): self
    {
        return clone ($this, ['identifiers' => RegistrationNumbers::kept($identifiers)]);
    }

    /** The same profile carrying this phone, as the numbering plans keep it (E.164, or a value kept before them). */
    public function withPhone(?string $phone): self
    {
        return clone ($this, ['phone' => self::text($phone)]);
    }

    /** @return list<string> the fields whose value differs from the other profile's, in declaration order */
    public function differencesFrom(self $other): array
    {
        $theirs = $other->comparable();

        return array_keys(array_filter($this->comparable(), static fn (mixed $value, string $field): bool => $value !== $theirs[$field], \ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Whether saving this profile over the one held sets or changes the bank details, the IBAN or the BIC, which asks
     * who is at the screen again (docs/SPEC.md § 7, the step-up list). Compared once normalised, so the same details
     * written as a person types them change nothing.
     */
    public function changesBankDetailsOf(?self $held): bool
    {
        if (null === $held) {
            return null !== $this->iban || null !== $this->bic;
        }

        return [] !== array_intersect(['iban', 'bic'], $this->differencesFrom($held));
    }

    /** @return array<string, mixed> */
    private function comparable(): array
    {
        return [
            'name' => $this->name,
            'legalName' => $this->legalName,
            'identifiers' => $this->identifiers,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => $this->address->parts(),
            'iban' => $this->iban,
            'bic' => $this->bic,
            'paymentTermsDays' => $this->paymentTermsDays,
            'notes' => $this->notes,
            'defaultExpenseCategoryId' => $this->defaultExpenseCategoryId?->toRfc4122(),
        ];
    }

    /**
     * Holds what is written to the rules the form holds it to, each refusal under the form field it names. Asked by the
     * vendor as it is created or revised, never as a profile is read back from a stored row. A contact the vendor
     * already holds is judged only once it changes (row 208): one kept before today's rule never refuses another change.
     *
     * @param array<string, ?string> $held the e-mail and phone the vendor holds now, by field
     *
     * @throws InvalidVendor
     */
    public function check(array $held = []): void
    {
        $kept = array_intersect_assoc(['email' => $this->email, 'phone' => $this->phone], $held);
        foreach ($this->refusals() as $field => $refusal) {
            if (null !== $refusal && !\array_key_exists($field, $kept)) {
                throw new InvalidVendor($field, $refusal->message, $refusal->reason, $refusal->params);
            }
        }
    }

    /** @return iterable<string, ?Refusal> each field the form holds, in the form's order, and what it refuses there */
    private function refusals(): iterable
    {
        if (null !== $this->legalName && null !== ContactRules::text($this->legalName, self::LEGAL_NAME_MAX)) {
            yield 'legalName' => new Refusal('invalid_legal_name', \sprintf('A legal name is at most %d characters.', self::LEGAL_NAME_MAX));
        }
        yield 'email' => null === $this->email ? null : ContactRules::email($this->email);
        yield 'phone' => null === $this->phone ? null : ContactRules::phone($this->phone);
        yield 'website' => null === $this->website ? null : ContactRules::website($this->website);
        $part = $this->address->refusal();
        if (null !== $part) {
            yield self::ADDRESS_FIELDS[$part[0]] => $part[1];
        }
        yield 'iban' => null === $this->iban ? null : ContactRules::iban($this->iban);
        yield 'bic' => null === $this->bic ? null : ContactRules::bic($this->bic);
        yield 'notes' => null === $this->notes ? null : ContactRules::text($this->notes, self::NOTES_MAX);
    }

    private static function text(?string $value): ?string
    {
        $value = null === $value ? '' : TypedText::trimmed($value);

        return '' === $value ? null : $value;
    }

    private static function code(?string $value): ?string
    {
        $value = strtoupper((string) preg_replace('/\s+/u', '', (string) $value));

        return '' === $value ? null : $value;
    }
}

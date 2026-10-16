<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain\ValueObject;

use App\Module\Customers\Domain\Exception\InvalidCustomer;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\RegistrationNumbers;
use App\Shared\Domain\ValueObject\Refusal;
use BcMath\Number;

/**
 * What a company knows of a customer and prints on its documents (docs/SPEC.md § 4 customer): kind, names,
 * registration numbers, contact, the billing address and a shipping address when it differs, a default discount and
 * internal notes. Values are kept without stray spaces and an empty value is no value; which registration numbers a
 * customer carries is its company's preset's to say, checked by the use case.
 */
final readonly class CustomerProfile
{
    public const int NAME_MAX = 200;
    public const int LEGAL_NAME_MAX = 200;
    public const int NOTES_MAX = 5000;
    /** An address part as the form names it after « billing » or « shipping ». */
    private const array ADDRESS_FIELDS = ['line1' => 'AddressLine1', 'line2' => 'AddressLine2', 'postalCode' => 'PostalCode', 'city' => 'City', 'countryCode' => 'CountryCode'];
    private const string RATE = '/^(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,3}))?$/';

    public string $name;
    public ?string $legalName;
    /** @var array<string, string> by identifier key, in key order */
    public array $identifiers;
    public ?string $email;
    public ?string $phone;
    public ?string $website;
    /** Null when goods go to the billing address. */
    public ?PostalAddress $shippingAddress;
    /** A percentage with three decimals, from 0.000 to 100.000. */
    public ?string $defaultDiscountRate;
    public ?string $notes;

    /**
     * @param array<string, string|null> $identifiers
     *
     * @throws InvalidCustomer
     */
    public function __construct(
        public CustomerKind $kind,
        string $name,
        ?string $legalName = null,
        array $identifiers = [],
        ?string $email = null,
        ?string $phone = null,
        ?string $website = null,
        public PostalAddress $billingAddress = new PostalAddress(),
        ?PostalAddress $shippingAddress = null,
        ?string $defaultDiscountRate = null,
        ?string $notes = null,
    ) {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidCustomer('name', \sprintf('A customer is named in 1 to %d characters.', self::NAME_MAX), 'invalid_text', ['min' => 1, 'max' => self::NAME_MAX]);
        }
        $this->name = $name;
        $this->legalName = self::text($legalName);
        $this->identifiers = RegistrationNumbers::kept($identifiers);
        $this->email = self::text($email);
        $this->phone = self::text($phone);
        $this->website = self::text($website);
        $this->shippingAddress = null === $shippingAddress || $shippingAddress->isEmpty() ? null : $shippingAddress;
        $this->defaultDiscountRate = self::rate($defaultDiscountRate);
        $this->notes = self::text($notes);
    }

    /**
     * Holds what is written to the rules the form holds it to, each refusal under the form field it names. Asked by the
     * customer as it is created or revised, never as a profile is read back from a stored row.
     *
     * @throws InvalidCustomer
     */
    public function check(): void
    {
        foreach ($this->refusals() as $field => $refusal) {
            if (null !== $refusal) {
                throw new InvalidCustomer($field, $refusal->message, $refusal->reason, $refusal->params);
            }
        }
    }

    /**
     * A company billed at the seller's own country, the customer a preset may require registration numbers of: one
     * abroad is registered under another country's rules, and a billing address with no country is taken as home.
     */
    public function isDomesticBusiness(string $sellerCountryCode): bool
    {
        $country = $this->billingAddress->countryCode;

        return CustomerKind::Company === $this->kind && (null === $country || $country === $sellerCountryCode);
    }

    /** @return list<string> the fields whose value differs from the other profile's, in declaration order */
    public function differencesFrom(self $other): array
    {
        $theirs = $other->comparable();

        return array_keys(array_filter($this->comparable(), static fn (mixed $value, string $field): bool => $value !== $theirs[$field], \ARRAY_FILTER_USE_BOTH));
    }

    /** @return array<string, mixed> */
    private function comparable(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'legalName' => $this->legalName,
            'identifiers' => $this->identifiers,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'billingAddress' => $this->billingAddress->parts(),
            'shippingAddress' => $this->shippingAddress?->parts(),
            'defaultDiscountRate' => $this->defaultDiscountRate,
            'notes' => $this->notes,
        ];
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
        foreach (['billing' => $this->billingAddress, 'shipping' => $this->shippingAddress] as $which => $address) {
            $part = $address?->refusal();
            if (null !== $part) {
                yield $which.self::ADDRESS_FIELDS[$part[0]] => $part[1];
            }
        }
        yield 'notes' => null === $this->notes ? null : ContactRules::text($this->notes, self::NOTES_MAX);
    }

    private static function rate(?string $rate): ?string
    {
        $rate = self::text($rate);
        if (null === $rate) {
            return null;
        }
        if (1 !== preg_match(self::RATE, $rate, $parts) || !is_numeric($rate) || 1 === new Number($rate)->compare(100)) {
            throw new InvalidCustomer('defaultDiscountRate', 'A discount rate is a percentage from 0 to 100 with at most three decimals.', 'invalid_rate');
        }

        return $parts[1].'.'.str_pad($parts[2] ?? '', 3, '0');
    }

    private static function text(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}

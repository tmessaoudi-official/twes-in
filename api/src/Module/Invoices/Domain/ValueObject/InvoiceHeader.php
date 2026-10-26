<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain\ValueObject;

use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Model\Payment;
use App\Shared\Domain\Service\TypedText;

/**
 * What an invoice says besides its customer, its lines and its document taxes: the day the goods or services were
 * supplied, its payment terms in days (none: the customer's at issue), the customer's own reference, notes printed
 * and notes kept inside the company, a discount on the whole document as an amount, and the category of its operations
 * where its country's law asks for one (none: worked out at issue from its lines). Empty texts are absent.
 */
final readonly class InvoiceHeader
{
    public const int REFERENCE_MAX = 64;
    public const int TEXT_MAX = 5000;
    public const int TERMS_MAX = 365;
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';

    public ?\DateTimeImmutable $supplyDate;
    public ?string $customerReference;
    public ?string $notesPrinted;
    public ?string $notesInternal;
    /** Three decimals; whether it fits the currency is the use case's to say. */
    public ?string $discountAmount;

    /** @throws InvalidInvoice */
    public function __construct(
        ?\DateTimeImmutable $supplyDate = null,
        public ?int $paymentTermsDays = null,
        ?string $customerReference = null,
        ?string $notesPrinted = null,
        ?string $notesInternal = null,
        ?string $discountAmount = null,
        public ?OperationCategory $operationCategory = null,
    ) {
        if (null !== $paymentTermsDays && ($paymentTermsDays < 0 || $paymentTermsDays > self::TERMS_MAX)) {
            throw new InvalidInvoice('paymentTermsDays', \sprintf('Payment terms run from 0 to %d days.', self::TERMS_MAX), 'invalid_payment_terms', ['max' => self::TERMS_MAX]);
        }
        $this->supplyDate = null === $supplyDate ? null : new \DateTimeImmutable($supplyDate->format('Y-m-d'), new \DateTimeZone('UTC'));
        $this->customerReference = self::text('customerReference', $customerReference, self::REFERENCE_MAX);
        $this->notesPrinted = self::text('notesPrinted', $notesPrinted, self::TEXT_MAX);
        $this->notesInternal = self::text('notesInternal', $notesInternal, self::TEXT_MAX);
        $this->discountAmount = self::amount($discountAmount);
    }

    /** @return list<string> the fields whose values differ from the other's, in the order a form shows them */
    public function differencesFrom(self $other): array
    {
        $changed = [];
        if ($this->supplyDate?->format('Y-m-d') !== $other->supplyDate?->format('Y-m-d')) {
            $changed[] = 'supplyDate';
        }
        foreach (['paymentTermsDays', 'customerReference', 'notesPrinted', 'notesInternal', 'discountAmount', 'operationCategory'] as $field) {
            if ($this->{$field} !== $other->{$field}) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function text(string $field, ?string $value, int $max): ?string
    {
        $value = TypedText::trimmed($value ?? '');
        if (mb_strlen($value) > $max) {
            throw new InvalidInvoice($field, \sprintf('At most %d characters.', $max), 'invalid_text', ['max' => $max]);
        }

        return '' === $value ? null : $value;
    }

    private static function amount(?string $amount): ?string
    {
        $amount = TypedText::trimmed($amount ?? '');
        if ('' === $amount) {
            return null;
        }
        if (1 !== preg_match(self::AMOUNT, $amount)) {
            throw new InvalidInvoice('discountAmount', 'A discount is an amount from 0 with at most three decimals.', 'invalid_discount', ['decimals' => 3]);
        }
        [$units, $decimals] = [...explode('.', $amount), ''];

        return $units.'.'.str_pad($decimals, 3, '0');
    }
}

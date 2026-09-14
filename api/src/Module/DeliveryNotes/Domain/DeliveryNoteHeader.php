<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Shared\Domain\PostalAddress;

/**
 * What a delivery note says besides its customer and its lines: the day the goods are expected, where they go, the
 * customer's own reference, remarks printed on the note and notes kept inside the company. Empty texts are absent.
 */
final readonly class DeliveryNoteHeader
{
    public const int REFERENCE_MAX = 64;
    public const int TEXT_MAX = 5000;

    public ?\DateTimeImmutable $deliveryDate;
    public ?string $customerReference;
    public ?string $remarksPrinted;
    public ?string $notesInternal;

    /** @throws InvalidDeliveryNote */
    public function __construct(
        ?\DateTimeImmutable $deliveryDate = null,
        public PostalAddress $deliveryAddress = new PostalAddress(),
        ?string $customerReference = null,
        ?string $remarksPrinted = null,
        ?string $notesInternal = null,
    ) {
        $this->deliveryDate = null === $deliveryDate ? null : new \DateTimeImmutable($deliveryDate->format('Y-m-d'), new \DateTimeZone('UTC'));
        $this->customerReference = self::text('customerReference', $customerReference, self::REFERENCE_MAX);
        $this->remarksPrinted = self::text('remarksPrinted', $remarksPrinted, self::TEXT_MAX);
        $this->notesInternal = self::text('notesInternal', $notesInternal, self::TEXT_MAX);
    }

    /** @return list<string> the fields whose values differ from the other's, in the order a form shows them */
    public function differencesFrom(self $other): array
    {
        $changed = [];
        if ($this->deliveryDate?->format('Y-m-d') !== $other->deliveryDate?->format('Y-m-d')) {
            $changed[] = 'deliveryDate';
        }
        if (!$this->deliveryAddress->equals($other->deliveryAddress)) {
            $changed[] = 'deliveryAddress';
        }
        foreach (['customerReference', 'remarksPrinted', 'notesInternal'] as $field) {
            if ($this->{$field} !== $other->{$field}) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function text(string $field, ?string $value, int $max): ?string
    {
        $value = trim($value ?? '');
        if (mb_strlen($value) > $max) {
            throw new InvalidDeliveryNote($field, \sprintf('At most %d characters.', $max));
        }

        return '' === $value ? null : $value;
    }
}

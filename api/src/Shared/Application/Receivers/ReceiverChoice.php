<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Receivers;

use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Receiver;

/**
 * Who received a document, as a person chose it: one of the customer's contacts, or a name typed, which may be added to
 * the customer's contacts as it is recorded. A contact chosen wins over a name typed beside it.
 */
final readonly class ReceiverChoice
{
    public function __construct(
        public ?string $contactId = null,
        public ?string $name = null,
        public bool $addToContacts = false,
    ) {
    }

    /**
     * Whether this is the receiver already named, as a form that read it sends it back or a request sent again: kept as
     * it was copied, nothing looked up, nobody added twice. A name typed again is the same one unless it asks to be
     * added and is not yet a contact.
     */
    public function names(?Receiver $current): bool
    {
        if (null === $current) {
            return false;
        }
        if (null !== $this->contactId) {
            return $this->contactId === $current->contactId?->toRfc4122();
        }

        return TypedText::trimmed($this->name ?? '') === $current->name && (null !== $current->contactId || !$this->addToContacts);
    }
}

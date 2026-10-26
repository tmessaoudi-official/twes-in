<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Receivers;

use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\Refusal;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Who received a document, from what a person chose: a contact of the document's customer, its name copied as it is
 * now, or a name typed, added to the customer's contacts when asked, in the caller's transaction, so a document refused
 * afterwards takes the new contact back with it. Each document's module says a refusal in its own words for the field.
 */
final readonly class ChooseReceiver
{
    public function __construct(private ReceiverContacts $contacts)
    {
    }

    /** Null when nobody was named; a refusal when the choice cannot be a receiver of this customer's document. */
    public function receiver(Company $company, Uuid $customerId, ?ReceiverChoice $choice, ?Uuid $actorUserId): Receiver|Refusal|null
    {
        if (null !== $choice?->contactId) {
            $contactId = Uuid::isValid($choice->contactId) ? Uuid::fromString($choice->contactId) : null;
            $name = null === $contactId ? null : $this->contacts->nameOf($company, $customerId, $contactId);
            if (null === $contactId || null === $name || null !== Receiver::refusal($name)) {
                return new Refusal('unknown_choice', 'This contact is not one of the customer\'s: choose one of its contacts, or type a name.');
            }

            return Receiver::named($name, $contactId);
        }
        $typed = $choice->name ?? '';
        if ('' === TypedText::trimmed($typed)) {
            return null;
        }
        $refused = Receiver::refusal($typed);
        if (null !== $refused) {
            return $refused;
        }
        if (!$choice?->addToContacts) {
            return Receiver::named($typed);
        }
        $added = $this->contacts->add($company, $customerId, Receiver::named($typed)->name, $actorUserId);

        return $added instanceof ReceiverContactRefused
            ? new Refusal($added->reason, $added->message, $added->params)
            : Receiver::named($typed, $added);
    }
}

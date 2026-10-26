<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Receivers;

use App\Module\Customers\Application\ManageContacts;
use App\Module\Customers\Domain\Exception\InvalidContact;
use App\Module\Customers\Domain\Model\Contact;
use App\Module\Customers\Domain\Repository\ContactRepository;
use App\Module\Customers\Domain\ValueObject\ContactDetails;
use App\Shared\Application\Receivers\ReceiverContact;
use App\Shared\Application\Receivers\ReceiverContactRefused;
use App\Shared\Application\Receivers\ReceiverContacts;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The customers' answer to a document naming who received it: a contact read by its customer, and a typed name added
 * through the contacts' own use case, recorded like any contact added by hand.
 */
final readonly class CustomerReceiverContacts implements ReceiverContacts
{
    public function __construct(private ContactRepository $contacts, private ManageContacts $manage)
    {
    }

    public function contactsOf(Company $company, Uuid $customerId): array
    {
        $contacts = array_filter($this->contacts->ofCustomer($customerId), static fn (Contact $contact): bool => $contact->getCompany()->getId()->equals($company->getId()));

        return array_values(array_map(static fn (Contact $contact): ReceiverContact => new ReceiverContact($contact->getId(), self::name($contact)), $contacts));
    }

    public function nameOf(Company $company, Uuid $customerId, Uuid $contactId): ?string
    {
        $contact = $this->contacts->ofIdForCustomer($contactId, $customerId);
        if (null === $contact || !$contact->getCompany()->getId()->equals($company->getId())) {
            return null;
        }

        return self::name($contact);
    }

    public function add(Company $company, Uuid $customerId, string $name, ?Uuid $actorUserId): Uuid|ReceiverContactRefused
    {
        $words = explode(' ', $name, 2);
        try {
            $details = 2 === \count($words) ? new ContactDetails($words[0], $words[1], null, null, null) : new ContactDetails(null, $name, null, null, null);
        } catch (InvalidContact $refused) {
            return new ReceiverContactRefused($refused->reason ?? 'invalid_value', $refused->getMessage(), $refused->params);
        }

        return $this->manage->add($company, $customerId, $details, false, $actorUserId)->getId();
    }

    private static function name(Contact $contact): string
    {
        $details = $contact->getDetails();

        return TypedText::trimmed(($details->firstName ?? '').' '.($details->lastName ?? ''));
    }
}

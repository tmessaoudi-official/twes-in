<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Receivers;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A customer's contacts, as a document naming who received it reads them: answered by the customers' module, which
 * owns them, so no document's module reaches into it.
 */
interface ReceiverContacts
{
    /**
     * The customer's contacts a form offers as receivers, its main contact first, then in the order they were added;
     * none for a customer of another company.
     *
     * @return list<ReceiverContact>
     */
    public function contactsOf(Company $company, Uuid $customerId): array;

    /** The contact's name as a document prints it, or null when the customer has no such contact. */
    public function nameOf(Company $company, Uuid $customerId, Uuid $contactId): ?string;

    /**
     * A new contact of the customer, named as typed: the first word its first name, the rest its last. Its id, or the
     * refusal of a name a contact cannot carry.
     */
    public function add(Company $company, Uuid $customerId, string $name, ?Uuid $actorUserId): Uuid|ReceiverContactRefused;
}

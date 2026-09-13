<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

use Symfony\Component\Uid\Uuid;

interface ContactRepository
{
    /** @return list<Contact> one customer's contacts, the primary one first, then in the order they were added */
    public function ofCustomer(Uuid $customerId): array;

    /** Null for a contact that does not exist or belongs to another customer. */
    public function ofIdForCustomer(Uuid $id, Uuid $customerId): ?Contact;

    public function save(Contact $contact): void;

    public function remove(Contact $contact): void;
}

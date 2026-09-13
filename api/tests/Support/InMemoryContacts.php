<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Customers\Domain\Contact;
use App\Module\Customers\Domain\ContactRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryContacts implements ContactRepository
{
    /** @var list<Contact> */
    public array $contacts = [];

    public function ofCustomer(Uuid $customerId): array
    {
        $theirs = array_values(array_filter($this->contacts, static fn (Contact $c) => $c->getCustomer()->getId()->equals($customerId)));
        usort($theirs, static fn (Contact $a, Contact $b) => [!$a->isPrimary(), $a->getId()->toRfc4122()] <=> [!$b->isPrimary(), $b->getId()->toRfc4122()]);

        return $theirs;
    }

    public function ofIdForCustomer(Uuid $id, Uuid $customerId): ?Contact
    {
        foreach ($this->ofCustomer($customerId) as $contact) {
            if ($contact->getId()->equals($id)) {
                return $contact;
            }
        }

        return null;
    }

    public function save(Contact $contact): void
    {
        if (!\in_array($contact, $this->contacts, true)) {
            $this->contacts[] = $contact;
        }
    }

    public function remove(Contact $contact): void
    {
        $this->contacts = array_values(array_filter($this->contacts, static fn (Contact $c) => $c !== $contact));
    }
}

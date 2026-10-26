<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Receivers\ReceiverContact;
use App\Shared\Application\Receivers\ReceiverContactRefused;
use App\Shared\Application\Receivers\ReceiverContacts;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Each customer's contacts by id and name; the names added are kept in order. */
final class InMemoryReceiverContacts implements ReceiverContacts
{
    /** @var array<string, array<string, string>> customer id => contact id => name */
    public array $contacts = [];

    /** @var list<array{string, string}> customer id and name, in the order they were added */
    public array $added = [];

    public ?ReceiverContactRefused $refuseAdding = null;

    public function contactsOf(Company $company, Uuid $customerId): array
    {
        $contacts = [];
        foreach ($this->contacts[$customerId->toRfc4122()] ?? [] as $id => $name) {
            $contacts[] = new ReceiverContact(Uuid::fromString($id), $name);
        }

        return $contacts;
    }

    public function nameOf(Company $company, Uuid $customerId, Uuid $contactId): ?string
    {
        return $this->contacts[$customerId->toRfc4122()][$contactId->toRfc4122()] ?? null;
    }

    public function add(Company $company, Uuid $customerId, string $name, ?Uuid $actorUserId): Uuid|ReceiverContactRefused
    {
        if (null !== $this->refuseAdding) {
            return $this->refuseAdding;
        }
        $id = Uuid::v7();
        $this->contacts[$customerId->toRfc4122()][$id->toRfc4122()] = $name;
        $this->added[] = [$customerId->toRfc4122(), $name];

        return $id;
    }
}

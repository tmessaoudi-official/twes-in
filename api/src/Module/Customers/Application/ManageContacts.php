<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Customers\Domain\Contact;
use App\Module\Customers\Domain\ContactDetails;
use App\Module\Customers\Domain\ContactRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The people at a customer. A customer's first contact is its primary one; making another primary hands it over, and
 * removing the primary contact makes the next one primary. Audited without values: contacts are people.
 */
final readonly class ManageContacts
{
    public const string ENTITY_TYPE = 'contact';
    public const string CREATED = 'contact.created';
    public const string REVISED = 'contact.revised';
    public const string DELETED = 'contact.deleted';

    public function __construct(
        private ContactRepository $contacts,
        private CustomerRepository $customers,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<Contact>
     *
     * @throws CustomerNotFound
     */
    public function list(Company $company, Uuid $customerId): array
    {
        return $this->contacts->ofCustomer($this->customer($company, $customerId)->getId());
    }

    /** @throws CustomerNotFound */
    public function add(Company $company, Uuid $customerId, ContactDetails $details, bool $isPrimary, ?Uuid $actorUserId): Contact
    {
        $customer = $this->customer($company, $customerId);
        $others = $this->contacts->ofCustomer($customer->getId());
        $isPrimary = $isPrimary || [] === $others;
        if ($isPrimary) {
            $this->stepDown($others);
        }
        $contact = Contact::create($customer, $details, $isPrimary, $this->clock->now());
        $this->contacts->save($contact);
        $this->record($company, $contact->getId(), self::CREATED, $actorUserId);

        return $contact;
    }

    /**
     * @throws CustomerNotFound
     * @throws ContactNotFound
     */
    public function revise(Company $company, Uuid $customerId, Uuid $contactId, ContactDetails $details, bool $isPrimary, ?Uuid $actorUserId): Contact
    {
        $contact = $this->contact($company, $customerId, $contactId);
        $now = $this->clock->now();
        $changed = $contact->revise($details, $now);
        if ($isPrimary && !$contact->isPrimary()) {
            $this->stepDown(array_values(array_filter($this->contacts->ofCustomer($customerId), static fn (Contact $other) => $other !== $contact)));
        }
        if ($contact->markPrimary($isPrimary, $now) || $changed) {
            $this->contacts->save($contact);
            $this->record($company, $contact->getId(), self::REVISED, $actorUserId);
        }

        return $contact;
    }

    /**
     * @throws CustomerNotFound
     * @throws ContactNotFound
     */
    public function remove(Company $company, Uuid $customerId, Uuid $contactId, ?Uuid $actorUserId): void
    {
        $contact = $this->contact($company, $customerId, $contactId);
        $wasPrimary = $contact->isPrimary();
        $this->contacts->remove($contact);
        $next = $wasPrimary ? ($this->contacts->ofCustomer($customerId)[0] ?? null) : null;
        if (null !== $next && $next->markPrimary(true, $this->clock->now())) {
            $this->contacts->save($next);
        }
        $this->record($company, $contactId, self::DELETED, $actorUserId);
    }

    /**
     * The primary contact steps down and is saved first, so the partial unique index never sees two at once.
     *
     * @param list<Contact> $others
     */
    private function stepDown(array $others): void
    {
        foreach ($others as $other) {
            if ($other->markPrimary(false, $this->clock->now())) {
                $this->contacts->save($other);
            }
        }
    }

    private function customer(Company $company, Uuid $customerId): Customer
    {
        return $this->customers->ofIdInCompany($customerId, $company->getId()) ?? throw new CustomerNotFound();
    }

    private function contact(Company $company, Uuid $customerId, Uuid $contactId): Contact
    {
        return $this->contacts->ofIdForCustomer($contactId, $this->customer($company, $customerId)->getId()) ?? throw new ContactNotFound();
    }

    private function record(Company $company, Uuid $contactId, string $action, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $contactId, $action, $actorUserId, [], $company->getId()));
    }
}

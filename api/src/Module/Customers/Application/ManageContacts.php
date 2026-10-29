<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Module\Customers\Domain\Exception\InvalidContact;
use App\Module\Customers\Domain\Model\Contact;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Repository\ContactRepository;
use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\Customers\Domain\ValueObject\ContactDetails;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Shared\Application\TypedPhones;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The people at a customer. A customer's first contact is its primary one; making another primary hands it over, and
 * removing the primary contact makes the next one primary. Audited without values: contacts are people.
 */
final readonly class ManageContacts implements DeclaresAuditActions
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
        private Transactions $transactions,
        private RecordVersions $versions,
        private TypedPhones $phones,
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

    /**
     * @throws CustomerNotFound
     * @throws InvalidContact
     */
    public function add(Company $company, Uuid $customerId, ContactDetails $details, bool $isPrimary, ?Uuid $actorUserId): Contact
    {
        return $this->transactions->run(function () use ($company, $customerId, $details, $isPrimary, $actorUserId): Contact {
            $customer = $this->customer($company, $customerId);
            $details = $this->withPhoneAsKept($company, $customer, $details, null);
            $others = $this->contacts->ofCustomer($customer->getId());
            $isPrimary = $isPrimary || [] === $others;
            if ($isPrimary) {
                $this->stepDown($others);
            }
            $contact = Contact::create($customer, $details, $isPrimary, $this->clock->now());
            $this->contacts->save($contact);
            $this->record($company, $contact->getId(), self::CREATED, $actorUserId);

            return $contact;
        });
    }

    /**
     * @throws CustomerNotFound
     * @throws ContactNotFound
     * @throws RecordChanged
     * @throws InvalidContact
     */
    public function revise(Company $company, Uuid $customerId, Uuid $contactId, ContactDetails $details, bool $isPrimary, ?Uuid $actorUserId, ?int $version = null): Contact
    {
        return $this->transactions->run(function () use ($company, $customerId, $contactId, $details, $isPrimary, $actorUserId, $version): Contact {
            $contact = $this->contact($company, $customerId, $contactId);
            if (null !== $version) {
                $this->versions->expect($contact, $version);
            }
            $details = $this->withPhoneAsKept($company, $contact->getCustomer(), $details, $contact->getDetails()->phone);
            $now = $this->clock->now();
            $changed = $contact->revise($details, $now);
            if ($isPrimary && !$contact->isPrimary()) {
                $this->stepDown(array_values(array_filter($this->contacts->ofCustomer($contact->getCustomer()->getId()), static fn (Contact $other) => $other !== $contact)));
            }
            if ($contact->markPrimary($isPrimary, $now) || $changed) {
                $this->contacts->save($contact);
                $this->record($company, $contact->getId(), self::REVISED, $actorUserId);
            }

            return $contact;
        });
    }

    /**
     * @throws CustomerNotFound
     * @throws ContactNotFound
     */
    public function remove(Company $company, Uuid $customerId, Uuid $contactId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $customerId, $contactId, $actorUserId): void {
            $contact = $this->contact($company, $customerId, $contactId);
            $wasPrimary = $contact->isPrimary();
            $holder = $contact->getCustomer()->getId();
            $this->contacts->remove($contact);
            $next = $wasPrimary ? ($this->contacts->ofCustomer($holder)[0] ?? null) : null;
            if (null !== $next && $next->markPrimary(true, $this->clock->now())) {
                $this->contacts->save($next);
            }
            $this->record($company, $contactId, self::DELETED, $actorUserId);
        });
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

    /**
     * The details with their phone as kept (`TypedPhones`), read by the customer's billing country, else the company's.
     * A contact has no « À vérifier » of its own yet: a number outside the ones handed out is kept, unmarked.
     *
     * @throws InvalidContact
     */
    private function withPhoneAsKept(Company $company, Customer $customer, ContactDetails $details, ?string $held): ContactDetails
    {
        $kept = $this->phones->kept($details->phone, $held, $customer->getProfile()->billingAddress->countryCode ?? $company->getCountryCode());
        if (null !== $kept->refusal) {
            throw new InvalidContact('phone', $kept->refusal->message, $kept->refusal->reason, $kept->refusal->params);
        }

        return $details->withPhone($kept->phone);
    }

    /** A customer merged into another handed it its contacts: its address reaches the customer kept's. */
    private function customer(Company $company, Uuid $customerId): Customer
    {
        return ($this->customers->ofIdInCompany($customerId, $company->getId()) ?? throw new CustomerNotFound())->standingFor();
    }

    private function contact(Company $company, Uuid $customerId, Uuid $contactId): Contact
    {
        return $this->contacts->ofIdForCustomer($contactId, $this->customer($company, $customerId)->getId()) ?? throw new ContactNotFound();
    }

    private function record(Company $company, Uuid $contactId, string $action, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $contactId, $action, $actorUserId, [], $company->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::CREATED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::DELETED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::REVISED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}

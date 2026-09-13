<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Someone at a customer (docs/SPEC.md § 4 contact). At most one contact of a customer is its primary one, held by a
 * partial unique index; the company is kept on the row like every business table's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contact')]
#[ORM\Index(name: 'idx_contact_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_contact_customer', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_contact_primary', columns: ['customer_id'], options: ['where' => 'is_primary'])]
class Contact
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: ContactDetails::NAME_MAX, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: ContactDetails::NAME_MAX, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: ContactDetails::NAME_MAX, nullable: true)]
    private ?string $role = null;

    #[ORM\Column]
    private bool $isPrimary = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Customer $customer, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $customer->getCompany();
        $this->customer = $customer;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function create(Customer $customer, ContactDetails $details, bool $isPrimary, \DateTimeImmutable $now): self
    {
        $contact = new self($customer, $now);
        $contact->apply($details);
        $contact->isPrimary = $isPrimary;

        return $contact;
    }

    /** @return bool whether anything changed */
    public function revise(ContactDetails $details, \DateTimeImmutable $now): bool
    {
        if ($details->equals($this->getDetails())) {
            return false;
        }
        $this->apply($details);
        $this->updatedAt = $now;

        return true;
    }

    /** @return bool whether this changed anything */
    public function markPrimary(bool $isPrimary, \DateTimeImmutable $now): bool
    {
        if ($isPrimary === $this->isPrimary) {
            return false;
        }
        $this->isPrimary = $isPrimary;
        $this->updatedAt = $now;

        return true;
    }

    public function getDetails(): ContactDetails
    {
        return new ContactDetails($this->firstName, $this->lastName, $this->email, $this->phone, $this->role);
    }

    private function apply(ContactDetails $details): void
    {
        [$this->firstName, $this->lastName, $this->email, $this->phone, $this->role] = [$details->firstName, $details->lastName, $details->email, $details->phone, $details->role];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }
}

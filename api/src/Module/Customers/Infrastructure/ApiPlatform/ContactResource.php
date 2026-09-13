<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Customers\Domain\Contact;
use App\Module\Customers\Domain\ContactDetails;
use App\Module\Customers\Domain\InvalidContact;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The people at one customer, reached only through that customer of that company. Read with customer.read, changed
 * with customer.write; the first contact is the primary one, and setting `isPrimary` on another hands it over.
 */
#[ApiResource(
    shortName: 'Contact',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/contacts',
            provider: ContactCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/contacts',
            processor: AddContactProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/contacts/{contactId}',
            processor: ReviseContactProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/customers/{customerId}/contacts/{contactId}',
            processor: RemoveContactProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class ContactResource
{
    public const string READ = 'contact:read';
    public const string WRITE = 'contact:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\Length(max: ContactDetails::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $firstName = null;

    #[Assert\Length(max: ContactDetails::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $lastName = null;

    #[Assert\Email(groups: [self::WRITE])]
    #[Assert\Length(max: 254, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $email = null;

    #[Assert\Regex(pattern: '/^\+?[0-9 ().\-]{3,40}$/', groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $phone = null;

    #[Assert\Length(max: ContactDetails::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $role = null;

    #[Groups([self::READ, self::WRITE])]
    public bool $isPrimary = false;

    public static function of(Contact $contact): self
    {
        $details = $contact->getDetails();
        $resource = new self();
        $resource->id = $contact->getId()->toRfc4122();
        [$resource->firstName, $resource->lastName, $resource->email, $resource->phone, $resource->role] = [$details->firstName, $details->lastName, $details->email, $details->phone, $details->role];
        $resource->isPrimary = $contact->isPrimary();

        return $resource;
    }

    /** @throws InvalidContact */
    public function details(): ContactDetails
    {
        return new ContactDetails($this->firstName, $this->lastName, $this->email, $this->phone, $this->role);
    }
}

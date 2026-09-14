<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Tenancy\Application\Establishment\EstablishmentDetails;
use App\Tenancy\Domain\Establishment;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's establishments. Read with company.read, added and revised with company.settings; every row carries the
 * shape its company's fiscal preset gives a code, so a form can check it before sending.
 */
#[ApiResource(
    shortName: 'Establishment',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/establishments',
            provider: EstablishmentCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/establishments',
            processor: CreateEstablishmentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/establishments/{establishmentId}',
            processor: ReviseEstablishmentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class EstablishmentResource
{
    public const string READ = 'establishment:read';
    public const string WRITE = 'establishment:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Regex(pattern: Establishment::CODE, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $code = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: 120, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine1 = null;

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine2 = null;

    #[Assert\Length(max: 20, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $postalCode = null;

    #[Assert\Length(max: 120, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $city = null;

    #[Assert\Length(max: 40, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $phone = null;

    #[Assert\Email(groups: [self::WRITE])]
    #[Assert\Length(max: 254, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $email = null;

    /** Setting it hands the role over from the current default; a default is never simply unset. */
    #[Groups([self::READ, self::WRITE])]
    public bool $isDefault = false;

    /** A regular expression, without delimiters, a code matches whole. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $codePattern = '';

    /** Whether numbered documents carry the code, which then no longer changes. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $codeLocked = false;

    public static function of(Establishment $establishment, string $codePattern, bool $codeLocked): self
    {
        $resource = new self();
        $resource->id = $establishment->getId()->toRfc4122();
        $resource->code = $establishment->getCode();
        $resource->name = $establishment->getName();
        $resource->addressLine1 = $establishment->getAddressLine1();
        $resource->addressLine2 = $establishment->getAddressLine2();
        $resource->postalCode = $establishment->getPostalCode();
        $resource->city = $establishment->getCity();
        $resource->phone = $establishment->getPhone();
        $resource->email = $establishment->getEmail();
        $resource->isDefault = $establishment->isDefault();
        $resource->codePattern = $codePattern;
        $resource->codeLocked = $codeLocked;

        return $resource;
    }

    public function details(): EstablishmentDetails
    {
        return new EstablishmentDetails($this->code, $this->name, $this->addressLine1, $this->addressLine2, $this->postalCode, $this->city, $this->phone, $this->email, $this->isDefault);
    }
}

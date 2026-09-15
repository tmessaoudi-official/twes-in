<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the vendor form offers, read with vendor.read alone: the company's country and the registration numbers its
 * preset knows, with their shape, labelled in the reader's language. None is required of a vendor.
 */
#[ApiResource(
    shortName: 'VendorOptions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/vendor-options',
            provider: VendorOptionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class VendorOptionsResource
{
    public const string READ = 'vendor_options:read';

    /** The company's country: an address without one is there. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $countryCode = '';

    /** @var list<VendorIdentifierOption> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'label', 'pattern'],
            'properties' => ['key' => ['type' => 'string'], 'label' => ['type' => 'string'], 'pattern' => ['type' => 'string', 'description' => 'A regular expression the whole value matches, without delimiters.']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $identifiers = [];
}

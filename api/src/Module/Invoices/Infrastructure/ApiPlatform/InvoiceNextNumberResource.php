<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The number a draft would carry if it were issued now, which the question before issuing says (docs/SPEC.md § 7,
 * 2026-09-26 23:04). Nothing is taken: 409 when the document is no longer a draft or its establishment cannot number
 * it today.
 */
#[ApiResource(
    shortName: 'InvoiceNextNumber',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/next-number',
            provider: InvoiceNextNumberProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class InvoiceNextNumberResource
{
    public const string READ = 'invoice_next_number:read';

    #[ApiProperty(identifier: false, required: true)]
    #[Groups([self::READ])]
    public string $number = '';
}

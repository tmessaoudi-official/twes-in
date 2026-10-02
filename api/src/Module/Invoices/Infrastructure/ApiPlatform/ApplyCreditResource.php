<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Pays an issued invoice from what its customer has to their credit (docs/SPEC.md § 7), with payment.write: an ordinary
 * payment of the amount, dated today, and the same amount off the balance. The answer is the invoice. An invoice that is
 * not issued answers 409; an amount above what is due, above what is to the customer's credit or finer than the currency, 422.
 */
#[ApiResource(
    shortName: 'ApplyCredit',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/apply-credit',
            processor: ApplyCreditProcessor::class,
            security: 'is_granted("ROLE_USER")',
            status: 200,
            output: InvoiceResource::class,
            normalizationContext: InvoiceResource::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class ApplyCreditResource
{
    public const string WRITE = 'apply_credit:write';

    /** Above 0, at most what is due and what is to the customer's credit, in the currency's decimals; left out, as much as fits. */
    #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '100.000'])]
    #[Groups([self::WRITE])]
    public ?string $amount = null;
}

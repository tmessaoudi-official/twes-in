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
use App\Module\Invoices\Domain\PaymentDetails;
use App\Shared\Domain\PaymentMethod;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Money the customer paid beyond an issued invoice with nothing left due, a « trop-perçu », kept to their credit
 * (docs/SPEC.md § 7). An advance before any invoice is not this: it takes a deposit invoice (docs/fiscal, § 2b).
 * Takes `payment.write` and `customer.read`, 409 while something is still due on the invoice; it answers nothing, and
 * the balance is read at GET .../customers/{customerId}/credit-balance.
 */
#[ApiResource(
    shortName: 'Overpayment',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/overpayments',
            processor: OverpaymentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            status: 204,
            output: false,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class OverpaymentResource
{
    public const string WRITE = 'overpayment:write';

    /** The day the money was received, YYYY-MM-DD, from the invoice's issue day to the company's today. */
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $date = '';

    /** Above 0, in the currency's decimals. */
    #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '250.500'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $amount = '';

    /** A transfer or check number, say. */
    #[Assert\Length(max: PaymentDetails::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $reference = null;

    #[Assert\Length(max: PaymentDetails::NOTES_MAX, groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $notes = null;

    /** @throws \App\Module\Invoices\Domain\InvalidInvoice */
    public function details(): PaymentDetails
    {
        return new PaymentDetails(new \DateTimeImmutable($this->date, new \DateTimeZone('UTC')), $this->amount, PaymentMethod::Other, $this->reference, $this->notes);
    }
}

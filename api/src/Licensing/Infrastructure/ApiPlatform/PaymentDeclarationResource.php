<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Licensing\Domain\DeclaredPayment;
use App\Licensing\Domain\InvalidPayment;
use App\Licensing\Domain\PaymentDeclaration;
use App\Licensing\Domain\PaymentMethod;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A payment a company says it made (docs/SPEC.md § 7, 2026-09-17). The company declares it, which keeps it open while
 * the operator has not answered; the operator reads what waits and confirms or rejects it, and a confirmation is what
 * carries the covered time forward. Nothing here takes money: many companies pay in cash.
 */
#[ApiResource(
    shortName: 'PaymentDeclaration',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/subscription/payments',
            name: self::DECLARE_PAYMENT,
            status: 201,
            processor: DeclarePaymentProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
        ),
        new GetCollection(
            uriTemplate: '/platform/payment-declarations',
            name: self::WAITING,
            provider: WaitingPaymentsProvider::class,
            security: 'is_granted("'.PlatformSubscriptionResource::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/platform/payment-declarations/{declarationId}/confirm',
            name: self::CONFIRM,
            status: 200,
            processor: DecidePaymentProcessor::class,
            security: 'is_granted("'.PlatformSubscriptionResource::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::DECIDE]],
        ),
        new Post(
            uriTemplate: '/platform/payment-declarations/{declarationId}/reject',
            name: self::REJECT,
            status: 200,
            processor: DecidePaymentProcessor::class,
            security: 'is_granted("'.PlatformSubscriptionResource::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::DECIDE]],
        ),
    ],
)]
final class PaymentDeclarationResource
{
    public const string READ = 'payment_declaration:read';
    public const string WRITE = 'payment_declaration:write';
    public const string DECIDE = 'payment_declaration:decide';
    public const string DECLARE_PAYMENT = 'declare_payment';
    public const string WAITING = 'platform_payment_declarations';
    public const string CONFIRM = 'confirm_payment';
    public const string REJECT = 'reject_payment';
    /** A decision covers at most this many periods at once: a typo is not a decade of free service. */
    public const int MAX_PERIODS = 60;
    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ, CompanySubscriptionResource::READ])]
    public string $id = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $companyId = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $companyName = '';

    /** A decimal string, as every amount in this API. */
    #[ApiProperty(required: true, schema: ['type' => 'string', 'pattern' => '^(0|[1-9]\d{0,9})(\.\d{1,3})?$'])]
    #[Groups([self::READ, self::WRITE, CompanySubscriptionResource::READ])]
    public string $amount = '';

    #[ApiProperty(required: true, schema: ['type' => 'string', 'pattern' => '^[A-Z]{3}$'])]
    #[Groups([self::READ, self::WRITE, CompanySubscriptionResource::READ])]
    public string $currency = '';

    #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['cash', 'transfer', 'cheque', 'other']])]
    #[Groups([self::READ, self::WRITE, CompanySubscriptionResource::READ])]
    public string $method = 'cash';

    /** The day the company says it paid, in its own timezone. */
    #[ApiProperty(required: true, schema: ['type' => 'string', 'format' => 'date'])]
    #[Groups([self::READ, self::WRITE, CompanySubscriptionResource::READ])]
    public string $paidOn = '';

    /** A receipt number, a transfer reference, a cheque number. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => DeclaredPayment::REFERENCE_MAX])]
    #[Groups([self::READ, self::WRITE, CompanySubscriptionResource::READ])]
    public ?string $reference = null;

    /** What the company wants the operator to know. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => DeclaredPayment::NOTE_MAX])]
    #[Groups([self::READ, self::WRITE, CompanySubscriptionResource::READ])]
    public ?string $note = null;

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'enum' => ['declared', 'confirmed', 'rejected']])]
    #[Groups([self::READ, CompanySubscriptionResource::READ])]
    public string $status = '';

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ, CompanySubscriptionResource::READ])]
    public string $declaredAt = '';

    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null']])]
    #[Groups([self::READ, CompanySubscriptionResource::READ])]
    public ?string $decidedAt = null;

    /** What the operator wrote when deciding: why it was rejected, what it was taken as. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => DeclaredPayment::NOTE_MAX])]
    #[Groups([self::READ, self::DECIDE, CompanySubscriptionResource::READ])]
    public ?string $decisionNote = null;

    /** How many billing periods a confirmation covers; ignored by a rejection. */
    #[ApiProperty(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PERIODS])]
    #[Groups([self::DECIDE])]
    public int $periods = 1;

    public static function of(PaymentDeclaration $declaration): self
    {
        $company = $declaration->getCompany();

        $resource = new self();
        $resource->id = $declaration->getId()->toRfc4122();
        $resource->companyId = $company->getId()->toRfc4122();
        $resource->companyName = $company->getName();
        $resource->amount = $declaration->getAmount();
        $resource->currency = $declaration->getCurrency();
        $resource->method = $declaration->getMethod()->value;
        $resource->paidOn = $declaration->getPaidOn()->format('Y-m-d');
        $resource->reference = $declaration->getReference();
        $resource->note = $declaration->getNote();
        $resource->status = $declaration->getStatus()->value;
        $resource->declaredAt = $declaration->getDeclaredAt()->format(\DATE_ATOM);
        $resource->decidedAt = $declaration->getDecidedAt()?->format(\DATE_ATOM);
        $resource->decisionNote = $declaration->getDecisionNote();

        return $resource;
    }

    /** @throws InvalidPayment for a method or a day the domain does not know; the amount is the domain's own guard */
    public function payment(): DeclaredPayment
    {
        $method = PaymentMethod::tryFrom($this->method) ?? throw new InvalidPayment('method: cash, transfer, cheque or other.');
        $paidOn = 1 === preg_match(self::DAY, $this->paidOn) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $this->paidOn) : false;
        if (false === $paidOn || $paidOn->format('Y-m-d') !== $this->paidOn) {
            throw new InvalidPayment('paidOn: a day written YYYY-MM-DD.');
        }

        return new DeclaredPayment($this->amount, $this->currency, $method, $paidOn, $this->reference, $this->note);
    }

    /** @throws InvalidPayment when the number of periods is not one this API decides on */
    public function periodsCovered(): int
    {
        if ($this->periods < 1 || $this->periods > self::MAX_PERIODS) {
            throw new InvalidPayment(\sprintf('periods: 1 to %d.', self::MAX_PERIODS));
        }

        return $this->periods;
    }
}

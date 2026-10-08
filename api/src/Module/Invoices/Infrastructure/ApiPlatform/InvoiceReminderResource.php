<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Module\Invoices\Domain\InvoiceReminder;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A stage of the company's reminder calendar an invoice reached (`reminders.stages`): the day it was time to remind the
 * customer, how late the invoice was then and the late fee it drafted. It records that the stage was reached, never
 * that anything was sent.
 * Read with `invoice.read`.
 */
#[ApiResource(
    shortName: 'InvoiceReminder',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/reminders',
            provider: InvoiceRemindersProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            paginationEnabled: false,
        ),
    ],
)]
final class InvoiceReminderResource
{
    public const string READ = 'invoice_reminder:read';

    #[ApiProperty(identifier: true)]
    #[Groups([self::READ])]
    public string $id = '';

    /** One for the calendar's first stage. */
    #[Groups([self::READ])]
    public int $stage = 1;

    /** How many days past its due day the invoice was when it reached the stage. */
    #[Groups([self::READ])]
    public int $daysLate = 0;

    /** The company's day the stage was reached, YYYY-MM-DD. */
    #[Groups([self::READ])]
    public string $reachedOn = '';

    /** The draft invoice of the late fee the stage charged, if the company charges one there. */
    #[Groups([self::READ])]
    public ?string $lateFeeInvoiceId = null;

    public static function of(InvoiceReminder $reminder): self
    {
        $resource = new self();
        $resource->id = $reminder->getId()->toRfc4122();
        $resource->stage = $reminder->getStage();
        $resource->daysLate = $reminder->getDaysLate();
        $resource->reachedOn = $reminder->getReachedOn()->format('Y-m-d');
        $resource->lateFeeInvoiceId = $reminder->getLateFeeInvoiceId()?->toRfc4122();

        return $resource;
    }
}

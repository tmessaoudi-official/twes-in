<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Purchases\Application\PurchaseOrderWorkflow;
use App\Module\Quotes\Application\QuoteWorkflow;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * A document numbered as its processor numbers it: racer i validates `documents[i]`, a delivery note, issues it, an
 * invoice, or sends it, a quote or a purchase order (`kinds[i]`), through its workflow, whose events move the stock as
 * they do after a request.
 */
final class SellDocument implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $entityManager = $container->get(EntityManagerInterface::class);
        $company = $entityManager->find(Company::class, Uuid::fromString(Rows::text($arguments['company'] ?? null)));
        if (!$company instanceof Company) {
            throw new \LogicException('The race names no company of the stress database.');
        }
        $documents = $arguments['documents'] ?? null;
        $document = Uuid::fromString(Rows::text(\is_array($documents) ? ($documents[$index] ?? null) : null));
        $actor = Uuid::fromString(Rows::text($arguments['actor'] ?? null));

        $kinds = $arguments['kinds'] ?? null;

        return match (\is_array($kinds) ? ($kinds[$index] ?? null) : null) {
            'invoice' => $container->get(InvoiceWorkflow::class)->issue($company, $document, $actor)->getNumber(),
            'delivery_note' => $container->get(DeliveryNoteWorkflow::class)->validate($company, $document, $actor)->getNumber(),
            'quote' => $container->get(QuoteWorkflow::class)->send($company, $document, $actor)->getNumber(),
            'purchase_order' => $container->get(PurchaseOrderWorkflow::class)->send($company, $document, $actor)->getNumber(),
            default => throw new \LogicException('The race names no kind of document this step numbers.'),
        };
    }
}

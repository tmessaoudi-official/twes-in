<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\CreditOrDeliverInvoice;
use Symfony\Component\HttpFoundation\Response;

/**
 * A draft delivering an issued invoice's goods is checked again when it is validated, under the invoice's lock, against
 * what its credit notes and its other notes leave. A credit note taking those goods back while the validation runs is in
 * another series, so only that lock makes the validation wait for it and read what it took back: read beforehand, the
 * note hands over goods the credit note has just brought back into stock.
 */
final class DeliverableRaceTest extends SaleRaceTestCase
{
    protected function saleWithoutStock(): string
    {
        return 'allow';
    }

    protected function onHand(): string
    {
        return '1';
    }

    public function testADraftValidatedWhileACreditNoteTakesItsGoodsBackIsRefused(): void
    {
        $customerId = $this->customer();
        $invoiceId = $this->draft('invoices', $customerId);
        $this->postJson($this->path('invoices/'.$invoiceId.'/issue'), null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('invoices/'.$invoiceId.'/deliveries'), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $noteId = $this->stringAt($this->json(), 'id');
        $creditId = $this->creditNoteTakingBack($invoiceId, $customerId);

        $outcomes = Race::run(CreditOrDeliverInvoice::class, 2, ['company' => $this->company->getId()->toRfc4122(), 'actor' => $this->sellerId, 'creditNote' => $creditId, 'note' => $noteId, 'lingerMs' => 600, 'signal' => random_int(1, 2_147_483_647)]);

        $described = implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes));
        self::assertSame(Outcome::OK, $outcomes[0]->kind, "the credit note is issued\n$described");
        self::assertSame(Outcome::ERROR, $outcomes[1]->kind, "the note validated after it is refused\n$described");
        self::assertStringContainsString('left to deliver', $outcomes[1]->message, $described);
        $note = $this->em()->getConnection()->fetchAssociative('SELECT status, number FROM delivery_note WHERE id = ?', [$noteId]);
        self::assertSame(['status' => 'draft', 'number' => null], $note, 'it stays a draft and takes no number');
        self::assertSame(0, Rows::int($this->em()->getConnection()->fetchOne("SELECT count(*) FROM delivery_note WHERE company_id = ? AND status <> 'draft'", [$this->company->getId()->toRfc4122()])), 'no note delivers the goods taken back');
    }

    /** A draft credit note of the invoice taking its one unit back into stock. */
    private function creditNoteTakingBack(string $invoiceId, string $customerId): string
    {
        $this->postJson($this->path('invoices/'.$invoiceId.'/credit-notes'), ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->sendJson('PUT', $this->path('invoices/'.$id), ['version' => $this->storedVersion('invoice', $id), 'customerId' => $customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '1', 'returned' => true]]]);
        self::assertResponseIsSuccessful();

        return $id;
    }
}

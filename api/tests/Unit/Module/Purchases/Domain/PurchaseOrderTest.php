<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Purchases\Domain;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Domain\Exception\PurchaseOrderTransitionRefused;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderHeader;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderLineDetails;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Module\Purchases\Domain\ValueObject\ReceivedGoods;
use App\Module\Purchases\Domain\ValueObject\VendorSnapshot;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\ReceiverRule;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class PurchaseOrderTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private Company $company;
    private Company $globex;
    private Uuid $vendor;
    private Uuid $shelf;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-10-10 09:00:00');
        $this->now = $clock->now();
        $this->units = new InMemoryUnits();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
        $this->vendor = Uuid::v7();
        $this->shelf = Uuid::v7();
    }

    public function testADraftHoldsItsLinesInOrderAtTheirScales(): void
    {
        $order = $this->draft();

        self::assertSame(PurchaseOrderStatus::Draft, $order->getStatus());
        self::assertNull($order->getNumber());
        self::assertSame('Quincaillerie du Nord', $order->getVendorName());
        self::assertSame('2026-10-20', $order->getHeader()->expectedOn?->format('Y-m-d'));
        [$screws, $wire] = $order->getLines();
        self::assertSame([0, 'VIS-6', '10.000', 'C62', '0.3500', '0.000'], [$screws->getPosition(), $screws->getProductReference(), $screws->getQuantity(), $screws->getUnit()->getCode(), $screws->getUnitPriceNet(), $screws->getReceived()]);
        self::assertSame([1, 'FIL-2', '2.500', 'KGM', null], [$wire->getPosition(), $wire->getProductReference(), $wire->getQuantity(), $wire->getUnit()->getCode(), $wire->getUnitPriceNet()]);
    }

    public function testALineIsRefusedAQuantityFinerThanItsUnitCountsOrNoneAtAll(): void
    {
        foreach (['1.5' => 'counts with 0 decimals', '0' => 'positive quantity', '-2' => 'decimal number', '1.2345' => 'decimal number'] as $quantity => $said) {
            try {
                new PurchaseOrderLineDetails(Uuid::v7(), 'VIS-6', 'Vis', (string) $quantity, $this->unit('C62'), null);
                self::fail(\sprintf('%s was taken', $quantity));
            } catch (InvalidPurchaseOrder $refused) {
                self::assertSame('quantity', $refused->field);
                self::assertStringContainsString($said, $refused->getMessage());
            }
        }
    }

    public function testAnOrderIsPlacedByAnEstablishmentOfItsOwnCompany(): void
    {
        $this->expectExceptionObject(new InvalidPurchaseOrder('establishmentId', 'An order is placed by an establishment of its own company.'));

        PurchaseOrder::create($this->company, $this->establishment($this->globex), $this->vendor, 'Nord', new PurchaseOrderHeader(), $this->lines(), $this->now);
    }

    public function testARevisionSaysWhichFieldsChangedAndNoneWhenNothingDid(): void
    {
        $order = $this->draft();

        self::assertSame([], $order->revise($this->establishment(), $this->vendor, 'Quincaillerie du Nord', $order->getHeader(), $this->lines(), $this->now));
        self::assertSame(['expectedOn', 'lines'], $order->revise($this->establishment(), $this->vendor, 'Quincaillerie du Nord', new PurchaseOrderHeader(new \DateTimeImmutable('2026-10-25'), 'Livrer le matin'), [$this->lines()[0]], $this->now));
        self::assertCount(1, $order->getLines());
    }

    public function testARenamedVendorShowsUnderItsNewNameOnADraftWithoutCountingAsAChange(): void
    {
        $order = $this->draft();

        self::assertSame([], $order->revise($this->establishment(), $this->vendor, 'Nord Outillage', $order->getHeader(), $this->lines(), $this->now));
        self::assertSame('Nord Outillage', $order->getVendorName());
    }

    public function testSendingNumbersTheOrderAndKeepsWhoTheVendorAndTheBuyerWereThatDay(): void
    {
        $order = $this->sent();

        self::assertSame(PurchaseOrderStatus::Sent, $order->getStatus());
        self::assertSame('BCF-2026-0001', $order->getNumber());
        self::assertSame('2026-10-10', $order->getIssueDate()?->format('Y-m-d'));
        self::assertSame('Nord SARL', $order->getVendorSnapshot()?->legalName);
        self::assertSame('Nord', $order->getVendorName());
        self::assertSame('Acme', $order->getBuyerSnapshot()?->name);
    }

    public function testASentOrderNoLongerChanges(): void
    {
        $order = $this->sent();

        $this->expectException(PurchaseOrderTransitionRefused::class);
        $order->revise($this->establishment(), $this->vendor, 'Nord', new PurchaseOrderHeader(), $this->lines(), $this->now);
    }

    public function testADraftWithoutLinesIsNotSent(): void
    {
        $order = PurchaseOrder::create($this->company, $this->establishment(), $this->vendor, 'Nord', new PurchaseOrderHeader(), [], $this->now);

        $this->expectExceptionObject(new InvalidPurchaseOrder('lines', 'An order is sent with at least one line.'));
        $order->send('BCF-2026-0001', $this->now, $this->vendorSnapshot(), $this->now);
    }

    public function testGoodsArriveInPartsUntilNothingIsOutstanding(): void
    {
        $order = $this->sent();
        [$screws, $wire] = $order->getLines();

        $first = $order->receive(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'), ' BL-881 ', [
            new ReceivedGoods($screws->getId(), $this->shelf, '4'),
            new ReceivedGoods($screws->getId(), Uuid::v7(), '2', 'L-12', new \DateTimeImmutable('2027-01-31'), '0.34'),
        ], null, $this->now);

        self::assertSame(PurchaseOrderStatus::PartlyReceived, $order->getStatus());
        self::assertSame(['6.000', '4.000'], [$screws->getReceived(), $screws->outstanding()]);
        self::assertSame('2.500', $wire->outstanding());
        self::assertSame('BL-881', $first->getSupplierReference());
        [$a, $b] = $first->getLines();
        self::assertSame(['4.000', null, null], [$a->getQuantity(), $a->getLotCode(), $a->getUnitCost()]);
        self::assertSame(['2.000', 'L-12', '2027-01-31', '0.3400'], [$b->getQuantity(), $b->getLotCode(), $b->getLotExpiresOn()?->format('Y-m-d'), $b->getUnitCost()]);

        $order->receive(new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14'), null, [
            new ReceivedGoods($screws->getId(), $this->shelf, '4'),
            new ReceivedGoods($wire->getId(), $this->shelf, '2.5'),
        ], null, $this->now);

        self::assertSame(PurchaseOrderStatus::Received, $order->getStatus());
        self::assertSame(['0.000', '0.000'], [$screws->outstanding(), $wire->outstanding()]);
        self::assertCount(2, $order->getReceipts());
    }

    public function testAReceiptBringsNoMoreThanIsOutstandingSummedOverItsParts(): void
    {
        $order = $this->sent();
        [$screws] = $order->getLines();

        try {
            $order->receive($this->now, $this->now, null, [
                new ReceivedGoods($screws->getId(), $this->shelf, '6'),
                new ReceivedGoods($screws->getId(), Uuid::v7(), '5'),
            ], null, $this->now);
            self::fail('Eleven of ten were received.');
        } catch (InvalidPurchaseOrder $refused) {
            self::assertSame('lines[1].quantity', $refused->field);
            self::assertSame('10.000 of VIS-6 is still expected: a receipt brings no more than that.', $refused->getMessage());
        }
        self::assertSame(['0.000', PurchaseOrderStatus::Sent, []], [$screws->getReceived(), $order->getStatus(), $order->getReceipts()]);
    }

    public function testAReceiptIsRefusedALineOfAnotherOrderOrAQuantityTheUnitDoesNotCount(): void
    {
        $order = $this->sent();
        [$screws] = $order->getLines();
        $other = $this->sent();

        $this->assertRefused('lines[0].orderLineId', fn () => $order->receive($this->now, $this->now, null, [new ReceivedGoods($other->getLines()[0]->getId(), $this->shelf, '1')], null, $this->now));
        $this->assertRefused('lines[0].quantity', fn () => $order->receive($this->now, $this->now, null, [new ReceivedGoods($screws->getId(), $this->shelf, '1.5')], null, $this->now));
        $this->assertRefused('lines', fn () => $order->receive($this->now, $this->now, null, [], null, $this->now));
    }

    public function testGoodsArriveFromTheIssueDayToTodayOnly(): void
    {
        $order = $this->sent();
        [$screws] = $order->getLines();
        $goods = [new ReceivedGoods($screws->getId(), $this->shelf, '1')];

        $this->assertRefused('receivedOn', fn () => $order->receive(new \DateTimeImmutable('2026-10-09'), $this->now, null, $goods, null, $this->now));
        $this->assertRefused('receivedOn', fn () => $order->receive(new \DateTimeImmutable('2026-10-11'), $this->now, null, $goods, null, $this->now));
        $order->receive(new \DateTimeImmutable('2026-10-10 23:30'), $this->now, null, $goods, null, $this->now);
        self::assertSame('1.000', $screws->getReceived());
    }

    public function testAUseByDateBelongsToALot(): void
    {
        $this->expectExceptionObject(new InvalidPurchaseOrder('lotExpiresOn', 'A use-by date belongs to a lot: name the lot.'));

        new ReceivedGoods(Uuid::v7(), $this->shelf, '1', ' ', new \DateTimeImmutable('2027-01-01'));
    }

    public function testNothingArrivesAgainstADraftACancelledOrAClosedOrder(): void
    {
        $draft = $this->draft();
        $this->assertTransitionRefused(fn () => $draft->receive($this->now, $this->now, null, [new ReceivedGoods($draft->getLines()[0]->getId(), $this->shelf, '1')], null, $this->now));

        $cancelled = $this->sent();
        $cancelled->cancel($this->now);
        self::assertSame(PurchaseOrderStatus::Cancelled, $cancelled->getStatus());
        $this->assertTransitionRefused(fn () => $cancelled->receive($this->now, $this->now, null, [new ReceivedGoods($cancelled->getLines()[0]->getId(), $this->shelf, '1')], null, $this->now));

        $closed = $this->sent();
        $closed->receive($this->now, $this->now, null, [new ReceivedGoods($closed->getLines()[0]->getId(), $this->shelf, '1')], null, $this->now);
        $closed->close($this->now);
        self::assertSame(PurchaseOrderStatus::Closed, $closed->getStatus());
        $this->assertTransitionRefused(fn () => $closed->receive($this->now, $this->now, null, [new ReceivedGoods($closed->getLines()[0]->getId(), $this->shelf, '1')], null, $this->now));
    }

    public function testAnOrderSomethingArrivedAgainstIsClosedNeverCancelledAndOneNothingArrivedAgainstIsCancelledNeverClosed(): void
    {
        $partly = $this->sent();
        $partly->receive($this->now, $this->now, null, [new ReceivedGoods($partly->getLines()[0]->getId(), $this->shelf, '1')], null, $this->now);
        $this->assertTransitionRefused(fn () => $partly->cancel($this->now));

        $sent = $this->sent();
        $this->assertTransitionRefused(fn () => $sent->close($this->now));

        $draft = $this->draft();
        $draft->cancel($this->now);
        self::assertSame(PurchaseOrderStatus::Cancelled, $draft->getStatus());
    }

    public function testAReceivedOrderIsNeitherClosedNorCancelled(): void
    {
        $order = $this->sent();
        [$screws, $wire] = $order->getLines();
        $order->receive($this->now, $this->now, null, [new ReceivedGoods($screws->getId(), $this->shelf, '10'), new ReceivedGoods($wire->getId(), $this->shelf, '2.5')], null, $this->now);

        $this->assertTransitionRefused(fn () => $order->close($this->now));
        $this->assertTransitionRefused(fn () => $order->cancel($this->now));
    }

    public function testAReceiptKeepsWhoReceivedItAsNamedThenAndTheOrderListsEachReceiverOnce(): void
    {
        $order = $this->sent();
        [$screws, $wire] = $order->getLines();
        $sami = Uuid::v7();

        $first = $order->receive(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'), null, [new ReceivedGoods($screws->getId(), $this->shelf, '4')], null, $this->now, ReceiverRule::Optional, Receiver::named('Sami Ben Ali', $sami));
        $order->receive(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'), null, [new ReceivedGoods($screws->getId(), $this->shelf, '1')], null, $this->now, ReceiverRule::Optional, Receiver::named('Le chauffeur'));
        $order->receive(new \DateTimeImmutable('2026-10-13'), new \DateTimeImmutable('2026-10-13'), null, [new ReceivedGoods($wire->getId(), $this->shelf, '1')], null, $this->now, ReceiverRule::Optional, Receiver::named('Sami Ben Ali', $sami));
        $order->receive(new \DateTimeImmutable('2026-10-13'), new \DateTimeImmutable('2026-10-13'), null, [new ReceivedGoods($wire->getId(), $this->shelf, '1')], null, $this->now);

        self::assertTrue(Receiver::named('Sami Ben Ali', $sami)->equals($first->receiver()));
        self::assertNull($order->getReceipts()[3]->receiver(), 'nobody named, nobody kept');
        self::assertSame('Sami Ben Ali, Le chauffeur', $order->getReceivers(), 'each receiver once, in the order the goods came');
    }

    public function testTheRuleRefusesANameWhereItIsOffAndNobodyWhereItIsRequiredAndNothingArrives(): void
    {
        $order = $this->sent();
        [$screws] = $order->getLines();
        $goods = [new ReceivedGoods($screws->getId(), $this->shelf, '4')];

        foreach ([[ReceiverRule::Off, Receiver::named('Sami'), 'receiver_off'], [ReceiverRule::Required, null, 'receiver_required']] as [$rule, $receiver, $reason]) {
            try {
                $order->receive(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'), null, $goods, null, $this->now, $rule, $receiver);
                self::fail(\sprintf('%s was taken', $reason));
            } catch (InvalidPurchaseOrder $refused) {
                self::assertSame(['receivedBy', $reason], [$refused->field, $refused->reason]);
            }
        }
        self::assertSame([PurchaseOrderStatus::Sent, [], '0.000'], [$order->getStatus(), $order->getReceipts(), $screws->getReceived()]);

        $order->receive(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'), null, $goods, null, $this->now, ReceiverRule::Required, Receiver::named('Sami'));
        $order->receive(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'), null, [new ReceivedGoods($screws->getId(), $this->shelf, '1')], null, $this->now, ReceiverRule::Off);
        self::assertCount(2, $order->getReceipts());
    }

    public function testWhoReceivedIsPutRightOnTheReceiptAndInTheOrdersListWhateverTheOrdersStatus(): void
    {
        $order = $this->sent();
        [$screws, $wire] = $order->getLines();
        $first = $order->receive(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'), null, [new ReceivedGoods($screws->getId(), $this->shelf, '10')], null, $this->now, ReceiverRule::Optional, Receiver::named('Sammi'));
        $order->receive(new \DateTimeImmutable('2026-10-13'), new \DateTimeImmutable('2026-10-13'), null, [new ReceivedGoods($wire->getId(), $this->shelf, '2.5')], null, $this->now, ReceiverRule::Optional, Receiver::named('Le chauffeur'));
        self::assertSame(PurchaseOrderStatus::Received, $order->getStatus());
        $later = $this->now->modify('+1 hour');

        self::assertTrue($order->correctReceiver($first->getId(), ReceiverRule::Optional, Receiver::named('Sami Ben Ali'), $later));
        self::assertSame(['Sami Ben Ali', 'Sami Ben Ali, Le chauffeur'], [$first->receiver()?->name, $order->getReceivers()]);
        self::assertEquals($later, $order->getUpdatedAt());
        self::assertFalse($order->correctReceiver($first->getId(), ReceiverRule::Optional, Receiver::named('Sami Ben Ali'), $later), 'the same receiver again changes nothing');

        $this->assertRefused('receivedBy', static fn () => $order->correctReceiver($first->getId(), ReceiverRule::Required, null, $later));
        $this->assertRefused('receivedBy', static fn () => $order->correctReceiver($first->getId(), ReceiverRule::Off, Receiver::named('Sami'), $later));
        self::assertTrue($order->correctReceiver($first->getId(), ReceiverRule::Off, null, $later), 'switched off, a name recorded by mistake is taken away');
        self::assertSame([null, 'Le chauffeur'], [$first->receiver(), $order->getReceivers()]);
        self::assertTrue($order->hasReceipt($first->getId()));
        self::assertFalse($order->hasReceipt(Uuid::v7()));
    }

    private function draft(): PurchaseOrder
    {
        return PurchaseOrder::create($this->company, $this->establishment(), $this->vendor, 'Quincaillerie du Nord', new PurchaseOrderHeader(new \DateTimeImmutable('2026-10-20 15:00'), 'Livrer le matin', null), $this->lines(), $this->now);
    }

    private function sent(): PurchaseOrder
    {
        $order = $this->draft();
        $order->send('BCF-2026-0001', $this->now, $this->vendorSnapshot(), $this->now);

        return $order;
    }

    /** @return list<PurchaseOrderLineDetails> */
    private function lines(): array
    {
        return [
            new PurchaseOrderLineDetails(Uuid::fromString('0199f000-0000-7000-8000-000000000001'), 'VIS-6', 'Vis 6 mm', '10', $this->unit('C62'), '0.35'),
            new PurchaseOrderLineDetails(Uuid::fromString('0199f000-0000-7000-8000-000000000002'), 'FIL-2', 'Fil de fer 2 mm', '2.5', $this->unit('KGM'), null),
        ];
    }

    private function vendorSnapshot(): VendorSnapshot
    {
        return new VendorSnapshot('FRS-0001', 'Nord', 'Nord SARL', ['tn_tax_id' => '1234567A'], '12 rue de Tunis', null, '1000', 'Tunis', 'TN', null, null);
    }

    private function establishment(?Company $company = null): Establishment
    {
        return $this->establishments->ofCompany(($company ?? $this->company)->getId())[0];
    }

    private function unit(string $code): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function assertRefused(string $field, \Closure $act): void
    {
        try {
            $act();
            self::fail(\sprintf('Nothing refused %s.', $field));
        } catch (InvalidPurchaseOrder $refused) {
            self::assertSame($field, $refused->field, $refused->getMessage());
        }
    }

    private function assertTransitionRefused(\Closure $act): void
    {
        try {
            $act();
            self::fail('The transition was taken.');
        } catch (PurchaseOrderTransitionRefused) {
            self::addToAssertionCount(1);
        }
    }
}

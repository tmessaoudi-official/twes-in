<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\Model;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Domain\Exception\PurchaseOrderTransitionRefused;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderHeader;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderLineDetails;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Module\Purchases\Domain\ValueObject\ReceivedGoods;
use App\Module\Purchases\Domain\ValueObject\VendorSnapshot;
use App\Shared\Domain\Service\CompanyOwned;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Goods an establishment of a company asks a vendor for (« bon de commande fournisseur »). A draft names its
 * establishment, its vendor and its lines and changes freely; sending it gives it its number and fixes what it says.
 * Goods then arrive against it in receipts, all at once or in parts, and the order stays open until everything ordered
 * has come, or is closed with what came. The vendor is held by its id and its name: the vendors are their own module.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order')]
#[ORM\Index(name: 'idx_purchase_order_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_purchase_order_establishment', columns: ['establishment_id'])]
#[ORM\Index(name: 'idx_purchase_order_vendor', columns: ['vendor_id'])]
#[ORM\UniqueConstraint(name: 'uniq_purchase_order_company_number', columns: ['company_id', 'number'])]
class PurchaseOrder implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private Establishment $establishment;

    #[ORM\Column(type: 'uuid')]
    private Uuid $vendorId;

    /** The vendor's name as the order last read it: while a draft, at each save; once sent, the day it was sent. */
    #[ORM\Column(length: 255)]
    private string $vendorName;

    #[ORM\Column(length: 20, enumType: PurchaseOrderStatus::class)]
    private PurchaseOrderStatus $status = PurchaseOrderStatus::Draft;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $number = null;

    /** The company's day the order was sent; null while it is a draft. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issueDate = null;

    /** The day the goods are expected; null when nobody said. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedOn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarksPrinted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesInternal = null;

    /** @var array<string, mixed>|null VendorSnapshot::toArray(), written when the order is sent */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $vendorSnapshot = null;

    /** @var array<string, mixed>|null SellerSnapshot::toArray(), written when the order is sent */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $buyerSnapshot = null;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, GoodsReceipt> */
    #[ORM\OneToMany(targetEntity: GoodsReceipt::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['receivedOn' => 'ASC', 'createdAt' => 'ASC'])]
    private Collection $receipts;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Doctrine counts each save, so an editor of an older version is told rather than overwriting it. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->lines = new ArrayCollection();
        $this->receipts = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<PurchaseOrderLineDetails> $lines
     *
     * @throws InvalidPurchaseOrder
     */
    public static function create(Company $company, Establishment $establishment, Uuid $vendorId, string $vendorName, PurchaseOrderHeader $header, array $lines, \DateTimeImmutable $now): self
    {
        $order = new self($company, $now);
        $order->establishment = $order->establishmentOfThisCompany($establishment);
        $order->vendorId = $vendorId;
        $order->vendorName = $vendorName;
        $order->apply($header);
        $order->writeLines($lines);

        return $order;
    }

    /**
     * @param list<PurchaseOrderLineDetails> $lines
     *
     * @return list<string> the fields that changed, none when the revision says what the order already says
     *
     * @throws PurchaseOrderTransitionRefused
     * @throws InvalidPurchaseOrder
     */
    public function revise(Establishment $establishment, Uuid $vendorId, string $vendorName, PurchaseOrderHeader $header, array $lines, \DateTimeImmutable $now): array
    {
        $this->assertDraft('changes');
        $establishment = $this->establishmentOfThisCompany($establishment);

        $changed = [];
        if (!$establishment->getId()->equals($this->establishment->getId())) {
            $changed[] = 'establishmentId';
        }
        if (!$vendorId->equals($this->vendorId)) {
            $changed[] = 'vendorId';
        }
        $changed = [...$changed, ...$header->differencesFrom($this->getHeader())];
        $linesChanged = array_map(static fn (PurchaseOrderLineDetails $line): array => $line->values(), $lines) !== array_map(static fn (PurchaseOrderLine $line): array => $line->values(), $this->getLines());
        if ($linesChanged) {
            $changed[] = 'lines';
        }
        // The vendor's name is what the draft last read of its card, so a renamed vendor shows under its new name.
        $this->vendorName = $vendorName;
        if ([] === $changed) {
            return [];
        }

        $this->establishment = $establishment;
        $this->vendorId = $vendorId;
        $this->apply($header);
        if ($linesChanged) {
            $this->writeLines($lines);
        }
        $this->updatedAt = $now;

        return $changed;
    }

    /**
     * Gives a draft with lines its number and issue day, and keeps who the vendor and the buyer were that day, which the
     * order prints from then on.
     *
     * @throws PurchaseOrderTransitionRefused
     * @throws InvalidPurchaseOrder
     */
    public function send(string $number, \DateTimeImmutable $issueDate, VendorSnapshot $vendor, \DateTimeImmutable $now): void
    {
        $this->assertDraft('is sent');
        if ($this->lines->isEmpty()) {
            throw new InvalidPurchaseOrder('lines', 'An order is sent with at least one line.');
        }
        $this->vendorSnapshot = $vendor->toArray();
        $this->vendorName = $vendor->name;
        $this->buyerSnapshot = SellerSnapshot::of($this->company, $this->establishment)->toArray();
        $this->status = PurchaseOrderStatus::Sent;
        $this->number = $number;
        $this->issueDate = self::day($issueDate);
        $this->updatedAt = $now;
    }

    /**
     * Records goods that arrived against the order on a day of the company's, from its issue day to today: each part
     * names a line of this order, and all parts of one line together never bring more than is still outstanding of it.
     * The order is received once nothing is outstanding, partly received until then.
     *
     * @param list<ReceivedGoods> $goods in the order the person gave them
     *
     * @throws PurchaseOrderTransitionRefused
     * @throws InvalidPurchaseOrder
     */
    public function receive(\DateTimeImmutable $receivedOn, \DateTimeImmutable $today, ?string $supplierReference, array $goods, ?Uuid $recordedBy, \DateTimeImmutable $now): GoodsReceipt
    {
        if (!$this->status->open()) {
            throw new PurchaseOrderTransitionRefused(\sprintf('The order %s is %s: goods are received against a sent order that is still open.', $this->reference(), $this->status->value));
        }
        if ([] === $goods) {
            throw new InvalidPurchaseOrder('lines', 'A receipt brings at least one line.');
        }
        $receivedOn = self::day($receivedOn);
        $issued = $this->issueDate ?? throw new \LogicException('A sent order has an issue day.');
        if ($receivedOn < $issued) {
            throw new InvalidPurchaseOrder('receivedOn', \sprintf('Goods arrive on or after the day the order was sent, %s.', $issued->format('Y-m-d')));
        }
        if ($receivedOn > self::day($today)) {
            throw new InvalidPurchaseOrder('receivedOn', 'Goods are received once they arrived, today at the latest.');
        }
        $reference = TypedText::trimmed($supplierReference ?? '');
        if (mb_strlen($reference) > GoodsReceipt::REFERENCE_MAX) {
            throw new InvalidPurchaseOrder('supplierReference', \sprintf('A supplier reference is at most %d characters.', GoodsReceipt::REFERENCE_MAX));
        }

        $parts = [];
        $brought = [];
        foreach ($goods as $index => $part) {
            $line = $this->lineOfThisOrder($part->orderLineId) ?? throw new InvalidPurchaseOrder(\sprintf('lines[%d].orderLineId', $index), 'A receipt brings goods of a line of its own order.');
            try {
                $quantity = PurchaseOrderLineDetails::quantity(TypedText::trimmed($part->quantity), $line->getUnit());
            } catch (InvalidPurchaseOrder $refused) {
                throw $refused->within(\sprintf('lines[%d]', $index));
            }
            $key = $line->getId()->toRfc4122();
            $brought[$key] = ($brought[$key] ?? Decimal::of('0'))->add(Decimal::of($quantity));
            if ($brought[$key]->compare(Decimal::of($line->outstanding())) > 0) {
                throw new InvalidPurchaseOrder(\sprintf('lines[%d].quantity', $index), \sprintf('%s of %s is still expected: a receipt brings no more than that.', $line->outstanding(), $line->getProductReference()));
            }
            $parts[] = [$line, new ReceivedGoods($part->orderLineId, $part->locationId, $quantity, $part->lotCode, $part->lotExpiresOn, $part->unitCost)];
        }

        $receipt = new GoodsReceipt($this, $receivedOn, '' === $reference ? null : $reference, $parts, $recordedBy, $now);
        foreach ($parts as [$line, $part]) {
            $line->receive($part->quantity);
        }
        $this->receipts->add($receipt);
        $this->status = $this->nothingOutstanding() ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartlyReceived;
        $this->updatedAt = $now;

        return $receipt;
    }

    /**
     * A draft, or a sent order nothing has arrived against, that will not be filled. A sent order keeps its number,
     * which is never given again.
     *
     * @throws PurchaseOrderTransitionRefused
     */
    public function cancel(\DateTimeImmutable $now): void
    {
        if (PurchaseOrderStatus::Draft !== $this->status && PurchaseOrderStatus::Sent !== $this->status) {
            throw new PurchaseOrderTransitionRefused(\sprintf('The order %s is %s: only a draft or a sent order nothing has arrived against is cancelled; one partly received is closed.', $this->reference(), $this->status->value));
        }
        $this->status = PurchaseOrderStatus::Cancelled;
        $this->updatedAt = $now;
    }

    /**
     * A partly received order whose rest is no longer expected: settled with what came, and closed to receipts.
     *
     * @throws PurchaseOrderTransitionRefused
     */
    public function close(\DateTimeImmutable $now): void
    {
        if (PurchaseOrderStatus::PartlyReceived !== $this->status) {
            throw new PurchaseOrderTransitionRefused(\sprintf('The order %s is %s: only a partly received order is closed; one nothing arrived against is cancelled.', $this->reference(), $this->status->value));
        }
        $this->status = PurchaseOrderStatus::Closed;
        $this->updatedAt = $now;
    }

    /** @throws PurchaseOrderTransitionRefused */
    /**
     * Each line's vendor's own code for its product, by product id. Read again on every save of the draft and when it is
     * sent, never typed and never counted as a change of the lines: what the order keeps is what the product said when
     * the order went out, under whichever vendor it then names.
     *
     * @param array<string, string> $codes
     *
     * @throws PurchaseOrderTransitionRefused
     */
    public function keepVendorCodes(array $codes): void
    {
        $this->assertDraft('reads its vendor\'s codes');
        foreach ($this->lines as $line) {
            $line->keepVendorCode($codes[$line->getProductId()->toRfc4122()] ?? null);
        }
    }

    public function assertDraft(string $what): void
    {
        if (PurchaseOrderStatus::Draft !== $this->status) {
            throw new PurchaseOrderTransitionRefused(\sprintf('The order %s is %s: only a draft %s.', $this->reference(), $this->status->value, $what));
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEstablishment(): Establishment
    {
        return $this->establishment;
    }

    public function getVendorId(): Uuid
    {
        return $this->vendorId;
    }

    public function getVendorName(): string
    {
        return $this->vendorName;
    }

    public function getStatus(): PurchaseOrderStatus
    {
        return $this->status;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getIssueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function getHeader(): PurchaseOrderHeader
    {
        return new PurchaseOrderHeader($this->expectedOn, $this->remarksPrinted, $this->notesInternal);
    }

    public function getVendorSnapshot(): ?VendorSnapshot
    {
        return null === $this->vendorSnapshot ? null : VendorSnapshot::fromArray($this->vendorSnapshot);
    }

    public function getBuyerSnapshot(): ?SellerSnapshot
    {
        return null === $this->buyerSnapshot ? null : SellerSnapshot::fromArray($this->buyerSnapshot);
    }

    /** @return list<PurchaseOrderLine> */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** @return list<GoodsReceipt> the oldest first */
    public function getReceipts(): array
    {
        return array_values($this->receipts->toArray());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    private function apply(PurchaseOrderHeader $header): void
    {
        $this->expectedOn = null === $header->expectedOn ? null : self::day($header->expectedOn);
        $this->remarksPrinted = $header->remarksPrinted;
        $this->notesInternal = $header->notesInternal;
    }

    /** @param list<PurchaseOrderLineDetails> $lines */
    private function writeLines(array $lines): void
    {
        $this->lines->clear();
        foreach ($lines as $position => $details) {
            $this->lines->add(new PurchaseOrderLine($this, $position, $details));
        }
    }

    private function lineOfThisOrder(Uuid $id): ?PurchaseOrderLine
    {
        foreach ($this->lines as $line) {
            if ($line->getId()->equals($id)) {
                return $line;
            }
        }

        return null;
    }

    private function nothingOutstanding(): bool
    {
        foreach ($this->lines as $line) {
            if ('0.000' !== $line->outstanding()) {
                return false;
            }
        }

        return true;
    }

    private function reference(): string
    {
        return $this->number ?? $this->id->toRfc4122();
    }

    private function establishmentOfThisCompany(Establishment $establishment): Establishment
    {
        if (!$establishment->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidPurchaseOrder('establishmentId', 'An order is placed by an establishment of its own company.', 'unknown_choice');
        }

        return $establishment;
    }

    private static function day(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}

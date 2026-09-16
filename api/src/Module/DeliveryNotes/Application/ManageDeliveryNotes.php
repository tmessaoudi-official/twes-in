<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's delivery notes while they are drafts. A note goes to one of the company's active customers from one of
 * its establishments; a line delivers one of its active products or states what it is, in an active unit, with active
 * line taxes the customer's regime charges. A customer, product, unit or tax retired since stays with the note that
 * already names it. The lines must total. Audited with the names of the fields a revision changed, never their values.
 */
final readonly class ManageDeliveryNotes
{
    public const string ENTITY_TYPE = 'delivery_note';
    public const string CREATED = 'delivery_note.created';
    public const string REVISED = 'delivery_note.revised';

    public function __construct(
        private DeliveryNoteRepository $notes,
        private Transactions $transactions,
        private CustomerRepository $customers,
        private ProductRepository $products,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private EstablishmentRepository $establishments,
        private DeliveryNoteTotals $totals,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<DeliveryNote> */
    public function list(Company $company): array
    {
        return $this->notes->ofCompany($company->getId());
    }

    /** @throws DeliveryNoteNotFound */
    public function get(Company $company, Uuid $id): DeliveryNote
    {
        return $this->notes->ofIdInCompany($id, $company->getId()) ?? throw new DeliveryNoteNotFound();
    }

    /** @throws InvalidDeliveryNote */
    public function create(Company $company, DeliveryNoteInput $input, ?Uuid $actorUserId): DeliveryNote
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId): DeliveryNote {
            [$establishment, $customer, $lines] = $this->checked($company, $input, null);
            $note = DeliveryNote::create($company, $establishment, $customer, $input->header, $lines, $this->clock->now());
            $this->totals->checked($note);
            $this->notes->save($note);
            $this->record($company, $note->getId(), self::CREATED, [], $actorUserId);

            return $note;
        });
    }

    /**
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     */
    public function revise(Company $company, Uuid $id, DeliveryNoteInput $input, ?Uuid $actorUserId): DeliveryNote
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId): DeliveryNote {
            // Held until this transaction ends and read as it stands: a draft check on it cannot be overtaken.
            $note = $this->notes->lockedOfIdsInCompany([$id], $company->getId())[0] ?? throw new DeliveryNoteNotFound();
            [$establishment, $customer, $lines] = $this->checked($company, $input, $note);
            $changed = $note->revise($establishment, $customer, $input->header, $lines, $this->clock->now());
            if ([] !== $changed) {
                $this->totals->checked($note);
                $this->notes->save($note);
                $this->record($company, $note->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $note;
        });
    }

    /**
     * What the input names, found in the company and checked; what the note already names is kept even retired.
     *
     * @return array{Establishment, Customer, list<DeliveryNoteLineDetails>}
     */
    private function checked(Company $company, DeliveryNoteInput $input, ?DeliveryNote $current): array
    {
        $customer = $this->customers->ofIdInCompany($input->customerId, $company->getId())
            ?? throw new InvalidDeliveryNote('customerId', 'No customer of this company has this id.');
        if (!$customer->isActive() && !(null !== $current && $current->getCustomer()->getId()->equals($customer->getId()))) {
            throw new InvalidDeliveryNote('customerId', \sprintf('The customer %s is deactivated.', $customer->getNumber()));
        }

        if (null === $input->establishmentId) {
            $establishment = array_find($this->establishments->ofCompany($company->getId()), static fn (Establishment $e): bool => $e->isDefault())
                ?? throw new \LogicException('A company always has a default establishment.');
        } else {
            $establishment = $this->establishments->ofIdInCompany($input->establishmentId, $company->getId())
                ?? throw new InvalidDeliveryNote('establishmentId', 'No establishment of this company has this id.');
        }

        $kept = self::named($current);
        $lines = [];
        foreach ($input->lines as $index => $line) {
            try {
                $lines[] = $this->line($company, $customer, $line, $kept);
            } catch (InvalidDeliveryNote $refused) {
                throw $refused->within("lines[$index]");
            }
        }

        return [$establishment, $customer, $lines];
    }

    /** @param array{products: list<string>, units: list<string>, taxes: list<string>} $kept */
    private function line(Company $company, Customer $customer, DeliveryNoteLineInput $line, array $kept): DeliveryNoteLineDetails
    {
        $product = null;
        if (null !== $line->productId) {
            $product = $this->products->ofIdInCompany($line->productId, $company->getId())
                ?? throw new InvalidDeliveryNote('productId', 'No product of this company has this id.');
            if (!$product->isActive() && !\in_array($product->getId()->toRfc4122(), $kept['products'], true)) {
                throw new InvalidDeliveryNote('productId', \sprintf('The product %s is deactivated.', $product->getReference()));
            }
        }

        $unitId = $line->unitId ?? $product?->getUnit()->getId() ?? throw new InvalidDeliveryNote('unitId', 'A line without a product names its unit.');
        $unit = $this->units->ofIdInCompany($unitId, $company->getId()) ?? throw new InvalidDeliveryNote('unitId', 'No unit of this company has this id.');
        if (!$unit->isActive() && !\in_array($unit->getId()->toRfc4122(), $kept['units'], true)) {
            throw new InvalidDeliveryNote('unitId', \sprintf('The unit %s is retired.', $unit->getCode()));
        }

        $price = $line->unitPriceNet ?? $product?->getDetails()->unitPriceNet ?? throw new InvalidDeliveryNote('unitPriceNet', 'A line without a product states its price.');
        $description = null === $line->description || '' === trim($line->description) ? ($product?->getDetails()->name ?? '') : $line->description;

        return new DeliveryNoteLineDetails($product, $description, $line->quantity, $unit, $price, $this->lineTaxes($company, $customer, $line, $product?->getDefaultTaxComponentIds(), $kept['taxes']));
    }

    /**
     * The taxes a line states, each checked; or, when it states none, its product's defaults its customer's regime
     * charges and the company still has active.
     *
     * @param list<string>|null $productDefaults
     * @param list<string>      $kept
     *
     * @return list<TaxComponent>
     */
    private function lineTaxes(Company $company, Customer $customer, DeliveryNoteLineInput $line, ?array $productDefaults, array $kept): array
    {
        $excluded = $customer->getTaxRegime()->getExcludedFamilies();
        if (null === $line->taxComponentIds) {
            $defaults = array_map(fn (string $id): ?TaxComponent => $this->taxes->ofIdInCompany(Uuid::fromString($id), $company->getId()), $productDefaults ?? []);

            return array_values(array_filter($defaults, static fn (?TaxComponent $tax): bool => null !== $tax && $tax->isActive() && !\in_array($tax->getFamily(), $excluded, true)));
        }

        $taxes = [];
        foreach ($line->taxComponentIds as $taxId) {
            $tax = $this->taxes->ofIdInCompany($taxId, $company->getId())
                ?? throw new InvalidDeliveryNote('taxComponentIds', \sprintf('No tax of this company has the id %s.', $taxId->toRfc4122()));
            if (!$tax->isActive() && !\in_array($taxId->toRfc4122(), $kept, true)) {
                throw new InvalidDeliveryNote('taxComponentIds', \sprintf('The tax %s is retired.', $tax->getCode()));
            }
            if (\in_array($tax->getFamily(), $excluded, true)) {
                throw new InvalidDeliveryNote('taxComponentIds', \sprintf('The %s regime does not charge %s.', $customer->getTaxRegime()->getCode(), $tax->getCode()));
            }
            $taxes[] = $tax;
        }

        return $taxes;
    }

    /**
     * The products, units and taxes a note's lines already name, by id.
     *
     * @return array{products: list<string>, units: list<string>, taxes: list<string>}
     */
    private static function named(?DeliveryNote $note): array
    {
        $named = ['products' => [], 'units' => [], 'taxes' => []];
        foreach ($note?->getLines() ?? [] as $line) {
            $product = $line->getProduct();
            if (null !== $product) {
                $named['products'][] = $product->getId()->toRfc4122();
            }
            $named['units'][] = $line->getUnit()->getId()->toRfc4122();
            foreach ($line->getTaxes() as $tax) {
                $named['taxes'][] = $tax->getTaxComponent()->getId()->toRfc4122();
            }
        }

        return $named;
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $noteId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $noteId, $action, $actorUserId, $changes, $company->getId()));
    }
}

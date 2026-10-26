<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\DeliveryNotes\Application\Invoices\InvoiceLines;
use App\Module\DeliveryNotes\Application\Quotes\QuoteLines;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteSearch;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Settings\Application\ReadSetting;
use App\Shared\Application\Receivers\ChooseReceiver;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Domain\ValueObject\ReceiverRule;
use App\Shared\Domain\ValueObject\Refusal;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's delivery notes while they are drafts. A note goes to one of the company's active customers from one of
 * its establishments; a line delivers one of its active products or states what it is, in an active unit, with active
 * line taxes the customer's regime charges. A customer, product, unit or tax retired since stays with the note that
 * already names it. The lines must total. Audited with the names of the fields a revision changed, never their values.
 */
final readonly class ManageDeliveryNotes implements DeclaresAuditActions
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
        private DeliveryNoteLinePrices $linePrices,
        private ExcludedTaxFamilies $excluded,
        private RecordVersions $versions,
        private QuoteLines $quoteLines,
        private InvoiceLines $invoiceLines,
        private ChooseReceiver $receivers,
        private ReadSetting $settings,
    ) {
    }

    /** @return list<DeliveryNote> */
    public function list(Company $company): array
    {
        return $this->notes->ofCompany($company->getId());
    }

    /**
     * One page of the company's delivery notes, searched, narrowed and sorted by the database.
     *
     * @return Page<DeliveryNote>
     */
    public function search(Company $company, DeliveryNoteSearch $search, PageRequest $page): Page
    {
        return $this->notes->search($company->getId(), $search, $page);
    }

    /**
     * What each status chip of the list would show under the same search (docs/SPEC.md § 7, 2026-09-26).
     *
     * @return array{all: int, statuses: array<string, int>}
     */
    public function statusCounts(Company $company, DeliveryNoteSearch $search): array
    {
        return $this->notes->statusCounts($company->getId(), $search);
    }

    /** @throws DeliveryNoteNotFound */
    public function get(Company $company, Uuid $id): DeliveryNote
    {
        return $this->notes->ofIdInCompany($id, $company->getId()) ?? throw new DeliveryNoteNotFound();
    }

    /**
     * @param array<string, string> $origin what the note was drawn from, as its audit entry says it
     *
     * @throws InvalidDeliveryNote
     */
    public function create(Company $company, DeliveryNoteInput $input, ?Uuid $actorUserId, array $origin = []): DeliveryNote
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId, $origin): DeliveryNote {
            [$establishment, $customer, $lines] = $this->checked($company, $input, null, true);
            $header = $this->received($company, $customer, $input, null, $actorUserId);
            $note = DeliveryNote::create($company, $establishment, $customer, $header, $lines, $this->clock->now());
            $this->totals->checked($note);
            $this->notes->save($note);
            $this->record($company, $note->getId(), self::CREATED, $origin, $actorUserId);

            return $note;
        });
    }

    /**
     * `$version` is the one the editor opened; a save over a later one is refused before anything is changed.
     *
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $id, DeliveryNoteInput $input, ?Uuid $actorUserId, ?int $version = null): DeliveryNote
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId, $version): DeliveryNote {
            // Held until this transaction ends and read as it stands: a draft check on it cannot be overtaken.
            $note = $this->notes->lockedOfIdsInCompany([$id], $company->getId())[0] ?? throw new DeliveryNoteNotFound();
            if (null !== $version) {
                $this->versions->expect($note, $version);
            }
            [$establishment, $customer, $lines] = $this->checked($company, $input, $note, true);
            $header = $this->received($company, $customer, $input, $note, $actorUserId);
            $changed = $note->revise($establishment, $customer, $header, $lines, $this->clock->now());
            if ([] !== $changed) {
                $this->totals->checked($note);
                $this->notes->save($note);
                $this->record($company, $note->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $note;
        });
    }

    /**
     * The figures a new note, or the draft `$id` revised, would have, from what a save would send: checked as the save
     * checks it, worked out by the calculator that saves them, and kept nowhere. Nothing is locked, written or audited.
     *
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     */
    public function preview(Company $company, DeliveryNoteInput $input, ?Uuid $id): DocumentTotals
    {
        $current = null === $id ? null : $this->get($company, $id);
        $current?->assertDraft('changes');
        [$establishment, $customer, $lines] = $this->checked($company, $input, $current, false);
        // Refused as a save would refuse it, without adding the name typed to the customer's contacts.
        $choice = $input->receivedBy;
        $this->received($company, $customer, new DeliveryNoteInput($input->customerId, $input->establishmentId, $input->header, $input->lines, null === $choice ? null : new ReceiverChoice($choice->contactId, $choice->name)), $current, null);

        // What a revision would make of the draft is a new note of the same parts, saved nowhere: a note's figures
        // depend on its lines alone.
        return $this->totals->checked(DeliveryNote::create($company, $establishment, $customer, $input->header, $lines, $this->clock->now()));
    }

    /**
     * What the input names, found in the company and checked; what the note already names is kept even retired, and so
     * is what a quote line it delivers names. With `$locked`, the quotes its lines deliver are held until the
     * transaction ends, so what is left of them cannot be taken twice.
     *
     * @return array{Establishment, Customer, list<DeliveryNoteLineDetails>}
     */
    private function checked(Company $company, DeliveryNoteInput $input, ?DeliveryNote $current, bool $locked): array
    {
        $customer = $this->customers->ofIdInCompany($input->customerId, $company->getId())
            ?? throw new InvalidDeliveryNote('customerId', 'No customer of this company has this id.', 'unknown_choice');
        if (!$customer->isActive() && !(null !== $current && $current->getCustomer()->getId()->equals($customer->getId()))) {
            throw new InvalidDeliveryNote('customerId', $customer->whySwitchedOff(), ...$customer->switchedOffReason());
        }

        if (null === $input->establishmentId) {
            $establishment = array_find($this->establishments->ofCompany($company->getId()), static fn (Establishment $e): bool => $e->isDefault())
                ?? throw new \LogicException('A company always has a default establishment.');
        } else {
            $establishment = $this->establishments->ofIdInCompany($input->establishmentId, $company->getId())
                ?? throw new InvalidDeliveryNote('establishmentId', 'No establishment of this company has this id.', 'unknown_choice');
        }

        [$inputs, $fromInvoices] = $this->invoiceLines->resolved($company, $input->lines, $customer, $establishment, $current, $locked);
        [$inputs, $fromQuotes] = $this->quoteLines->resolved($company, $inputs, $customer, $establishment, $current, $locked);
        $named = self::named($current);
        $kept = [
            'products' => [...$named['products'], ...$fromQuotes['products'], ...$fromInvoices['products']],
            'units' => [...$named['units'], ...$fromQuotes['units'], ...$fromInvoices['units']],
            'taxes' => [...$named['taxes'], ...$fromQuotes['taxes'], ...$fromInvoices['taxes']],
        ];
        $lines = [];
        foreach ($inputs as $index => $line) {
            try {
                $lines[] = $this->line($company, $customer, $line, $kept);
            } catch (InvalidDeliveryNote $refused) {
                throw $refused->within("lines[$index]");
            }
        }

        return [$establishment, $customer, $lines];
    }

    /**
     * The header naming whoever the person chose to receive the goods: as the draft copied them when sent back as read,
     * whatever the contact is called since; else a contact's name copied now, or the name typed, added to the customer's
     * contacts when asked. Where the customer's notes name nobody, naming someone is refused; where they must name
     * someone, it is the delivery that asks.
     *
     * @throws InvalidDeliveryNote
     */
    private function received(Company $company, Customer $customer, DeliveryNoteInput $input, ?DeliveryNote $current, ?Uuid $actorUserId): DeliveryNoteHeader
    {
        $choice = $input->receivedBy;
        $kept = null !== $current && $current->getCustomer()->getId()->equals($customer->getId()) ? $current->getHeader()->receivedBy : null;
        if (null !== $kept && null !== $choice && $choice->names($kept)) {
            return $input->header->withReceiver($kept);
        }
        $receiver = $this->receivers->receiver($company, $customer->getId(), $choice, $actorUserId);
        if ($receiver instanceof Refusal) {
            throw new InvalidDeliveryNote('receivedBy', $receiver->message, $receiver->reason, $receiver->params);
        }
        $rule = DeliveryNoteSettings::receiverRuleFor($this->settings, $customer);
        $refused = ReceiverRule::Off === $rule ? $rule->refusal($receiver) : null;
        if (null !== $refused) {
            throw new InvalidDeliveryNote('receivedBy', $refused->message, $refused->reason, $refused->params);
        }

        return $input->header->withReceiver($receiver);
    }

    /** @param array{products: list<string>, units: list<string>, taxes: list<string>} $kept */
    private function line(Company $company, Customer $customer, DeliveryNoteLineInput $line, array $kept): DeliveryNoteLineDetails
    {
        $product = null;
        if (null !== $line->productId) {
            $product = $this->products->ofIdInCompany($line->productId, $company->getId())
                ?? throw new InvalidDeliveryNote('productId', 'No product of this company has this id.', 'unknown_choice');
            if (!$product->isActive() && !\in_array($product->getId()->toRfc4122(), $kept['products'], true)) {
                throw new InvalidDeliveryNote('productId', $product->whySwitchedOff(), ...$product->switchedOffReason());
            }
        }

        $unitId = $line->unitId ?? $product?->getUnit()->getId() ?? throw new InvalidDeliveryNote('unitId', 'A line without a product names its unit.', 'value_required');
        $unit = $this->units->ofIdInCompany($unitId, $company->getId()) ?? throw new InvalidDeliveryNote('unitId', 'No unit of this company has this id.', 'unknown_choice');
        if (!$unit->isActive() && !\in_array($unit->getId()->toRfc4122(), $kept['units'], true)) {
            throw new InvalidDeliveryNote('unitId', \sprintf('The unit %s is retired.', $unit->getCode()), 'unknown_choice');
        }

        // A product line sent without a price starts where the screen would have started it: the customer's list price.
        $price = $line->unitPriceNet
            ?? (null === $product ? null : $this->linePrices->startingPrice($product, $customer->getId(), $line->quantity))
            ?? throw new InvalidDeliveryNote('unitPriceNet', 'A line without a product states its price.', 'value_required');
        $description = null === $line->description || '' === TypedText::trimmed($line->description) ? ($product?->getDetails()->name ?? '') : $line->description;

        return new DeliveryNoteLineDetails($product, $description, $line->quantity, $unit, $price, $this->lineTaxes($company, $customer, $line, $product?->getDefaultTaxComponentIds(), $kept['taxes']), $line->lotCode, $line->sourceQuoteLineId, $line->sourceInvoiceLineId);
    }

    /**
     * The taxes a line states, each checked; or, when it states none, its product's defaults that both its customer's
     * regime and its company's own charge, and that the company still has active.
     *
     * @param list<string>|null $productDefaults
     * @param list<string>      $kept
     *
     * @return list<TaxComponent>
     */
    private function lineTaxes(Company $company, Customer $customer, DeliveryNoteLineInput $line, ?array $productDefaults, array $kept): array
    {
        $excluded = $this->excluded->of($company, $customer->getTaxRegime());
        if (null === $line->taxComponentIds) {
            $defaults = array_map(fn (string $id): ?TaxComponent => $this->taxes->ofIdInCompany(Uuid::fromString($id), $company->getId()), $productDefaults ?? []);

            return array_values(array_filter($defaults, static fn (?TaxComponent $tax): bool => null !== $tax && $tax->isActive() && !\in_array($tax->getFamily(), $excluded, true)));
        }

        $taxes = [];
        foreach ($line->taxComponentIds as $taxId) {
            $tax = $this->taxes->ofIdInCompany($taxId, $company->getId())
                ?? throw new InvalidDeliveryNote('taxComponentIds', \sprintf('No tax of this company has the id %s.', $taxId->toRfc4122()), 'unknown_tax');
            if (!$tax->isActive() && !\in_array($taxId->toRfc4122(), $kept, true)) {
                throw new InvalidDeliveryNote('taxComponentIds', \sprintf('The tax %s is retired.', $tax->getCode()), 'unknown_tax');
            }
            $leftOutBy = $this->excluded->regimeLeavingOut($company, $customer->getTaxRegime(), $tax->getFamily());
            if (null !== $leftOutBy) {
                throw new InvalidDeliveryNote('taxComponentIds', \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode()), 'regime_excludes_tax', ['tax' => $tax->getCode()]);
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

    public function auditActions(): array
    {
        return [
            self::CREATED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::REVISED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}

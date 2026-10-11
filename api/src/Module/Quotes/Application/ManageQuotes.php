<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Files\Application\AttachmentRefused;
use App\Files\Application\Attachments;
use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Files\Domain\Attachment;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteLineDetails;
use App\Module\Quotes\Domain\QuoteNotDraft;
use App\Module\Quotes\Domain\QuoteRepository;
use App\Module\Quotes\Domain\QuoteSearch;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's quotes while they are drafts, and the files attached to any of them, such as the signed scan that comes
 * back with an accepted one. A quote goes to one of the company's active customers from one of its establishments; a
 * line offers one of its active products or states what it is, in an active unit, with active line taxes the
 * customer's regime charges. A customer, product, unit or tax retired since stays with the quote that already names it.
 * The lines must total. Audited with the names of the fields a revision changed, never their values.
 */
final readonly class ManageQuotes
{
    public const string ENTITY_TYPE = 'quote';
    public const string CREATED = 'quote.created';
    public const string REVISED = 'quote.revised';
    public const string ATTACHMENT_ADDED = 'quote.attachment_added';
    public const string ATTACHMENT_REMOVED = 'quote.attachment_removed';
    public const string ATTACHMENT_RESTORED = 'quote.attachment_restored';

    public function __construct(
        private QuoteRepository $quotes,
        private Transactions $transactions,
        private CustomerRepository $customers,
        private ProductRepository $products,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private EstablishmentRepository $establishments,
        private QuoteTotals $totals,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private QuoteLinePrices $linePrices,
        private ExcludedTaxFamilies $excluded,
        private Attachments $attachments,
    ) {
    }

    /**
     * One page of the company's quotes, searched, narrowed and sorted by the database.
     *
     * @return Page<Quote>
     */
    public function search(Company $company, QuoteSearch $search, PageRequest $page): Page
    {
        return $this->quotes->search($company->getId(), $search, $page);
    }

    /**
     * What each status chip of the list would show under the same search.
     *
     * @return array{all: int, statuses: array<string, int>}
     */
    public function statusCounts(Company $company, QuoteSearch $search): array
    {
        return $this->quotes->statusCounts($company->getId(), $search);
    }

    /** @throws QuoteNotFound */
    public function get(Company $company, Uuid $id): Quote
    {
        return $this->quotes->ofIdInCompany($id, $company->getId()) ?? throw new QuoteNotFound();
    }

    /** @throws InvalidQuote */
    public function create(Company $company, QuoteInput $input, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId): Quote {
            [$establishment, $customer, $lines] = $this->checked($company, $input, null);
            $quote = Quote::create($company, $establishment, $customer, $input->header, $lines, $this->clock->now());
            $this->totals->checked($quote);
            $this->quotes->save($quote);
            $this->record($company, $quote->getId(), self::CREATED, [], $actorUserId);

            return $quote;
        });
    }

    /**
     * The figures a new quote, or the draft `$id` revised, would have, from what a save would send: checked as the save
     * checks it, worked out by the calculator that saves them, and kept nowhere. Nothing is locked, written or audited.
     *
     * @throws QuoteNotFound
     * @throws QuoteNotDraft
     * @throws InvalidQuote
     */
    public function preview(Company $company, QuoteInput $input, ?Uuid $id): DocumentTotals
    {
        $current = null === $id ? null : $this->get($company, $id);
        $current?->assertDraft('changes');
        [$establishment, $customer, $lines] = $this->checked($company, $input, $current);

        // A quote names no other document, so what it would be is a new one of the same parts, saved nowhere.
        return $this->totals->checked(Quote::create($company, $establishment, $customer, $input->header, $lines, $this->clock->now()));
    }

    /**
     * @throws QuoteNotFound
     * @throws QuoteNotDraft
     * @throws InvalidQuote
     */
    public function revise(Company $company, Uuid $id, QuoteInput $input, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId): Quote {
            // Held until this transaction ends and read as it stands: a draft check on it cannot be overtaken.
            $quote = $this->quotes->lockedOfIdInCompany($id, $company->getId()) ?? throw new QuoteNotFound();
            [$establishment, $customer, $lines] = $this->checked($company, $input, $quote);
            $changed = $quote->revise($establishment, $customer, $input->header, $lines, $this->clock->now());
            if ([] !== $changed) {
                $this->totals->checked($quote);
                $this->quotes->save($quote);
                $this->record($company, $quote->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $quote;
        });
    }

    /**
     * @return list<Attachment>
     *
     * @throws QuoteNotFound
     */
    public function attachments(Company $company, Uuid $id): array
    {
        return $this->attachments->of($company, self::ENTITY_TYPE, $this->get($company, $id)->getId());
    }

    /**
     * Each quote's attachment count, read once for a whole list page.
     *
     * @param list<Quote> $quotes
     *
     * @return array<string, int> by quote id (RFC 4122)
     */
    public function attachmentCounts(Company $company, array $quotes): array
    {
        return $this->attachments->countsOf($company, self::ENTITY_TYPE, array_map(static fn (Quote $quote): Uuid => $quote->getId(), $quotes));
    }

    /**
     * A file attached to a quote at any stage: the signed copy that comes back with an accepted one, a plan, a photo.
     *
     * @throws QuoteNotFound
     * @throws AttachmentRefused
     */
    public function attach(Company $company, Uuid $id, string $name, string $contents, ?Uuid $actorUserId): Attachment
    {
        return $this->transactions->run(function () use ($company, $id, $name, $contents, $actorUserId): Attachment {
            $quote = $this->get($company, $id);
            $attachment = $this->attachments->attach($company, self::ENTITY_TYPE, $quote->getId(), $name, $contents, $actorUserId);
            $this->record($company, $quote->getId(), self::ATTACHMENT_ADDED, ['attachmentId' => $attachment->getId()->toRfc4122()], $actorUserId);

            return $attachment;
        });
    }

    /**
     * @return array{Attachment, string} the attachment and its bytes
     *
     * @throws QuoteNotFound
     * @throws QuoteAttachmentNotFound
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function attachmentContents(Company $company, Uuid $id, Uuid $attachmentId): array
    {
        $attachment = $this->attachment($company, $id, $attachmentId);

        return [$attachment, $this->attachments->contents($attachment)];
    }

    /**
     * A file attached by mistake comes off whatever the quote's status: what it says stays the quote's own.
     *
     * @throws QuoteNotFound
     * @throws QuoteAttachmentNotFound
     */
    public function detach(Company $company, Uuid $id, Uuid $attachmentId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $id, $attachmentId, $actorUserId): void {
            $this->attachments->detach($this->attachment($company, $id, $attachmentId));
            $this->record($company, $id, self::ATTACHMENT_REMOVED, ['attachmentId' => $attachmentId->toRfc4122()], $actorUserId);
        });
    }

    /**
     * « Annuler » after a file came off: it is put back where it was, whatever the quote's status, as it came off.
     *
     * @throws QuoteNotFound
     * @throws QuoteAttachmentNotFound
     * @throws AttachmentRefused       when the quote already holds as many files as it may
     */
    public function restore(Company $company, Uuid $id, Uuid $attachmentId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $id, $attachmentId, $actorUserId): void {
            $quote = $this->get($company, $id);
            $this->attachments->restore($company, self::ENTITY_TYPE, $quote->getId(), $attachmentId) ?? throw new QuoteAttachmentNotFound();
            $this->record($company, $id, self::ATTACHMENT_RESTORED, ['attachmentId' => $attachmentId->toRfc4122()], $actorUserId);
        });
    }

    private function attachment(Company $company, Uuid $id, Uuid $attachmentId): Attachment
    {
        return $this->attachments->find($company, self::ENTITY_TYPE, $this->get($company, $id)->getId(), $attachmentId) ?? throw new QuoteAttachmentNotFound();
    }

    /**
     * What the input names, found in the company and checked; what the quote already names is kept even retired.
     *
     * @return array{Establishment, Customer, list<QuoteLineDetails>}
     */
    private function checked(Company $company, QuoteInput $input, ?Quote $current): array
    {
        $customer = $this->customers->ofIdInCompany($input->customerId, $company->getId())
            ?? throw new InvalidQuote('customerId', 'No customer of this company has this id.');
        if (!$customer->isActive() && !(null !== $current && $current->getCustomer()->getId()->equals($customer->getId()))) {
            throw new InvalidQuote('customerId', \sprintf('The customer %s is deactivated.', $customer->getNumber()));
        }

        if (null === $input->establishmentId) {
            $establishment = array_find($this->establishments->ofCompany($company->getId()), static fn (Establishment $e): bool => $e->isDefault())
                ?? throw new \LogicException('A company always has a default establishment.');
        } else {
            $establishment = $this->establishments->ofIdInCompany($input->establishmentId, $company->getId())
                ?? throw new InvalidQuote('establishmentId', 'No establishment of this company has this id.');
        }

        $kept = self::named($current);
        $lines = [];
        foreach ($input->lines as $index => $line) {
            try {
                $lines[] = $this->line($company, $customer, $line, $kept);
            } catch (InvalidQuote $refused) {
                throw $refused->within("lines[$index]");
            }
        }

        return [$establishment, $customer, $lines];
    }

    /** @param array{products: list<string>, units: list<string>, taxes: list<string>} $kept */
    private function line(Company $company, Customer $customer, QuoteLineInput $line, array $kept): QuoteLineDetails
    {
        $product = null;
        if (null !== $line->productId) {
            $product = $this->products->ofIdInCompany($line->productId, $company->getId())
                ?? throw new InvalidQuote('productId', 'No product of this company has this id.');
            if (!$product->isActive() && !\in_array($product->getId()->toRfc4122(), $kept['products'], true)) {
                throw new InvalidQuote('productId', \sprintf('The product %s is deactivated.', $product->getReference()));
            }
        }

        $unitId = $line->unitId ?? $product?->getUnit()->getId() ?? throw new InvalidQuote('unitId', 'A line without a product names its unit.');
        $unit = $this->units->ofIdInCompany($unitId, $company->getId()) ?? throw new InvalidQuote('unitId', 'No unit of this company has this id.');
        if (!$unit->isActive() && !\in_array($unit->getId()->toRfc4122(), $kept['units'], true)) {
            throw new InvalidQuote('unitId', \sprintf('The unit %s is retired.', $unit->getCode()));
        }

        // A product line sent without a price starts where the screen would have started it: the customer's list price.
        $price = $line->unitPriceNet
            ?? (null === $product ? null : $this->linePrices->startingPrice($product, $customer->getId(), $line->quantity))
            ?? throw new InvalidQuote('unitPriceNet', 'A line without a product states its price.');
        $description = null === $line->description || '' === trim($line->description) ? ($product?->getDetails()->name ?? '') : $line->description;

        return new QuoteLineDetails($product, $description, $line->quantity, $unit, $price, $line->discountRate, $this->lineTaxes($company, $customer, $line, $product?->getDefaultTaxComponentIds(), $kept['taxes']), $line->discountAmount);
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
    private function lineTaxes(Company $company, Customer $customer, QuoteLineInput $line, ?array $productDefaults, array $kept): array
    {
        $excluded = $this->excluded->of($company, $customer->getTaxRegime());
        if (null === $line->taxComponentIds) {
            $defaults = array_map(fn (string $id): ?TaxComponent => $this->taxes->ofIdInCompany(Uuid::fromString($id), $company->getId()), $productDefaults ?? []);

            return array_values(array_filter($defaults, static fn (?TaxComponent $tax): bool => null !== $tax && $tax->isActive() && !\in_array($tax->getFamily(), $excluded, true)));
        }

        $taxes = [];
        foreach ($line->taxComponentIds as $taxId) {
            $tax = $this->taxes->ofIdInCompany($taxId, $company->getId())
                ?? throw new InvalidQuote('taxComponentIds', \sprintf('No tax of this company has the id %s.', $taxId->toRfc4122()));
            if (!$tax->isActive() && !\in_array($taxId->toRfc4122(), $kept, true)) {
                throw new InvalidQuote('taxComponentIds', \sprintf('The tax %s is retired.', $tax->getCode()));
            }
            $leftOutBy = $this->excluded->regimeLeavingOut($company, $customer->getTaxRegime(), $tax->getFamily());
            if (null !== $leftOutBy) {
                throw new InvalidQuote('taxComponentIds', \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode()));
            }
            $taxes[] = $tax;
        }

        return $taxes;
    }

    /**
     * The products, units and taxes a quote's lines already name, by id.
     *
     * @return array{products: list<string>, units: list<string>, taxes: list<string>}
     */
    private static function named(?Quote $quote): array
    {
        $named = ['products' => [], 'units' => [], 'taxes' => []];
        foreach ($quote?->getLines() ?? [] as $line) {
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
    private function record(Company $company, Uuid $quoteId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $quoteId, $action, $actorUserId, $changes, $company->getId()));
    }
}

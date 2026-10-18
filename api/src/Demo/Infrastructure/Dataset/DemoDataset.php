<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Dataset;

use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Application\ManageContacts;
use App\Module\Customers\Application\ManageCustomerGroups;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\ValueObject\ContactDetails;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\DeliveryNotes\Application\DeliveryNoteInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteLineInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteHeader;
use App\Module\Expenses\Application\ExpenseInput;
use App\Module\Expenses\Application\ManageExpenseCategories;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\ValueObject\ExpenseDetails;
use App\Module\Inventory\Application\KeepProductHomes;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\ValueObject\NamedLot;
use App\Module\Invoices\Application\Credit\ManageCustomerCredit;
use App\Module\Invoices\Application\InvoiceInput;
use App\Module\Invoices\Application\InvoiceLineInput;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Application\Payment\ManagePayments;
use App\Module\Invoices\Domain\ValueObject\InvoiceHeader;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Application\Category\ManageProductCategories;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductInput;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteInput;
use App\Module\Quotes\Application\QuoteInvoices;
use App\Module\Quotes\Application\QuoteLineInput;
use App\Module\Quotes\Application\QuoteWorkflow;
use App\Module\Quotes\Domain\ValueObject\QuoteHeader;
use App\Module\RecurringExpenses\Application\ManageRecurringExpenses;
use App\Module\RecurringExpenses\Application\RunRecurringExpenses;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\Model\Schedule;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Shared\Domain\ValueObject\ScheduleAnchor;
use App\Shared\Domain\ValueObject\ScheduleUnit;
use App\Tenancy\Application\Company\ReviseCompanyProfile;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * The demo dataset (docs/SPEC.md § 7, 2026-09-19): one company of `DemoCatalogue`, written through the application's
 * use cases, never around them, so it carries the numbers, totals, stock movements and audit rows the product itself
 * would have written. Five months of activity end a few days before it is written: invoices paid, part paid, overdue,
 * credited, drafted and cancelled; delivery notes in every state; expenses drafted, recorded and paid.
 *
 * The history is written under a moved clock, the process's own (`Clock::set`), put back however the writing ends: nothing
 * else may run in the process meanwhile, which holds for a fixtures load and for one message of the worker. Who owns the
 * company is the caller's to say: the fixtures give it to the seeded operator, a demo sandbox to an account of its own.
 */
final readonly class DemoDataset
{
    /** The first day of a company's history, in days from today. */
    private const int FIRST_DAY = -170;
    private const int INVOICES = 28;
    private const array STREETS = ['rue de la République', 'avenue de la Liberté', 'rue des Jardins', 'place du Marché'];
    /**
     * Each demo customer's town and its postal code, so that every address is whole: a document's Factur-X names the
     * buyer's postal code and country (EN 16931 BG-8), and an address without them is refused.
     */
    private const array POSTAL_CODES = [
        'Tunis' => '1000', 'Sfax' => '3000', 'Sousse' => '4000', 'Houmt Souk' => '4180', 'Kairouan' => '3100',
        'Bizerte' => '7000', 'Nabeul' => '8000', 'Gabès' => '6000', 'Tozeur' => '2200', 'Monastir' => '5000',
        'Hammamet' => '8050', 'Tabarka' => '8110', 'Mahdia' => '5100', 'Béja' => '9000', 'Zaghouan' => '1100',
        'Kasserine' => '1200', 'Jendouba' => '8100', 'Siliana' => '6100', 'Ariana' => '2080', 'Ben Arous' => '2013',
        'Manouba' => '2010', 'Kébili' => '4200', 'Médenine' => '4100', 'La Marsa' => '2070', 'Hamburg' => '20095',
        'Lyon' => '69002', 'Annecy' => '74000', 'Grenoble' => '38000', 'Villeurbanne' => '69100', 'Bron' => '69500',
        'Chambéry' => '73000', 'Paris' => '75003', 'Mâcon' => '71000', 'Valence' => '26000', 'Beaune' => '21200',
        'Vienne' => '38200', 'Aix-les-Bains' => '73100', 'Écully' => '69130', 'Givors' => '69700', 'Cluny' => '71250',
        'Oyonnax' => '01100', 'Caluire' => '69300', 'Berlin' => '10115', 'Genève' => '1201',
    ];
    private const array METHODS = [PaymentMethod::Transfer, PaymentMethod::Check, PaymentMethod::Cash, PaymentMethod::Card];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyRepository $companies,
        private ReviseCompanyProfile $profiles,
        private ChangeSettings $settings,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private ManageEstablishments $establishments,
        private ManageCustomerGroups $customerGroups,
        private ManageCustomers $customers,
        private ManageContacts $contacts,
        private ManageProductCategories $productCategories,
        private ManageProducts $products,
        private DemoDepot $depot,
        private KeepProductHomes $homes,
        private KeepStock $stock,
        private ManageExpenseCategories $expenseCategories,
        private ManageVendors $vendors,
        private ManageExpenses $expenses,
        private ManageRecurringExpenses $recurringExpenses,
        private RunRecurringExpenses $runRecurringExpenses,
        private ManageDeliveryNotes $deliveryNotes,
        private DeliveryNoteWorkflow $deliveryNoteWorkflow,
        private InvoiceDeliveryNotes $invoiceDeliveryNotes,
        private ManageInvoices $invoices,
        private InvoiceWorkflow $invoiceWorkflow,
        private ManagePayments $payments,
        private ManageCustomerCredit $credit,
        private ManageQuotes $quotes,
        private QuoteWorkflow $quoteWorkflow,
        private QuoteInvoices $quoteInvoices,
    ) {
    }

    /**
     * Writes `$demo` and answers the company it was written into.
     *
     * @param \Closure(DemoCompany): OpenedCompany $open makes the company, active, provisioned and owned, on the history's
     *                                                   first day, and says who acts in it
     */
    public function write(DemoCompany $demo, \Closure $open): Uuid
    {
        // Every flush compares every entity the manager holds, so a load that kept them all slowed down with each row
        // it wrote (four minutes for the second company). Written rows are only read again by id: letting go of them
        // after each row or step is Doctrine's own batch-processing practice.
        $settle = function (): void {
            $this->entityManager->clear();
        };
        $clock = Clock::get();
        try {
            $timeline = new Timeline(new \DateTimeImmutable('today', new \DateTimeZone($demo->timezone)), $settle);
            Clock::set(new MockClock($timeline->day(self::FIRST_DAY)->setTime(9, 0)));
            $opened = $open($demo);
            $companyId = $opened->companyId;
            $actor = $opened->actorId;
            $company = fn (): Company => $this->companies->ofId($companyId) ?? throw new \LogicException("$demo->name vanished.");
            $unit = fn (string $code): Uuid => ($this->units->ofCodeInCompany($code, $companyId) ?? throw new \LogicException("The $demo->country preset has no unit $code."))->getId();
            $tax = fn (string $code): Uuid => ($this->taxes->ofCodeInCompany($code, $companyId) ?? throw new \LogicException("The $demo->country preset has no tax $code."))->getId();

            $this->profiles->handle($company(), $demo->profile, $actor);
            $this->settings->change(new SettingContext($company()), 'article.stock_tracking', SettingLevel::Company, true, $actor);
            foreach ($demo->mentionData as $key => $value) {
                $this->settings->change(new SettingContext($company()), $key, SettingLevel::Company, $value, $actor);
            }

            $customerIds = $this->writeCustomers($demo, $company, $tax, $actor, $settle);
            $vendors = $this->writeVendors($demo, $company, $actor);
            [$sellable, $goods, $tracked] = $this->writeProducts($demo, $company, $unit, $tax, $vendors, $actor, $settle);

            $this->planExpenses($demo, $timeline, $company, $vendors, $tax, $actor);
            $this->planRecurringExpense($demo, $timeline, $company, $vendors[0], $tax, $actor);
            $this->planDeliveryNotes($timeline, $company, $customerIds, $goods, $tracked, $actor);
            $this->planQuotes($timeline, $company, $customerIds, $sellable, $actor);
            // A tracked article leaves through a delivery note, which names its lot or serial number as a shop hands it
            // over; an invoice naming none would sell the pieces the notes already took, and move nothing.
            $untracked = array_values(array_filter($sellable, static fn (Uuid $id): bool => [] === array_filter($tracked, static fn (Uuid $each): bool => $each->equals($id))));
            $this->planInvoices($demo, $timeline, $company, $customerIds, $untracked, $actor);
            $timeline->run();

            return $companyId;
        } finally {
            Clock::set($clock);
        }
    }

    /**
     * @param \Closure(): Company    $company
     * @param \Closure(string): Uuid $tax
     * @param \Closure(): void       $settle
     *
     * @return list<Uuid> the active customers, in catalogue order
     */
    private function writeCustomers(DemoCompany $demo, \Closure $company, \Closure $tax, Uuid $actor, \Closure $settle): array
    {
        $slugger = new AsciiSlugger();
        $groupIds = [];
        foreach ($demo->customerGroups as $name) {
            $groupIds[] = $this->customerGroups->create($company(), $name, null, $actor)->getId();
        }

        $active = [];
        foreach ($demo->customers as $n => $row) {
            $slug = $slugger->slug($row->name)->lower()->toString();
            $profile = new CustomerProfile(
                $row->individual ? CustomerKind::Individual : CustomerKind::Company,
                $row->name,
                identifiers: $row->identifiers,
                email: $row->individual ? "$slug@courriel.example" : "contact@$slug.example",
                phone: $this->phone($demo->country, $n),
                billingAddress: new PostalAddress(\sprintf('%d, %s', 3 + 4 * $n, self::STREETS[$n % \count(self::STREETS)]), null, self::POSTAL_CODES[$row->city] ?? throw new \LogicException("No postal code is given for $row->city."), $row->city, $row->country ?? $demo->country),
                defaultDiscountRate: 0 === $row->group ? '5' : null,
            );
            $withholds = $row->withheld && null !== $demo->withholdingCode;
            $customer = $this->customers->create($company(), new CustomerInput(
                \sprintf('C-%04d', $n + 1),
                $profile,
                null === $row->group ? null : $groupIds[$row->group],
                $row->regime,
                $withholds ? [$tax((string) $demo->withholdingCode)] : [],
                !$row->inactive,
            ), $actor);
            if (!$row->inactive) {
                $active[] = $customer->getId();
            }
            if (isset($demo->contacts[$n])) {
                [$first, $last, $role] = $demo->contacts[$n];
                $email = $slugger->slug("$first.$last")->lower()->toString()."@$slug.example";
                $this->contacts->add($company(), $customer->getId(), new ContactDetails($first, $last, $email, $this->phone($demo->country, 100 + $n), $role), true, $actor);
            }
            $settle();
        }

        return $active;
    }

    /**
     * @param \Closure(): Company    $company
     * @param \Closure(string): Uuid $unit
     * @param \Closure(string): Uuid $tax
     * @param list<array{id: Uuid}>  $vendors
     * @param \Closure(): void       $settle
     *
     * @return array{list<Uuid>, list<Uuid>, list<Uuid>} the active products, then the active goods among them that
     *                                                   track nothing, then those tracked by lot or serial number
     */
    private function writeProducts(DemoCompany $demo, \Closure $company, \Closure $unit, \Closure $tax, array $vendors, Uuid $actor, \Closure $settle): array
    {
        $categoryIds = [];
        foreach ($demo->productCategories as $name => $parent) {
            $categoryIds[$name] = $this->productCategories->create($company(), $name, null === $parent ? null : $categoryIds[$parent], $actor)->getId();
        }

        $establishment = null;
        foreach ($this->establishments->list($company()) as $each) {
            $establishment = $each->isDefault() ? $each : $establishment;
        }
        $establishment ??= throw new \LogicException("$demo->name was provisioned without a default establishment.");
        // Each family of goods has its rack on the drawn depot, the rack is its goods' home, and they are received
        // there: deliveries leave from a product's home, and the stock map has goods to show where they lie.
        $families = array_values(array_unique(array_map(
            static fn (array $row): string => $row['category'],
            array_values(array_filter($demo->products, static fn (array $row): bool => !isset($row['service']) && !isset($row['inactive']))),
        )));
        $racks = $this->depot->draw($company(), $establishment->getId(), $families, $actor);
        $settle();

        $sellable = [];
        $goods = [];
        $tracked = [];
        foreach ($demo->products as $n => $row) {
            $service = isset($row['service']);
            $tracking = ProductTracking::from($row['tracking'] ?? 'none');
            $product = $this->products->create($company(), new ProductInput(
                $row['ref'],
                new ProductDetails($row['name'], null, $service ? ProductKind::Service : ProductKind::Goods, $row['price'], $service ? null : bcmul($row['price'], '0.6', $demo->scale), $row['group'] ?? null),
                $unit($row['unit']),
                $categoryIds[$row['category']],
                array_map($tax, $row['taxes']),
                !isset($row['inactive']),
                barcodes: $service ? [] : self::codes($n, $row['ref'], $vendors[$n % \count($vendors)]['id']),
                tracking: $tracking,
            ), $actor);
            if (!isset($row['inactive'])) {
                $sellable[] = $product->getId();
            }
            $shelf = isset($row['inactive']) || $service ? null : $racks[$row['category']] ?? null;
            if (null !== $shelf) {
                $this->homes->set($company(), $product->getId(), $shelf, $actor);
            }
            if (null !== $shelf && ProductTracking::None === $tracking) {
                $goods[] = $product->getId();
                $this->stock->receive($company(), $product->getId(), $shelf, (string) (40 + (37 * $n) % 160), $actor);
            } elseif (null !== $shelf) {
                $tracked[] = $product->getId();
                $this->receiveTracked($company(), $product->getId(), $tracking, $shelf, $row['ref'], $actor);
            }
            $settle();
        }

        return [$sellable, $goods, $tracked];
    }

    /**
     * A tracked article's opening stock: two lots of a lot-tracked one, the first used by three weeks from the start
     * of the story, so it has expired by its end and the stock screen shows one of each; five serial numbers of a
     * serial-tracked one, one piece each.
     */
    private function receiveTracked(Company $company, Uuid $product, ProductTracking $tracking, Uuid $shelf, string $reference, Uuid $actor): void
    {
        $start = Clock::get()->now();
        if (ProductTracking::Serial === $tracking) {
            foreach (range(1, 5) as $piece) {
                $this->stock->receive($company, $product, $shelf, '1', $actor, new NamedLot(\sprintf('SN-%s-%04d', $reference, $piece)));
            }

            return;
        }
        $this->stock->receive($company, $product, $shelf, '12', $actor, new NamedLot($reference.'-'.$start->format('ym').'A', $start->modify('+21 days')));
        $this->stock->receive($company, $product, $shelf, '30', $actor, new NamedLot($reference.'-'.$start->format('ym').'B', $start->modify('+300 days')));
    }

    /**
     * What a scanner finds a demo article by (docs/SPEC.md § 7, 2026-09-22 11:05): a unit EAN-13 under Tunisia's GS1
     * prefix 619 for every article, a carton of twelve as a GTIN-14 on every other one, and the supplier's own code on
     * every third, so the product sheet and a scan show each role. Invented numbers with real check digits.
     *
     * @return list<BarcodeInput>
     */
    private static function codes(int $n, string $reference, Uuid $supplier): array
    {
        $unit = self::withCheckDigit(\sprintf('619000%06d', $n + 1));
        $codes = [new BarcodeInput('unit', $unit, 1)];
        if (0 === $n % 2) {
            $codes[] = new BarcodeInput('pack', self::withCheckDigit('1'.substr($unit, 0, 12)), 12);
        }
        if (0 === $n % 3) {
            $codes[] = new BarcodeInput('supplier', 'F-'.$reference, 1, $supplier);
        }

        return $codes;
    }

    /** The GS1 check digit: weights 3 and 1 alternating from the digit next to it. */
    private static function withCheckDigit(string $body): string
    {
        $sum = 0;
        foreach (array_reverse(str_split($body)) as $place => $digit) {
            $sum += (int) $digit * (0 === $place % 2 ? 3 : 1);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    /**
     * @param \Closure(): Company $company
     *
     * @return list<array{id: Uuid, category: Uuid, amount: numeric-string, untaxed: bool, name: string}>
     */
    private function writeVendors(DemoCompany $demo, \Closure $company, Uuid $actor): array
    {
        $slugger = new AsciiSlugger();
        $categoryIds = [];
        foreach ($demo->expenseCategories as $name => $parent) {
            $categoryIds[$name] = $this->expenseCategories->create($company(), $name, null === $parent ? null : $categoryIds[$parent], true, $actor)->getId();
        }

        $vendors = [];
        foreach ($demo->vendors as $n => $row) {
            $slug = $slugger->slug($row['name'])->lower()->toString();
            $vendor = $this->vendors->create($company(), new VendorInput(\sprintf('F-%03d', $n + 1), new VendorProfile(
                $row['name'],
                email: "factures@$slug.example",
                phone: $this->phone($demo->country, 200 + $n),
                address: new PostalAddress(null, null, null, $row['city']),
                paymentTermsDays: 30,
                defaultExpenseCategoryId: $categoryIds[$row['category']],
            ), true), $actor);
            $vendors[] = ['id' => $vendor->getId(), 'category' => $categoryIds[$row['category']], 'amount' => $row['amount'], 'untaxed' => isset($row['untaxed']), 'name' => $row['name']];
        }

        return $vendors;
    }

    /**
     * Sixteen expenses, one every nine days: a third left as drafts, a third recorded, a third recorded and paid. Every
     * fourth of a taxed vendor is a bill at several rates, as the company's preset prints one.
     *
     * @param \Closure(): Company                                                                        $company
     * @param list<array{id: Uuid, category: Uuid, amount: numeric-string, untaxed: bool, name: string}> $vendors
     * @param \Closure(string): Uuid                                                                     $tax
     */
    private function planExpenses(DemoCompany $demo, Timeline $timeline, \Closure $company, array $vendors, \Closure $tax, Uuid $actor): void
    {
        for ($e = 0; $e < 16; ++$e) {
            $vendor = $vendors[$e % \count($vendors)];
            $day = self::FIRST_DAY + 10 + 9 * $e;
            $timeline->at($day, function () use ($demo, $timeline, $company, $vendor, $tax, $actor, $e, $day): void {
                $date = $timeline->day($day);
                $amount = bcmul($vendor['amount'], ['1', '1.1', '0.95'][$e % 3], $demo->scale);
                $mixed = null !== $demo->mixedBill && !$vendor['untaxed'] && 1 === $e % 4 ? $demo->mixedBill : null;
                $expense = $this->expenses->create($company(), new ExpenseInput(
                    new ExpenseDetails($date, \sprintf('%s, %s', $vendor['name'], $date->format('m/Y')), \sprintf('F-%s-%04d', $date->format('Y'), 310 + $e)),
                    null === $mixed
                        ? ExpenseInput::oneLine($amount, $vendor['untaxed'] ? null : $tax($demo->vatCode))
                        : array_map(static fn (array $line): array => ['amountNet' => bcmul($amount, $line['share'], $demo->scale), 'taxComponentIds' => array_map($tax, $line['taxes'])], $mixed['lines']),
                    $vendor['id'],
                    $vendor['category'],
                    null === $mixed ? [] : array_map($tax, $mixed['charges']),
                ), $actor);
                if (0 !== $e % 3) {
                    $this->expenses->recordInBooks($company(), $expense->getId(), $actor);
                }
                if (2 === $e % 3) {
                    $this->expenses->pay($company(), $expense->getId(), PaymentMethod::Transfer, $date, $actor);
                }
            });
        }
    }

    /**
     * A monthly bill whose amount varies, made recurring forty days ago on the day it came: the worker's pass today drafts
     * the occurrences due since, each flagged for its amount to be checked against the bill.
     *
     * @param \Closure(): Company                                                                  $company
     * @param array{id: Uuid, category: Uuid, amount: numeric-string, untaxed: bool, name: string} $vendor
     * @param \Closure(string): Uuid                                                               $tax
     */
    private function planRecurringExpense(DemoCompany $demo, Timeline $timeline, \Closure $company, array $vendor, \Closure $tax, Uuid $actor): void
    {
        $first = -40;
        $timeline->at($first, function () use ($demo, $timeline, $company, $vendor, $tax, $actor, $first): void {
            $date = $timeline->day($first);
            $model = $this->expenses->create($company(), new ExpenseInput(
                new ExpenseDetails($date, $vendor['name'], \sprintf('F-%s-%04d', $date->format('Y'), 400)),
                ExpenseInput::oneLine($vendor['amount'], $vendor['untaxed'] ? null : $tax($demo->vatCode)),
                $vendor['id'],
                $vendor['category'],
            ), $actor);
            $this->expenses->recordInBooks($company(), $model->getId(), $actor);
            $schedule = new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Date, (int) $date->format('j'));
            $this->recurringExpenses->create($company(), $model->getId(), $schedule, $date, null, true, $actor);
        });
        $timeline->at(0, function () use ($company): void {
            $this->runRecurringExpenses->handle($company());
        });
    }

    /**
     * Six delivery notes, one in each state: invoiced, delivered twice, validated, cancelled and a draft.
     *
     * @param \Closure(): Company $company
     * @param list<Uuid>          $customerIds
     * @param list<Uuid>          $goods
     * @param list<Uuid>          $tracked     goods tracked by lot or serial number: a few notes carry two of one, which
     *                                         leave from its lots, the first to expire first
     */
    private function planDeliveryNotes(Timeline $timeline, \Closure $company, array $customerIds, array $goods, array $tracked, Uuid $actor): void
    {
        /** @var list<array{customer: int, day: int, validate: bool, deliver?: int, cancel?: int, invoice?: int, both?: true}> $plan */
        $plan = [
            ['customer' => 0, 'day' => -120, 'validate' => true, 'deliver' => -118, 'invoice' => -110],
            ['customer' => 7, 'day' => -80, 'validate' => true, 'deliver' => -78],
            ['customer' => 3, 'day' => -60, 'validate' => true, 'cancel' => -58],
            ['customer' => 9, 'day' => -40, 'validate' => true],
            ['customer' => 12, 'day' => -25, 'validate' => true, 'deliver' => -24],
            ['customer' => 2, 'day' => -8, 'validate' => false],
            // Numbered and delivered in the one step « Valider et livrer » takes, as the goods leave at once.
            ['customer' => 5, 'day' => -15, 'validate' => false, 'both' => true],
        ];
        /** @var array<int, Uuid> $ids */
        $ids = [];
        foreach ($plan as $n => $note) {
            $customerId = $customerIds[$note['customer']];
            $lines = [
                new DeliveryNoteLineInput($goods[(3 * $n) % \count($goods)], null, (string) (2 + $n)),
                new DeliveryNoteLineInput($goods[(3 * $n + 5) % \count($goods)], null, (string) (1 + $n % 3)),
            ];
            if ([] !== $tracked && \in_array($n, [0, 3, 4], true)) {
                $lines[] = new DeliveryNoteLineInput($tracked[$n % \count($tracked)], null, '2');
            }
            $timeline->at($note['day'], function () use (&$ids, $n, $company, $customerId, $lines, $note, $actor): void {
                $ids[$n] = $this->deliveryNotes->create($company(), new DeliveryNoteInput($customerId, null, new DeliveryNoteHeader(), $lines), $actor)->getId();
                if ($note['validate']) {
                    $this->deliveryNoteWorkflow->validate($company(), $ids[$n], $actor);
                }
                if (isset($note['both'])) {
                    $this->deliveryNoteWorkflow->validateAndDeliver($company(), $ids[$n], $actor);
                }
            });
            if (isset($note['deliver'])) {
                $timeline->at($note['deliver'], function () use (&$ids, $n, $company, $actor): void {
                    $this->deliveryNoteWorkflow->deliver($company(), $ids[$n], null, $actor);
                });
            }
            if (isset($note['cancel'])) {
                $timeline->at($note['cancel'], function () use (&$ids, $n, $company, $actor): void {
                    $this->deliveryNoteWorkflow->cancel($company(), $ids[$n], $actor);
                });
            }
            if (isset($note['invoice'])) {
                $timeline->at($note['invoice'], function () use (&$ids, $n, $company, $actor): void {
                    $invoiceId = $this->invoiceDeliveryNotes->draftInvoice($company(), [$ids[$n]], $actor);
                    $this->invoiceWorkflow->issue($company(), $invoiceId, $actor);
                });
            }
        }
    }

    /**
     * Seven quotes, one in each state the list tells apart: accepted and invoiced, accepted, refused, sent and expired, sent
     * and still binding, a draft and a draft cancelled. Each binds for the company's 30 days.
     *
     * @param \Closure(): Company $company
     * @param list<Uuid>          $customerIds
     * @param list<Uuid>          $sellable
     */
    private function planQuotes(Timeline $timeline, \Closure $company, array $customerIds, array $sellable, Uuid $actor): void
    {
        /** @var list<array{customer: int, day: int, send?: int, accept?: int, refuse?: int, deposit?: int, invoice?: int, cancel?: int}> $plan */
        $plan = [
            // A deposit of 30 %, paid, then given back on the invoice of the whole.
            ['customer' => 1, 'day' => -100, 'send' => -99, 'accept' => -90, 'deposit' => -89, 'invoice' => -80],
            ['customer' => 4, 'day' => -50, 'send' => -49, 'accept' => -40],
            ['customer' => 6, 'day' => -70, 'send' => -70, 'refuse' => -55],
            ['customer' => 8, 'day' => -45, 'send' => -44],
            ['customer' => 5, 'day' => -6, 'send' => -5],
            ['customer' => 10, 'day' => -2],
            ['customer' => 11, 'day' => -30, 'cancel' => -29],
        ];
        /** @var array<int, Uuid> $ids */
        $ids = [];
        foreach ($plan as $n => $quote) {
            $customerId = $customerIds[$quote['customer'] % \count($customerIds)];
            $lines = [
                // A discount as an amount on one quote in two, small enough for the cheapest product the catalogue sells.
                new QuoteLineInput($sellable[(2 * $n + 1) % \count($sellable)], null, (string) (3 + $n), discountAmount: 1 === $n % 2 ? '0.1' : null),
                new QuoteLineInput($sellable[(2 * $n + 4) % \count($sellable)], null, '1', discountRate: 0 === $n % 2 ? '5' : null),
            ];
            $header = new QuoteHeader(customerReference: 0 === $n ? 'DA-2026-014' : null, discountAmount: 1 === $n ? '10' : null);
            $timeline->at($quote['day'], function () use (&$ids, $n, $company, $customerId, $header, $lines, $actor): void {
                $ids[$n] = $this->quotes->create($company(), new QuoteInput($customerId, null, $header, $lines), $actor)->getId();
            });
            // Each step reads the quote's id when it runs, after the creation step wrote it.
            $steps = [
                'send' => function () use (&$ids, $n, $company, $actor): void {
                    $this->quoteWorkflow->send($company(), $ids[$n], $actor);
                },
                'accept' => function () use (&$ids, $n, $company, $actor): void {
                    $this->quoteWorkflow->accept($company(), $ids[$n], null, $actor);
                },
                'refuse' => function () use (&$ids, $n, $company, $actor): void {
                    $this->quoteWorkflow->refuse($company(), $ids[$n], null, 'Délai de livraison trop long.', $actor);
                },
                'cancel' => function () use (&$ids, $n, $company, $actor): void {
                    $this->quoteWorkflow->cancel($company(), $ids[$n], $actor);
                },
                'deposit' => function () use (&$ids, $n, $company, $actor, $timeline, $quote): void {
                    $this->quoteWorkflow->deposit($company(), $ids[$n], '30', null, $actor);
                    $deposit = $this->quoteInvoices->depositsOf($company(), [$ids[$n]])[$ids[$n]->toRfc4122()][0] ?? throw new \LogicException('A deposit drawn is listed on its quote.');
                    $depositId = Uuid::fromString($deposit->invoiceId);
                    $issued = $this->invoiceWorkflow->issue($company(), $depositId, $actor)->getIssuedFigures() ?? throw new \LogicException('An issued invoice has figures.');
                    $this->payments->record($company(), $depositId, new PaymentDetails($timeline->day($quote['deposit'] ?? 0), $issued->amountDue, PaymentMethod::Transfer), $actor);
                },
                'invoice' => function () use (&$ids, $n, $company, $actor): void {
                    $invoiceId = $this->quoteWorkflow->invoice($company(), $ids[$n], $actor)->getInvoiceId() ?? throw new \LogicException('An invoiced quote names its invoice.');
                    $this->invoiceWorkflow->issue($company(), $invoiceId, $actor);
                },
            ];
            foreach ($steps as $step => $run) {
                if (isset($quote[$step])) {
                    $timeline->at($quote[$step], $run);
                }
            }
        }
    }

    /** The sections a three-line demo invoice is laid out in, by the line that opens each. */
    private const array INVOICE_SECTIONS = [0 => 'Fournitures', 2 => 'Compléments'];

    /**
     * An invoice every five days. Of those already due: paid in full (a few in two payments), half paid, left unpaid
     * (overdue), or corrected by a full credit note. The most recent are not due yet. Then two drafts and a draft
     * cancelled.
     *
     * @param \Closure(): Company $company
     * @param list<Uuid>          $customerIds
     * @param list<Uuid>          $sellable    services and untracked goods
     */
    private function planInvoices(DemoCompany $demo, Timeline $timeline, \Closure $company, array $customerIds, array $sellable, Uuid $actor): void
    {
        /** @var array<int, Uuid> $ids */
        $ids = [];
        $input = static function (int $i) use ($customerIds, $sellable): InvoiceInput {
            $lines = [];
            for ($k = 0; $k <= $i % 3; ++$k) {
                $lines[] = new InvoiceLineInput($sellable[(7 * $i + 11 * $k) % \count($sellable)], null, (string) (1 + ($i + $k) % 4), discountRate: 0 === $i % 5 && 0 === $k ? '10' : null, discountAmount: 0 === $i % 5 && 1 === $k ? '0.1' : null, section: 2 === $i % 3 ? self::INVOICE_SECTIONS[$k] ?? null : null);
            }

            return new InvoiceInput(
                $customerIds[(5 * $i + 1) % \count($customerIds)],
                null,
                new InvoiceHeader(customerReference: 0 === $i % 3 ? \sprintf('BC-%04d', 2600 + $i) : null),
                $lines,
            );
        };
        $pay = function (int $i, int $day, bool $half) use (&$ids, $demo, $timeline, $company, $actor): void {
            $timeline->at($day, function () use (&$ids, $i, $day, $half, $demo, $timeline, $company, $actor): void {
                $due = ($this->invoices->get($company(), $ids[$i])->getIssuedFigures() ?? throw new \LogicException('An issued invoice has figures.'))->amountDue;
                if (!is_numeric($due)) {
                    throw new \LogicException("An invoice's amount due is a number, not \"$due\".");
                }
                $amount = $half ? bcdiv($due, '2', $demo->scale) : bcadd($due, '0', $demo->scale);
                $this->payments->record($company(), $ids[$i], new PaymentDetails($timeline->day($day), $amount, self::METHODS[$i % \count(self::METHODS)]), $actor);
            });
        };

        for ($i = 0; $i < self::INVOICES; ++$i) {
            $day = -150 + 5 * $i;
            $timeline->at($day, function () use (&$ids, $i, $input, $company, $actor): void {
                $ids[$i] = $this->invoices->create($company(), $input($i), $actor)->getId();
                $this->invoiceWorkflow->issue($company(), $ids[$i], $actor);
            });
            if ($i >= 24) {
                continue; // not due yet
            }
            if (6 === $i || 18 === $i) {
                $timeline->at($day + 7, function () use (&$ids, $i, $company, $actor): void {
                    $creditNote = $this->invoices->draftCreditNote($company(), $ids[$i], 6 === $i ? 'Retour d\'une partie de la commande' : 'Geste commercial après un retard de livraison', $actor);
                    $this->invoiceWorkflow->issue($company(), $creditNote->getId(), $actor);
                });
                continue;
            }
            if (1 === $i % 4) {
                $pay($i, $day + 12, true);
            } elseif (3 === $i % 8) {
                $pay($i, $day + 8, true);
                $pay($i, $day + 18, false);
            } elseif (2 !== $i % 4) {
                $pay($i, $day + 10 + 5 * ($i % 3), false);
            } // else left unpaid: overdue
            if (0 === $i) {
                // Paid twice over by a few dinars: a « trop-perçu » the customer keeps to their credit.
                $timeline->at($day + 11, function () use (&$ids, $i, $demo, $timeline, $day, $company, $actor): void {
                    $this->credit->overpayment($company(), $ids[$i], new PaymentDetails($timeline->day($day + 11), bcadd('5', '0', $demo->scale), PaymentMethod::Cash), $actor);
                });
            }
        }

        $timeline->at(-6, function () use ($input, $company, $actor): void {
            $cancelled = $this->invoices->create($company(), $input(self::INVOICES), $actor);
            $this->invoices->cancel($company(), $cancelled->getId(), $actor);
        });
        $timeline->at(-5, function () use ($input, $company, $actor): void {
            $this->invoices->create($company(), $input(self::INVOICES + 1), $actor);
            $this->invoices->create($company(), $input(self::INVOICES + 2), $actor);
        });
    }

    private function phone(string $country, int $n): string
    {
        return 'TN' === $country
            ? \sprintf('+216 71 %03d %03d', 200 + $n % 800, (37 * $n) % 1000)
            : \sprintf('+33 4 %02d %02d %02d %02d', 10 + $n % 90, (7 * $n) % 100, (13 * $n) % 100, (31 * $n) % 100);
    }
}

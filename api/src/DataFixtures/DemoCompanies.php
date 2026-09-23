<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Identity\Domain\Email;
use App\Identity\Domain\UserRepository;
use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Application\ManageContacts;
use App\Module\Customers\Application\ManageCustomerGroups;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\ContactDetails;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Application\DeliveryNoteInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteLineInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\Expenses\Application\ExpenseInput;
use App\Module\Expenses\Application\ManageExpenseCategories;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\ExpenseDetails;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Domain\NamedLot;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Invoices\Application\InvoiceInput;
use App\Module\Invoices\Application\InvoiceLineInput;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Application\ManagePayments;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Application\ManageProductCategories;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductInput;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Domain\VendorProfile;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Shared\Domain\PaymentMethod;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Application\Company\ReviseCompanyProfile;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\AcceptRequest;
use App\Tenancy\Application\Invitation\InviteRequest;
use App\Tenancy\Application\Invitation\InviteToCompany;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Application\Seed\SeedRequest;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * The demo dataset (docs/SPEC.md § 7, 2026-09-19): the two companies of `DemoCatalogue`, owned by the seeded
 * operator and written through the application's use cases, never around them, so they carry the numbers, totals,
 * stock movements and audit rows the product itself would have written. Five months of activity end a few days
 * before the load: invoices paid, part paid, overdue, credited, drafted and cancelled; delivery notes in every
 * state; expenses drafted, recorded and paid.
 *
 * Load with `make fixtures` (`doctrine:fixtures:load --append`: never without it, which empties the database). A
 * company that already exists is left as it is, so a second load changes nothing.
 */
final class DemoCompanies extends Fixture
{
    public const string OPERATOR = 'operator@twes.local';
    /**
     * One member per built-in role in every demo company, so what a role may not do is something a person can sign
     * in and meet rather than read about (§ 7, 2026-09-20). They join through the invitation use cases like any
     * other member, so their memberships, audit rows and password rules are the product's own and not seeded rows.
     * Development passwords, on a development dataset: `make fixtures` refuses to run outside dev and test.
     */
    public const array TESTERS = [
        'owner' => 'owner@twes.local',
        'admin' => 'admin@twes.local',
        'member' => 'member@twes.local',
    ];
    /** Long enough for the password policy, and not a word any breach list carries. */
    public const string TESTER_PASSWORD = 'twes-role-test-2026';
    /** The first day of a company's history, in days from today. */
    private const int FIRST_DAY = -170;
    private const int INVOICES = 28;
    private const array STREETS = ['rue de la République', 'avenue de la Liberté', 'rue des Jardins', 'place du Marché'];
    private const array METHODS = [PaymentMethod::Transfer, PaymentMethod::Check, PaymentMethod::Cash, PaymentMethod::Card];

    public function __construct(
        private readonly UserRepository $users,
        private readonly CompanyRepository $companies,
        private readonly SeedPlatform $seed,
        private readonly ReviseCompanyProfile $profiles,
        private readonly ChangeSettings $settings,
        private readonly UnitRepository $units,
        private readonly TaxComponentRepository $taxes,
        private readonly ManageEstablishments $establishments,
        private readonly ManageCustomerGroups $customerGroups,
        private readonly ManageCustomers $customers,
        private readonly ManageContacts $contacts,
        private readonly ManageProductCategories $productCategories,
        private readonly ManageProducts $products,
        private readonly ManageStockLocations $stockLocations,
        private readonly KeepStock $stock,
        private readonly ManageExpenseCategories $expenseCategories,
        private readonly ManageVendors $vendors,
        private readonly ManageExpenses $expenses,
        private readonly ManageDeliveryNotes $deliveryNotes,
        private readonly DeliveryNoteWorkflow $deliveryNoteWorkflow,
        private readonly InvoiceDeliveryNotes $invoiceDeliveryNotes,
        private readonly ManageInvoices $invoices,
        private readonly InvoiceWorkflow $invoiceWorkflow,
        private readonly ManagePayments $payments,
        private readonly InviteToCompany $invitations,
        private readonly AcceptInvitation $acceptances,
        private readonly CapturingInvitationMailer $mailed,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $operator = $this->users->ofEmail(Email::fromString(self::OPERATOR))
            ?? throw new \RuntimeException(\sprintf('The demo companies belong to %s, who does not exist yet: run make seed first.', self::OPERATOR));

        // Every flush compares every entity the manager holds, so a load that kept them all slowed down with each row
        // it wrote (four minutes for the second company). Written rows are only read again by id: letting go of them
        // after each row or step is Doctrine's own batch-processing practice.
        $settle = static function () use ($manager): void {
            $manager->clear();
        };
        $clock = Clock::get();
        try {
            foreach (DemoCatalogue::all() as $demo) {
                if (null === $this->companies->ofName($demo->name)) {
                    $this->write($demo, $operator->getId(), $settle);
                }
            }
        } finally {
            Clock::set($clock);
        }
    }

    /** @param \Closure(): void $settle */
    private function write(DemoCompany $demo, Uuid $actor, \Closure $settle): void
    {
        $timeline = new Timeline(new \DateTimeImmutable('today', new \DateTimeZone($demo->timezone)), $settle);
        Clock::set(new MockClock($timeline->day(self::FIRST_DAY)->setTime(9, 0)));

        // The seed is the one path that makes a company active, owned by the operator and provisioned at once.
        $this->seed->seed(new SeedRequest(self::OPERATOR, null, 'Operator', $demo->name, $demo->country, $demo->currency, 'fr', $demo->timezone));
        $companyId = ($this->companies->ofName($demo->name) ?? throw new \LogicException("$demo->name was seeded a moment ago."))->getId();
        $company = fn (): Company => $this->companies->ofId($companyId) ?? throw new \LogicException("$demo->name vanished.");
        $unit = fn (string $code): Uuid => ($this->units->ofCodeInCompany($code, $companyId) ?? throw new \LogicException("The $demo->country preset has no unit $code."))->getId();
        $tax = fn (string $code): Uuid => ($this->taxes->ofCodeInCompany($code, $companyId) ?? throw new \LogicException("The $demo->country preset has no tax $code."))->getId();

        $this->profiles->handle($company(), $demo->profile, $actor);
        $this->addTesters($companyId, $actor);
        $this->settings->change(new SettingContext($company()), 'article.stock_tracking', SettingLevel::Company, true, $actor);

        $customerIds = $this->writeCustomers($demo, $company, $tax, $actor, $settle);
        $vendors = $this->writeVendors($demo, $company, $actor);
        [$sellable, $goods, $tracked] = $this->writeProducts($demo, $company, $unit, $tax, $vendors, $actor, $settle);

        $this->planExpenses($demo, $timeline, $company, $vendors, $tax, $actor);
        $this->planDeliveryNotes($timeline, $company, $customerIds, $goods, $tracked, $actor);
        $this->planInvoices($demo, $timeline, $company, $customerIds, $sellable, $actor);
        $timeline->run();
    }

    /**
     * Puts one person of each built-in role into the company, through the invitation the product itself sends.
     *
     * Nothing is written directly: the address is invited and the invitation is then accepted, so each member gets
     * the membership, the audit rows and the password checks any real member gets. The second company invites the
     * same three addresses again, and accepting with an account that already exists simply adds the membership —
     * which is why the name and password are only offered the first time.
     */
    private function addTesters(Uuid $companyId, Uuid $actor): void
    {
        foreach (self::TESTERS as $role => $email) {
            $this->invitations->handle(new InviteRequest($companyId, $email, $role), $actor);
            $known = null !== $this->users->ofEmail(Email::fromString($email));
            $this->acceptances->handle(new AcceptRequest(
                $this->mailed->tokenFor($email),
                $known ? null : ucfirst($role).' Demo',
                $known ? null : self::TESTER_PASSWORD,
            ));
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
                billingAddress: new PostalAddress(\sprintf('%d, %s', 3 + 4 * $n, self::STREETS[$n % \count(self::STREETS)]), null, null, $row->city, $row->country),
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
        // Deliveries take goods out of the default location; the racks are there to be seen on the locations screen.
        $shelf = $this->stockLocations->defaultOf($establishment)->getId();
        $this->stockLocations->create($company(), $establishment->getId(), null, StockLocationKind::Rack, 'RAYON-A', 'Rayonnage A', $actor);
        $this->stockLocations->create($company(), $establishment->getId(), null, StockLocationKind::Rack, 'RAYON-B', 'Rayonnage B', $actor);

        $sellable = [];
        $goods = [];
        $tracked = [];
        foreach ($demo->products as $n => $row) {
            $service = isset($row['service']);
            $tracking = ProductTracking::from($row['tracking'] ?? 'none');
            $product = $this->products->create($company(), new ProductInput(
                $row['ref'],
                new ProductDetails($row['name'], null, $service ? ProductKind::Service : ProductKind::Goods, $row['price'], $service ? null : bcmul($row['price'], '0.6', $demo->scale)),
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
            if (!isset($row['inactive']) && !$service && ProductTracking::None === $tracking) {
                $goods[] = $product->getId();
                $this->stock->receive($company(), $product->getId(), $shelf, (string) (40 + (37 * $n) % 160), $actor);
            } elseif (!isset($row['inactive']) && !$service) {
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
     * Sixteen expenses, one every nine days: a third left as drafts, a third recorded, a third recorded and paid.
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
                $expense = $this->expenses->create($company(), new ExpenseInput(
                    new ExpenseDetails($date, \sprintf('%s, %s', $vendor['name'], $date->format('m/Y')), bcmul($vendor['amount'], ['1', '1.1', '0.95'][$e % 3], $demo->scale), \sprintf('F-%s-%04d', $date->format('Y'), 310 + $e)),
                    $vendor['id'],
                    $vendor['category'],
                    $vendor['untaxed'] ? null : $tax($demo->vatCode),
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
        /** @var list<array{customer: int, day: int, validate: bool, deliver?: int, cancel?: int, invoice?: int}> $plan */
        $plan = [
            ['customer' => 0, 'day' => -120, 'validate' => true, 'deliver' => -118, 'invoice' => -110],
            ['customer' => 7, 'day' => -80, 'validate' => true, 'deliver' => -78],
            ['customer' => 3, 'day' => -60, 'validate' => true, 'cancel' => -58],
            ['customer' => 9, 'day' => -40, 'validate' => true],
            ['customer' => 12, 'day' => -25, 'validate' => true, 'deliver' => -24],
            ['customer' => 2, 'day' => -8, 'validate' => false],
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
                    $invoice = $this->invoiceDeliveryNotes->draftInvoice($company(), [$ids[$n]], $actor);
                    $this->invoiceWorkflow->issue($company(), $invoice->getId(), $actor);
                });
            }
        }
    }

    /**
     * An invoice every five days. Of those already due: paid in full (a few in two payments), half paid, left unpaid
     * (overdue), or corrected by a full credit note. The most recent are not due yet. Then two drafts and a draft
     * cancelled.
     *
     * @param \Closure(): Company $company
     * @param list<Uuid>          $customerIds
     * @param list<Uuid>          $sellable
     */
    private function planInvoices(DemoCompany $demo, Timeline $timeline, \Closure $company, array $customerIds, array $sellable, Uuid $actor): void
    {
        /** @var array<int, Uuid> $ids */
        $ids = [];
        $input = static function (int $i) use ($customerIds, $sellable): InvoiceInput {
            $lines = [];
            for ($k = 0; $k <= $i % 3; ++$k) {
                $lines[] = new InvoiceLineInput($sellable[(7 * $i + 11 * $k) % \count($sellable)], null, (string) (1 + ($i + $k) % 4), discountRate: 0 === $i % 5 && 0 === $k ? '10' : null);
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
                    $creditNote = $this->invoices->draftCreditNote($company(), $ids[$i], $actor);
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

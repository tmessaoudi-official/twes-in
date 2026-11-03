<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Licensing\Application\ManagePayments as ManageSubscriptionPayments;
use App\Licensing\Domain\Model\Subscription;
use App\Licensing\Domain\ValueObject\DeclaredPayment;
use App\Licensing\Domain\ValueObject\PaymentMethod as SubscriptionPaymentMethod;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\ValueObject\NamedLot;
use App\Module\Inventory\Domain\ValueObject\StockLossReason;
use App\Module\Invoices\Application\Credit\ManageCustomerCredit;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Application\Payment\ManagePayments;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\ApplyCustomerCredit;
use App\Tests\Concurrency\Support\Steps\ChangeCost;
use App\Tests\Concurrency\Support\Steps\DecidePaymentDeclaration;
use App\Tests\Concurrency\Support\Steps\DeleteRecords;
use App\Tests\Concurrency\Support\Steps\IssueOrMergeCustomer;
use App\Tests\Concurrency\Support\Steps\ReceiveLot;
use App\Tests\Concurrency\Support\Steps\RunRecurring;
use App\Tests\Concurrency\Support\Steps\UndoImportRun;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The money and stock lock sites, each contested by two racers at the same moment. Each test passes only while its lock
 * is taken: removing the lock named beside it turns the test red (the sabotage of each row).
 *
 * | Lock site                                                                  | Test                                                        | What the lock keeps                                     |
 * |----------------------------------------------------------------------------|-------------------------------------------------------------|---------------------------------------------------------|
 * | Licensing DoctrinePaymentDeclarationRepository::lockedOfId (FOR UPDATE)    | testOnePaymentDeclarationConfirmedTwiceAtOnceIsDecidedOnce   | a declaration decided once, the paid time moved once    |
 * | Customers DoctrineCustomerRepository::lockedOfIdInCompany (FOR UPDATE),     | testACreditSpentOnTwoInvoicesAtOnceIsSpentOnce              | a customer's credit never spent twice                   |
 * |   held by ManageCustomerCredit::apply through CustomerAccountsHeld         |                                                             |                                                         |
 * | Inventory DoctrineStockLotRepository::lock (advisory, product and code)    | testOneSerialNumberReceivedTwiceAtOnceIsInStockOnce         | a serial number in stock once, its lot opened once      |
 * | Products DoctrineProductRepository::lockedOfIdInCompany (FOR UPDATE)       | testTwoCostChangesOfOneProductAtOnceAreBothKeptInOrder       | each cost change taken in, its history one chain        |
 * | ImportExport DoctrineImportRunRepository::lockedOfId (FOR UPDATE)          | testOneImportUndoneTwiceAtOnceIsUndoneOnce                  | an import taken back once                               |
 * | Records DbalRecordStore::lockedLabels (FOR UPDATE OF the records)          | testTheSameRecordsDeletedTwiceAtOnceGoOnce                  | one delete per record, one removal in the bin           |
 * | Recurring DoctrineRecurringInvoiceRepository::lockedOfIdInCompany (FOR UPDATE) | testTheRecurringPassRunTwiceAtOnceDraftsOnce            | an occurrence drafted once                              |
 * | Invoices AdvisoryCreditAccountsHeld::hold (advisory, company and customer), | CreditAlertRaceTest's two-series cases                      | a credit limit's crossing told once, never by nobody    |
 * |   held by TellCreditWatchers::whileIssuing, last of an issue's locks       |                                                             |                                                         |
 * | FC#80: the invoice's row (issue) against the merge's moves                 | testACustomerMergedWhileItsDraftIsIssuedLeavesOneTruth      | an issued invoice bills whom it printed, no draft left  |
 * |                                                                            |                                                             | on a customer merged away, never a 500 carrying SQL     |
 */
final class LockSitesRaceTest extends SaleRaceTestCase
{
    private const string OWNER_PASSWORD = 'password-1234';

    protected function saleWithoutStock(): string
    {
        return 'allow';
    }

    protected function onHand(): string
    {
        return '10';
    }

    public function testOnePaymentDeclarationConfirmedTwiceAtOnceIsDecidedOnce(): void
    {
        $operator = $this->createUser('op-'.bin2hex(random_bytes(6)).'@twes.local', self::OWNER_PASSWORD, operator: true);
        $this->login($operator->getEmail()->value, self::OWNER_PASSWORD);
        $this->stepUp(self::OWNER_PASSWORD);
        $this->sendJson('PUT', '/api/platform/companies/'.$this->companyId().'/subscription', [
            'periodCount' => 1, 'periodUnit' => 'month', 'trialEndsOn' => null, 'paidThrough' => self::dayOf($this->company, '+30 days'),
            'price' => null, 'currency' => null, 'graceDays' => null, 'unpaidMode' => null, 'holdDays' => null, 'version' => 0,
        ]);
        self::assertResponseIsSuccessful();
        $before = $this->subscription()->getTerms();
        $declaration = static::getContainer()->get(ManageSubscriptionPayments::class)->declare(
            $this->freshCompany(),
            new DeclaredPayment('600.000', 'TND', SubscriptionPaymentMethod::Cash, self::dayAsDate($this->company, '-1 day')),
            Uuid::fromString($this->sellerId),
        );

        $outcomes = Race::run(DecidePaymentDeclaration::class, 2, ['declaration' => $declaration->getId()->toRfc4122(), 'operator' => $operator->getId()->toRfc4122()]);

        $this->oneWonOneRefused($outcomes, 'already decided');
        self::assertSame(1, $this->rows("SELECT count(*) FROM audit_log WHERE action = 'subscription.payment_confirmed' AND entity_id = ?", $declaration->getId()->toRfc4122()), 'decided once');
        $this->em()->clear();
        self::assertNotNull($before->paidUntil);
        self::assertEquals($before->period->after($before->paidUntil, 1), $this->subscription()->getTerms()->paidUntil, 'one period carried forward, not two');
    }

    /**
     * Two invoices, so no invoice's lock lines the two up: only the customer's row, which every spending of the credit
     * holds, keeps both from fitting the balance read before either.
     */
    public function testACreditSpentOnTwoInvoicesAtOnceIsSpentOnce(): void
    {
        $customer = $this->customer();
        $paid = $this->issued($this->draft('invoices', $customer));
        $today = self::dayAsDate($this->company, 'today');
        static::getContainer()->get(ManagePayments::class)->record($this->freshCompany(), Uuid::fromString($paid), new PaymentDetails($today, $this->due($paid), PaymentMethod::Cash), null);
        static::getContainer()->get(ManageCustomerCredit::class)->overpayment($this->freshCompany(), Uuid::fromString($paid), new PaymentDetails($today, '100.000', PaymentMethod::Cash), null);
        $invoices = [$this->issued($this->draft('invoices', $customer)), $this->issued($this->draft('invoices', $customer))];

        $outcomes = Race::run(ApplyCustomerCredit::class, 2, ['company' => $this->companyId(), 'invoices' => $invoices, 'amount' => '100.000']);

        $this->oneWonOneRefused($outcomes, "to the customer's credit");
        self::assertSame('0.000', static::getContainer()->get(ManageCustomerCredit::class)->balance($this->freshCompany(), Uuid::fromString($customer)), 'the credit spent once, never below zero');
        self::assertSame(1, $this->rows("SELECT count(*) FROM payment WHERE invoice_id IN (?, ?) AND reference = 'credit_balance'", ...$invoices), 'one payment made of credit');
    }

    /** @return iterable<string, array{bool}> */
    public static function serialHistories(): iterable
    {
        yield 'a serial number never seen' => [false];
        yield 'a serial number received and gone before' => [true];
    }

    /**
     * The lot's lock is the only one a receipt takes before it reads the lot: a receipt locks no place's stock, so two
     * receipts of one serial number are lined up by the lock on its code or not at all.
     */
    #[DataProvider('serialHistories')]
    public function testOneSerialNumberReceivedTwiceAtOnceIsInStockOnce(bool $seenBefore): void
    {
        $product = $this->serialProduct();
        $location = $this->defaultPlace();
        if ($seenBefore) {
            $stock = static::getContainer()->get(KeepStock::class);
            $stock->receive($this->freshCompany(), Uuid::fromString($product), Uuid::fromString($location), '1', null, new NamedLot('SN-0001'));
            $stock->writeOff($this->freshCompany(), Uuid::fromString($product), Uuid::fromString($location), '1', StockLossReason::Lost, null, null, new NamedLot('SN-0001'));
        }

        $outcomes = Race::run(ReceiveLot::class, 2, ['company' => $this->companyId(), 'product' => $product, 'location' => $location, 'lot' => 'SN-0001']);

        $this->oneWonOneRefused($outcomes, 'is already in stock');
        self::assertSame(1, $this->rows("SELECT count(*) FROM stock_lot WHERE product_id = ? AND code = 'SN-0001'", $product), 'the lot opened once');
        self::assertSame('1.000', Rows::text($this->em()->getConnection()->fetchOne(
            "SELECT SUM(m.quantity) FROM stock_movement m JOIN stock_lot l ON l.id = m.lot_id WHERE m.product_id = ? AND l.code = 'SN-0001'",
            [$product],
        )), 'the serial number in stock once');
    }

    /** The second change waits for the first and is taken in after it: none refused, the history a chain. */
    public function testTwoCostChangesOfOneProductAtOnceAreBothKeptInOrder(): void
    {
        $outcomes = Race::run(ChangeCost::class, 2, ['company' => $this->companyId(), 'product' => $this->productId, 'costs' => ['11.0000', '12.0000']]);

        foreach ($outcomes as $outcome) {
            self::assertSame([Outcome::OK, true], [$outcome->kind, $outcome->value], $outcome->describe());
        }
        $history = $this->em()->getConnection()->fetchAllAssociative(
            "SELECT old_cost, new_cost FROM product_cost_change WHERE product_id = ? AND source = 'edited' ORDER BY at, id",
            [$this->productId],
        );
        self::assertCount(2, $history);
        $first = $history[0]['old_cost'] === $history[1]['new_cost'] ? 1 : 0;
        self::assertSame($history[$first]['new_cost'], $history[1 - $first]['old_cost'], 'the later change starts from the earlier: '.json_encode($history));
        self::assertSame($history[1 - $first]['new_cost'], Rows::text($this->em()->getConnection()->fetchOne('SELECT cost_price FROM product WHERE id = ?', [$this->productId])), 'the product costs what the later change said');
    }

    public function testOneImportUndoneTwiceAtOnceIsUndoneOnce(): void
    {
        $owner = $this->owner();
        $this->regimes();
        $this->uploadFile($this->path('imports/customers'), 'customers.csv', "number,name\nCLI-0100,Atlas Bois\nCLI-0101,Sonia Ben Ali\n", 'file', ['mode' => 'create', 'dryRun' => '0']);
        self::assertResponseIsSuccessful();
        $run = $this->stringAt($this->json(), 'run');

        $outcomes = Race::run(UndoImportRun::class, 2, ['company' => $this->companyId(), 'run' => $run, 'actor' => $owner]);

        $this->oneWonOneRefused($outcomes, 'taken back already');
        self::assertSame(1, $this->rows("SELECT count(*) FROM audit_log WHERE action = 'import.undone' AND entity_id = ?", $run), 'undone once');
        self::assertSame(1, $this->rows("SELECT count(*) FROM record_removal WHERE company_id = ? AND kind = 'import'"), 'one delete in the bin');
        self::assertSame(0, $this->rows("SELECT count(*) FROM customer WHERE company_id = ? AND number IN ('CLI-0100', 'CLI-0101')"));
    }

    public function testTheSameRecordsDeletedTwiceAtOnceGoOnce(): void
    {
        $ids = [$this->aCustomer('CLI-0200', 'Atlas Bois'), $this->aCustomer('CLI-0201', 'Sonia Ben Ali')];
        sort($ids);

        $outcomes = Race::run(DeleteRecords::class, 2, ['company' => $this->companyId(), 'subject' => 'customer', 'ids' => $ids]);

        $this->oneWonOneRefused($outcomes, 'has not every customer');
        self::assertSame(1, $this->rows("SELECT count(*) FROM record_removal WHERE company_id = ? AND subject = 'customer'"), 'one delete in the bin');
        self::assertSame(2, $this->rows("SELECT count(*) FROM record_removal_row WHERE company_id = ? AND table_name = 'customer'"), 'each record copied once');
        self::assertSame(0, $this->rows("SELECT count(*) FROM customer WHERE company_id = ? AND number IN ('CLI-0200', 'CLI-0201')"));
    }

    public function testTheRecurringPassRunTwiceAtOnceDraftsOnce(): void
    {
        $model = $this->issued($this->draft('invoices', $this->customer()));
        $today = self::dayOf($this->company);
        $this->postJson($this->path('recurring-invoices'), ['modelInvoiceId' => $model, 'unit' => 'month', 'every' => 1, 'anchor' => 'date', 'monthDay' => (int) new \DateTimeImmutable($today)->format('j'), 'startsOn' => $today, 'endsOn' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $outcomes = Race::run(RunRecurring::class, 2, ['company' => $this->companyId()]);

        $drafted = 0;
        foreach ($outcomes as $outcome) {
            self::assertSame(Outcome::OK, $outcome->kind, $outcome->describe());
            $drafted += \is_int($outcome->value) ? $outcome->value : 0;
        }
        self::assertSame(1, $drafted, 'the occurrence drafted by one pass');
        self::assertSame(1, $this->rows("SELECT count(*) FROM invoice WHERE company_id = ? AND status = 'draft'"), 'one draft');
    }

    /**
     * FC#80. Either may go first: an invoice issued first keeps billing the customer it printed, and the merge then
     * leaves it there; a merge first moves the draft, which is then issued to the customer kept. A deadlock (40P01) is
     * the one failure this race tolerates, unlike every other here: the kernel answers it 409 /problems/busy and the
     * screen sends again (it reached the person as a 500 with its SQL before). What must never be is a state the two
     * left half each: an issued invoice whose printed customer is not the one it names, or a draft still billing a
     * customer merged away.
     */
    public function testACustomerMergedWhileItsDraftIsIssuedLeavesOneTruth(): void
    {
        $owner = $this->owner();
        $merged = $this->customer();
        $kept = $this->aCustomer('CLI-0002', 'Carthage Conseil SARL', business: true);
        $invoice = $this->draft('invoices', $merged);

        $outcomes = Race::run(IssueOrMergeCustomer::class, 2, ['company' => $this->companyId(), 'invoice' => $invoice, 'merged' => $merged, 'kept' => $kept, 'actor' => $owner]);

        $said = implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes));
        $won = 0;
        foreach ($outcomes as $outcome) {
            self::assertContains($outcome->kind, [Outcome::OK, Outcome::DEADLOCK], $said);
            $won += Outcome::OK === $outcome->kind ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(1, $won, $said);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT status, number, customer_id::text AS customer, customer_snapshot->>\'name\' AS printed FROM invoice WHERE id = ?', [$invoice]);
        self::assertIsArray($row);
        $issued = Outcome::OK === $outcomes[0]->kind;
        self::assertSame($issued ? 'issued' : 'draft', $row['status'], $said);
        if ($issued) {
            self::assertNotNull($row['number']);
            self::assertSame(Rows::text($this->em()->getConnection()->fetchOne('SELECT name FROM customer WHERE id = ?', [$row['customer']])), $row['printed'], 'an issued invoice names the customer it printed');
        }
        if (Outcome::OK === $outcomes[1]->kind) {
            self::assertSame($kept, Rows::text($this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM customer WHERE id = ?', [$merged])), 'the customer merged away points at the one kept');
            self::assertSame(0, $this->rows("SELECT count(*) FROM invoice WHERE customer_id = ? AND status = 'draft'", $merged), 'no draft left billing the customer merged away');
        }
    }

    /** @param list<Outcome> $outcomes */
    private function oneWonOneRefused(array $outcomes, string $refusal): void
    {
        $said = implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes));
        $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
        sort($kinds);
        self::assertSame([Outcome::ERROR, Outcome::OK], $kinds, $said);
        foreach ($outcomes as $outcome) {
            if (Outcome::ERROR === $outcome->kind) {
                self::assertStringContainsString($refusal, $outcome->message, 'refused for the lock\'s own reason: '.$said);
            }
        }
    }

    private function issued(string $draft): string
    {
        static::getContainer()->get(InvoiceWorkflow::class)->issue($this->freshCompany(), Uuid::fromString($draft), null);

        return $draft;
    }

    private function due(string $invoice): string
    {
        return Rows::text($this->em()->getConnection()->fetchOne('SELECT amount_due FROM invoice WHERE id = ?', [$invoice]));
    }

    /** A customer of the company with nothing naming it; a business one bills as the race's first customer does. */
    private function aCustomer(string $number, string $name, bool $business = false): string
    {
        $this->regimes();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $profile = $business ? self::aTunisianBusiness($name) : new CustomerProfile(CustomerKind::Individual, $name, null, [], null, null, null, new PostalAddress(countryCode: 'TN'), null);
        $customer = Customer::create($this->freshCompany(), $number, $profile, null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer->getId()->toRfc4122();
    }

    /** The preset's customer regimes, as at start: each case runs alone as well as after the others. */
    private function regimes(): void
    {
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
    }

    private function serialProduct(): string
    {
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $product = Product::create($this->freshCompany(), 'ART-SN', new ProductDetails('Meuleuse', null, ProductKind::Goods, '400'), $piece, null, [], new \DateTimeImmutable());
        $product->track(ProductTracking::Serial, new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();

        return $product->getId()->toRfc4122();
    }

    private function defaultPlace(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    /** An owner of the company signed in, who has just proved who they are: its id. */
    private function owner(): string
    {
        $email = 'owner-'.bin2hex(random_bytes(6)).'@twes.local';
        $owner = $this->createUser($email, self::OWNER_PASSWORD, $this->freshCompany());
        $this->login($email, self::OWNER_PASSWORD);
        self::assertResponseIsSuccessful();
        $this->stepUp(self::OWNER_PASSWORD);

        return $owner->getId()->toRfc4122();
    }

    private function subscription(): Subscription
    {
        $subscription = $this->em()->getRepository(Subscription::class)->findOneBy(['company' => $this->company->getId()]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    /** The company as this kernel reads it: a request since reboots the kernel, and the one set up is detached. */
    private function freshCompany(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function companyId(): string
    {
        return $this->company->getId()->toRfc4122();
    }

    private function rows(string $sql, string ...$parameters): int
    {
        return Rows::int($this->em()->getConnection()->fetchOne($sql, [] === $parameters ? [$this->companyId()] : $parameters));
    }
}

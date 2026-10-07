<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Invoices;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\InvoiceSummarySource;
use App\Module\Invoices\Application\SummarizeInvoices;
use App\Module\Invoices\Domain\CustomerCreditEntry;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\Payment;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Shared\Domain\PaymentMethod;
use App\Shared\Domain\PrintSettings;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The home page's figures as the database sums them: the same figures a walk over every invoice gave, in a handful of
 * statements whatever the number of invoices.
 */
final class SummarizeInvoicesTest extends KernelTestCase
{
    private MockClock $clock;
    private Company $company;
    private Company $globex;
    private SummarizeInvoices $summarize;

    protected function setUp(): void
    {
        self::bootKernel();
        // Half past eleven at night in UTC is already the 21st in Tunis: every day below is counted from the 21st.
        $this->clock = new MockClock('2026-09-20 23:30:00', 'UTC');
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $provision = static::getContainer()->get(ProvisionCompany::class);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        foreach ([$this->company, $this->globex] as $company) {
            $this->em()->persist($company);
            $this->em()->flush();
            $provision->handle($company);
        }
        $this->summarize = new SummarizeInvoices(static::getContainer()->get(InvoiceSummarySource::class), $this->clock, static::getContainer()->get(CurrencyScales::class));
    }

    public function testTheHomeFiguresAreWorkedOutFromIssuedDocumentsOnTheCompanysDay(): void
    {
        $this->draft();
        // Not yet due: 1 000 net, VAT 190 and a levy of 10, issued this month.
        $this->issued('FAC-N', 'Nabeul Bois', '2026-09-15', 30, '1200.000', ['TVA19' => '190.000', 'FODEC' => '10.000']);
        // Due in three days, 200 of its 500 paid this month.
        $soon = $this->issued('FAC-S', 'Sousse Print', '2026-08-25', 30, '500.000');
        $this->pay($soon, '2026-09-02', '200');
        // Ten days late.
        $this->issued('FAC-O1', 'Pharmacie Ennasr', '2026-08-12', 30, '850.050');
        // Forty-two days late.
        $this->issued('FAC-O2', 'Garage Ben Arous', '2026-07-11', 30, '300.000');
        // Eighty-two days late, 190 paid in July and 119 credited by a credit note issued this month (VAT −19).
        $old = $this->issued('FAC-O3', 'Transports Sahel', '2026-06-01', 30, '1190.000', ['TVA19' => '190.000']);
        $this->pay($old, '2026-07-15', '190');
        $this->credit($old, 'AV-1', '2026-09-10', '-119.000', ['TVA19' => '-19.000']);
        // Paid in full across August and September.
        $paid = $this->issued('FAC-P', 'Hôtel Dar Zarrouk', '2026-08-20', 0, '1000.000');
        $this->pay($paid, '2026-08-20', '400');
        $this->pay($paid, '2026-09-05', '600');
        // Another company's documents count nowhere: an overdue invoice, a payment and VAT this month.
        $this->issued('GLX-1', 'Autre', '2026-05-01', 0, '9999.000', company: $this->globex);
        $theirs = $this->issued('GLX-2', 'Autre encore', '2026-09-03', 30, '1190.000', ['TVA19' => '190.000'], $this->globex);
        $this->pay($theirs, '2026-09-04', '500');
        $this->em()->clear();

        $summary = $this->summarize->handle($this->company);

        self::assertSame(['TND', 3, '2026-09-21'], [$summary->currency, $summary->currencyScale, $summary->today]);
        self::assertSame(['3531.050', '1500.000', '2031.050', 3, 82], [$summary->outstanding, $summary->notYetDue, $summary->overdue, $summary->overdueCount, $summary->oldestOverdueDays]);
        self::assertSame([
            ['bucket' => 'not_due', 'amount' => '1500.000', 'count' => 2],
            ['bucket' => 'days_1_15', 'amount' => '850.050', 'count' => 1],
            ['bucket' => 'days_16_30', 'amount' => '0.000', 'count' => 0],
            ['bucket' => 'days_31_45', 'amount' => '300.000', 'count' => 1],
            ['bucket' => 'days_over_45', 'amount' => '881.000', 'count' => 1],
        ], $summary->aging);
        self::assertSame(
            [['FAC-O3', 'Transports Sahel', '2026-07-01', '881.000', 82], ['FAC-O2', 'Garage Ben Arous', '2026-08-10', '300.000', 42], ['FAC-O1', 'Pharmacie Ennasr', '2026-09-11', '850.050', 10], ['FAC-S', 'Sousse Print', '2026-09-24', '300.000', -3]],
            array_map(static fn (array $row): array => [$row['number'], $row['customerName'], $row['dueDate'], $row['amountDue'], $row['daysLate']], $summary->toChase),
        );
        self::assertSame([4, '2331.050'], [$summary->toChaseCount, $summary->toChaseAmount]);
        self::assertSame([
            ['month' => '2026-04', 'amount' => '0.000'],
            ['month' => '2026-05', 'amount' => '0.000'],
            ['month' => '2026-06', 'amount' => '0.000'],
            ['month' => '2026-07', 'amount' => '190.000'],
            ['month' => '2026-08', 'amount' => '400.000'],
            ['month' => '2026-09', 'amount' => '800.000'],
        ], $summary->collected);
        // Each tax named as the company names it, which already says its rate; its code belongs to the tax settings.
        $vat = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $this->company->getId())?->getName();
        self::assertNotNull($vat);
        self::assertSame([[['code' => 'TVA19', 'rate' => '19.000', 'name' => $vat, 'amount' => '171.000']], '171.000'], [$summary->vat, $summary->vatTotal]);
    }

    public function testAnInvoiceDueTodayIsNotLateAndOneDueInAWeekIsToChase(): void
    {
        $this->issued('FAC-T', 'Aujourd’hui', '2026-08-22', 30, '100.000');
        $this->issued('FAC-W', 'Semaine', '2026-08-29', 30, '200.000');
        $this->issued('FAC-L', 'Plus tard', '2026-08-30', 30, '400.000');

        $summary = $this->summarize->handle($this->company);

        self::assertSame(['0.000', 0, null], [$summary->overdue, $summary->overdueCount, $summary->oldestOverdueDays]);
        self::assertSame([['FAC-T', 0], ['FAC-W', -7]], array_map(static fn (array $row): array => [$row['number'], $row['daysLate']], $summary->toChase));
    }

    public function testOnlyFourAreShownToChaseTheMostLateFirstAndTheCountAndSumAreAllOfThem(): void
    {
        // Two due the same day are told apart by their number, byte by byte: an upper case sorts before a lower one.
        foreach (['FAC-b' => '2026-08-01', 'FAC-C' => '2026-08-01', 'FAC-A' => '2026-08-05', 'FAC-D' => '2026-08-10', 'FAC-E' => '2026-08-15'] as $number => $day) {
            $this->issued($number, 'Client '.$number, $day, 0, '10.000');
        }

        $summary = $this->summarize->handle($this->company);

        self::assertSame(['FAC-C', 'FAC-b', 'FAC-A', 'FAC-D'], array_column($summary->toChase, 'number'));
        self::assertSame([5, '50.000'], [$summary->toChaseCount, $summary->toChaseAmount]);
    }

    public function testACompanyWithoutInvoicesReadsZeros(): void
    {
        $summary = $this->summarize->handle($this->company);

        self::assertSame(['0.000', '0.000', '0.000', 0, null, [], 0, '0.000', [], '0.000'], [$summary->outstanding, $summary->notYetDue, $summary->overdue, $summary->overdueCount, $summary->oldestOverdueDays, $summary->toChase, $summary->toChaseCount, $summary->toChaseAmount, $summary->vat, $summary->vatTotal]);
        self::assertSame(['2026-04', '2026-09', '0.000'], [$summary->collected[0]['month'], $summary->collected[5]['month'], $summary->collected[5]['amount']]);
    }

    public function testTheMonthSoFarIsInvoicedAndCollectedAgainstTheSameDaysOfLastMonthAndTheMarginReadsTheFrozenCosts(): void
    {
        // Today is the 21st: this month is 1–21 September, the days to compare with are 1–21 August.
        $now = $this->issued('FAC-N', 'Nabeul Bois', '2026-09-15', 30, '1200.000');
        $this->costed($now, '700');
        $this->issued('FAC-X', 'Sans coût', '2026-09-16', 30, '300.000');
        $old = $this->issued('FAC-O3', 'Transports Sahel', '2026-06-01', 30, '1190.000');
        $credit = $this->credit($old, 'AV-1', '2026-09-10', '-119.000', []);
        $this->costed($credit, '60');
        // Last month's first 21 days: one with a cost, one without; the 25th is outside the comparison.
        $lastMonth = $this->issued('FAC-O1', 'Pharmacie Ennasr', '2026-08-12', 30, '850.050');
        $this->costed($lastMonth, '800');
        $this->pay($lastMonth, '2026-08-22', '50');
        $paid = $this->issued('FAC-P', 'Hôtel Dar Zarrouk', '2026-08-20', 0, '1000.000');
        $this->pay($paid, '2026-08-20', '400');
        $this->pay($paid, '2026-09-05', '600');
        $this->issued('FAC-S', 'Sousse Print', '2026-08-25', 30, '500.000');
        $soon = $this->issued('FAC-S2', 'Sousse Deux', '2026-09-02', 30, '500.000');
        $this->pay($soon, '2026-09-02', '200');
        $this->issued('GLX-1', 'Autre', '2026-09-03', 0, '9999.000', company: $this->globex);
        $this->em()->clear();

        $summary = $this->summarize->handle($this->company, true);

        // Invoiced: 1200 + 300 + 500 − 119 this month; 850.050 + 1000 last month (the 25th is not yet reached).
        self::assertSame(['1881.000', '1850.050'], [$summary->invoicedMonth, $summary->invoicedLastMonth]);
        // Collected: 600 + 200 this month, 400 last month (the payment of 22 August is past the days compared).
        self::assertSame(['800.000', '400.000'], [$summary->collectedMonth, $summary->collectedLastMonth]);
        // Margin over the lines that have a cost: (1200 − 700) + (−119 − (−60)) this month, 850.050 − 800 last month.
        self::assertSame(['441.000', '50.050', '1081.000'], [$summary->margin, $summary->marginLastMonth, $summary->marginBasis]);
    }

    public function testWhatIsInvoicedAndItsMarginComeAfterTheDocumentDiscountWhicheverWayPricesWereTyped(): void
    {
        // Tax-exclusive, a line keeps its figure before the document's discount: 2150 less 1125 is 1025 invoiced.
        $exclusive = $this->issuedAt('FAC-HT', '2026-09-15', new InvoiceFigures('2150.000', '1125.000', '1025.000', [], '0.000', [], '1025.000', [], '0.000', '1025.000', [['net' => '2150.000', 'tax' => '0.000', 'gross' => '2150.000']]));
        $this->costed($exclusive, '500');
        // Tax-inclusive, a line's net already comes after it: taking the discount off again would count it twice.
        $inclusive = $this->issuedAt('FAC-TTC', '2026-09-16', new InvoiceFigures('1000.000', '100.000', '1000.000', [], '0.000', [], '1000.000', [], '0.000', '1000.000', [['net' => '1000.000', 'tax' => '0.000', 'gross' => '1000.000']]));
        $this->costed($inclusive, '600');
        $this->em()->clear();

        $summary = $this->summarize->handle($this->company, true);

        self::assertSame('2025.000', $summary->invoicedMonth);
        self::assertSame(['925.000', null, '2025.000'], $this->marginOf($summary), '(1025 − 500) + (1000 − 600), over 1025 + 1000');
    }

    public function testCollectedIsMoneyReceivedOnlyCreditAppliedIsNotAndDepositsAndRefundsCountOnTheirOwnDay(): void
    {
        // Audit E-14: 100 paid in cash in August, sent to the balance by a credit note, then applied to another invoice
        // in September was counted collected twice. In September money only moves through a deposit and a refund.
        $first = $this->issued('FAC-A', 'Nabeul Bois', '2026-08-05', 0, '100.000');
        $this->pay($first, '2026-08-10', '100');
        $customer = $first->getCustomer();
        $credits = static::getContainer()->get(CustomerCreditRepository::class);
        $credits->save(CustomerCreditEntry::credited($customer, $first, 'AV-A', '100.000', new \DateTimeImmutable('2026-08-15'), null, $this->clock->now()));
        $second = $this->issued('FAC-B', 'Nabeul Bois bis', '2026-09-03', 0, '100.000');
        $applied = $this->pay($second, '2026-09-04', '100', PaymentMethod::Other);
        $credits->save(CustomerCreditEntry::applied($customer, $applied, null, $this->clock->now()));
        $credits->save(CustomerCreditEntry::deposit($customer, new PaymentDetails(new \DateTimeImmutable('2026-09-06'), '50.000', PaymentMethod::Cash), null, $this->clock->now()));
        $credits->save(CustomerCreditEntry::credited($customer, $second, 'AV-B', '30.000', new \DateTimeImmutable('2026-09-08'), null, $this->clock->now()));
        $credits->save(CustomerCreditEntry::refunded($customer, $second, 'AV-B', '30.000', new \DateTimeImmutable('2026-09-08'), null, $this->clock->now()));
        $other = $this->issued('GLX-1', 'Autre', '2026-09-03', 0, '100.000', company: $this->globex);
        $credits->save(CustomerCreditEntry::deposit($other->getCustomer(), new PaymentDetails(new \DateTimeImmutable('2026-09-06'), '999.000', PaymentMethod::Cash), null, $this->clock->now()));
        $this->em()->clear();

        $summary = $this->summarize->handle($this->company);

        self::assertSame(['20.000', '100.000'], [$summary->collectedMonth, $summary->collectedLastMonth], '50 deposited less 30 refunded; the 100 of credit came in once, in August');
        self::assertSame([['month' => '2026-08', 'amount' => '100.000'], ['month' => '2026-09', 'amount' => '20.000']], \array_slice($summary->collected, 4));
    }

    public function testTheWithholdingSufferedIsWhatTheIssuedDocumentsKeptBackThisMonthAgainstTheSameDaysOfLastMonth(): void
    {
        $this->issued('FAC-W1', 'Nabeul Bois', '2026-09-10', 30, '1000.000', withholding: '10.000');
        $this->issued('FAC-W2', 'Sans retenue', '2026-09-11', 30, '500.000');
        $old = $this->issued('FAC-W3', 'Transports Sahel', '2026-06-01', 30, '1000.000', withholding: '10.000');
        $this->credit($old, 'AV-W', '2026-09-12', '-200.000', [], withholding: '-2.000');
        $this->issued('FAC-W4', 'Pharmacie Ennasr', '2026-08-12', 30, '800.000', withholding: '8.000');
        // The 25th is past the days compared.
        $this->issued('FAC-W5', 'Sousse Print', '2026-08-25', 30, '900.000', withholding: '9.000');
        $this->issued('GLX-W', 'Autre', '2026-09-03', 0, '9999.000', company: $this->globex, withholding: '99.000');
        $this->em()->clear();

        $summary = $this->summarize->handle($this->company);

        self::assertSame(['8.000', '8.000'], [$summary->withheldMonth, $summary->withheldLastMonth]);
    }

    public function testTheMarginIsWithheldWithoutTheCostPermissionAndBlankUntilALineHasACost(): void
    {
        $this->issued('FAC-N', 'Nabeul Bois', '2026-09-15', 30, '1200.000');
        self::assertSame([null, null, null], $this->marginOf($this->summarize->handle($this->company, true)));

        $this->costed($this->issued('FAC-C', 'Avec coût', '2026-09-16', 30, '100.000'), '40');

        self::assertSame(['60.000', null, '100.000'], $this->marginOf($this->summarize->handle($this->company, true)));
        $withheld = $this->summarize->handle($this->company, false);
        self::assertSame([null, null, null, false], [...$this->marginOf($withheld), $withheld->costsVisible]);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function marginOf(\App\Module\Invoices\Application\InvoiceSummary $summary): array
    {
        return [$summary->margin, $summary->marginLastMonth, $summary->marginBasis];
    }

    private function draft(): void
    {
        $invoice = Invoice::create($this->company, $this->establishment($this->company), $this->customer($this->company, 'Brouillon'), new InvoiceHeader(), [$this->line($this->company, [])], [], $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);
    }

    /** @param array<string, string> $taxes the line taxes charged, by code */
    private function issued(string $number, string $customer, string $day, int $terms, string $total, array $taxes = [], ?Company $company = null, string $withholding = '0.000'): Invoice
    {
        $company ??= $this->company;
        $invoice = Invoice::create($company, $this->establishment($company), $this->customer($company, $customer), new InvoiceHeader(), [$this->line($company, array_keys($taxes))], [], $this->clock->now());
        $invoice->issue(new InvoiceIssue($number, new \DateTimeImmutable($day), $terms, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto')), fn (): InvoiceFigures => $this->figures($total, $taxes, $withholding), $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);

        return $invoice;
    }

    private function issuedAt(string $number, string $day, InvoiceFigures $figures): Invoice
    {
        $invoice = Invoice::create($this->company, $this->establishment($this->company), $this->customer($this->company, $number), new InvoiceHeader(), [$this->line($this->company, [])], [], $this->clock->now());
        $invoice->issue(new InvoiceIssue($number, new \DateTimeImmutable($day), 30, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto')), static fn (): InvoiceFigures => $figures, $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);

        return $invoice;
    }

    /** @param array<string, string> $taxes */
    private function credit(Invoice $invoice, string $number, string $day, string $total, array $taxes, string $withholding = '0.000'): Invoice
    {
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->clock->now());
        $credit->issue(new InvoiceIssue($number, new \DateTimeImmutable($day), 0, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto')), fn (): InvoiceFigures => $this->figures($total, $taxes, $withholding), $this->clock->now());
        $invoice->credit($credit, $this->clock->now());
        $invoices = static::getContainer()->get(InvoiceRepository::class);
        $invoices->save($credit);
        $invoices->save($invoice);

        return $credit;
    }

    /** What one unit cost when the line was issued: frozen at issue from the product, set here since these lines have none. */
    private function costed(Invoice $invoice, string $unitCost): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE invoice_line SET unit_cost = :cost WHERE invoice_id = :invoice', ['cost' => $unitCost, 'invoice' => $invoice->getId()->toRfc4122()]);
    }

    private function pay(Invoice $invoice, string $day, string $amount, PaymentMethod $method = PaymentMethod::Transfer): Payment
    {
        $payment = $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable($day), $amount, $method), new \DateTimeImmutable('2026-09-21'), 3, null, $this->clock->now());
        static::getContainer()->get(InvoiceRepository::class)->save($invoice);

        return $payment;
    }

    /** @param array<string, string> $taxes */
    private function figures(string $total, array $taxes, string $withholding = '0.000'): InvoiceFigures
    {
        $rated = [];
        foreach ($taxes as $code => $amount) {
            $rated[] = ['code' => $code, 'rate' => 'TVA19' === $code ? '19.000' : '1.000', 'base' => '0.000', 'amount' => $amount];
        }

        return new InvoiceFigures($total, '0.000', $total, $rated, '0.000', [], $total, [], $withholding, Decimal::format(Decimal::of($total)->sub(Decimal::of($withholding)), 3), [['net' => $total, 'tax' => '0.000', 'gross' => $total]]);
    }

    private function customer(Company $company, string $name): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($company, 'CLI-'.substr(md5($name), 0, 8), new CustomerProfile(CustomerKind::Company, $name), null, $regime, [], $this->clock->now());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function establishment(Company $company): \App\Tenancy\Domain\Establishment
    {
        return static::getContainer()->get(EstablishmentRepository::class)->ofCompany($company->getId())[0];
    }

    /** @param list<string> $taxCodes */
    private function line(Company $company, array $taxCodes): InvoiceLineDetails
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $components = static::getContainer()->get(TaxComponentRepository::class);
        $taxes = array_map(static fn (string $code): TaxComponent => $components->ofCodeInCompany($code, $company->getId()) ?? throw new \LogicException($code), $taxCodes);

        return new InvoiceLineDetails(null, 'Pièce', '1', $unit, '1', null, $taxes);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Expenses\Application\Tej;

use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Expenses\Application\Tej\DeclareTejWithholdings;
use App\Module\Expenses\Application\Tej\TejDeclarationRefused;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseCategory;
use App\Module\Expenses\Domain\ExpenseDetails;
use App\Module\Expenses\Domain\ExpenseRepository;
use App\Module\Expenses\Domain\ExpenseSearch;
use App\Module\Expenses\Domain\ExpenseStatus;
use App\Module\Expenses\Domain\TejOperationCode;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Domain\PaymentMethod;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The month's TEJ declaration (docs/research/tax-data-tunisia.md § 2.2, cahier des charges TEJ of September 2026): one
 * certificate per withholding payment of the month, amounts in millimes, and nothing emitted while a payment lacks
 * what the platform asks for.
 */
final class DeclareTejWithholdingsTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Company $acme;
    private TaxComponent $vat19;
    private ExpenseCategory $purchases;
    private Vendor $sotumag;
    /** @var list<Expense> */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-20 09:00:00');
        $this->acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->acme->reviseProfile(new CompanyProfile(legalName: 'Acme SARL', identifiers: ['matricule_fiscal' => '1234567A/B/M/000']));
        $this->vat19 = TaxComponent::create($this->acme, 'TVA19', 'TVA19', TaxFamily::Vat, '19', null, null, false, false, null, 0, 3, $this->now);
        $this->purchases = ExpenseCategory::create($this->acme, 'Achats', null, $this->now);
        $this->sotumag = Vendor::create($this->acme, 'FRN-0001', new VendorProfile(
            'Sotumag',
            legalName: 'Société Tunisienne de Matériel & Gros',
            identifiers: ['matricule_fiscal' => '7654321B/A/M/000'],
            email: 'achats@sotumag.tn',
            phone: '+216 71 000 000',
            address: new PostalAddress('4, rue de Marseille', null, '1000', 'Tunis', 'TN'),
        ), $this->now);
    }

    public function testEachWithholdingPaymentOfTheMonthIsACertificateInMillimes(): void
    {
        $bought = $this->paid('1037.451', '2026-09-12', '1.5', TejOperationCode::Rs7_000001, '2026-08-30');
        $exempt = $this->paid('2000', '2026-09-03', '0', TejOperationCode::Rs7_000006);
        $this->paid('1500', '2026-08-31', '1', TejOperationCode::Rs7_000001, '2026-08-01');
        $this->paid('1500', '2026-10-01', '1', TejOperationCode::Rs7_000001, '2026-09-30', today: '2026-10-01');
        $this->paid('100', '2026-09-05', '0');

        $declaration = $this->declare(2026, 9);

        self::assertSame(['1234567A', 'PM', 2026, 9, '1234567A-2026-09-0.xml'], [$declaration->declarant, $declaration->declarantCategory, $declaration->year, $declaration->month, $declaration->fileName()]);
        self::assertSame([$exempt->getId()->toRfc4122(), $bought->getId()->toRfc4122()], array_map(static fn ($certificate) => $certificate->reference, $declaration->certificates), 'this month\'s payments by day; neither last month\'s, next month\'s, nor one nobody declares');

        $certificate = $declaration->certificates[1];
        self::assertSame(['7654321B', 'PM', 'Société Tunisienne de Matériel & Gros', '4, rue de Marseille, 1000 Tunis', 'achats@sotumag.tn', '+216 71 000 000'], [$certificate->beneficiary, $certificate->beneficiaryCategory, $certificate->beneficiaryName, $certificate->beneficiaryAddress, $certificate->beneficiaryEmail, $certificate->beneficiaryPhone]);
        self::assertSame('2026-09-12', $certificate->paidOn->format('Y-m-d'));
        $operation = $certificate->operations[0];
        // 1037.451 net, 19 % is 197.11569 → 197.116, gross 1234.567, 1.5 % of it 18.518505 → 18.519, handed 1216.048.
        self::assertSame(
            [TejOperationCode::Rs7_000001, 2026, 1037451, '1.5', '19', 197116, 1234567, 18519, 1216048],
            [$operation->code, $operation->invoiceYear, $operation->amountNet, $operation->withholdingRate, $operation->vatRate, $operation->vatAmount, $operation->amountGross, $operation->withheld, $operation->netPaid],
        );
        self::assertSame(2026, $operation->invoiceYear, 'the year of the supplier\'s invoice, not of the payment');

        $zero = $declaration->certificates[0]->operations[0];
        self::assertSame([TejOperationCode::Rs7_000006, '0', 0, 2380000, 2380000], [$zero->code, $zero->withholdingRate, $zero->withheld, $zero->amountGross, $zero->netPaid], 'an exempt supplier is declared at 0 %');
    }

    public function testTheInvoiceYearIsTheExpensesOwn(): void
    {
        $this->paid('1500', '2026-09-02', '1', TejOperationCode::Rs7_000002, '2025-12-30');

        self::assertSame(2025, $this->declare(2026, 9)->certificates[0]->operations[0]->invoiceYear);
    }

    public function testAPaymentMissingWhatThePlatformAsksForStopsTheWholeMonthAndIsNamed(): void
    {
        $this->paid('1500', '2026-09-02', '1', TejOperationCode::Rs7_000002);
        $uncoded = $this->paid('1500', '2026-09-03', '1');
        $anonymous = $this->paid('1500', '2026-09-04', '1', TejOperationCode::Rs7_000001, vendor: false);
        $bare = Vendor::create($this->acme, 'FRN-0002', new VendorProfile('Kiosque', email: 'pas-un-courriel'), $this->now);
        $unreachable = $this->paid('1500', '2026-09-05', '1.125', TejOperationCode::Rs7_000001, vendor: $bare);

        $refused = $this->refused(2026, 9);

        self::assertSame(['incomplete_expenses', ['count' => 3]], [$refused->reason, $refused->params]);
        self::assertSame([
            $uncoded->getId()->toRfc4122() => ['operation_code_missing'],
            $anonymous->getId()->toRfc4122() => ['vendor_missing'],
            $unreachable->getId()->toRfc4122() => ['vendor_matricule_missing', 'vendor_address_missing', 'vendor_email_unaccepted', 'vendor_phone_missing', 'withholding_rate_too_precise'],
        ], array_column($refused->expenses, 'problems', 'expenseId'));
        self::assertSame(['expenseId', 'paidOn', 'description', 'reference', 'vendorName', 'problems'], array_keys($refused->expenses[0]));
    }

    public function testAVendorWhoseMatriculeNamesNoKnownCategoryIsNamed(): void
    {
        $odd = Vendor::create($this->acme, 'FRN-0003', new VendorProfile('Odd', identifiers: ['matricule_fiscal' => '7654321B/A/X/000'], email: 'a@b.tn', phone: '1', address: new PostalAddress('1 rue', null, null, 'Tunis')), $this->now);
        $expense = $this->paid('1500', '2026-09-02', '1', TejOperationCode::Rs7_000002, vendor: $odd);

        self::assertSame([$expense->getId()->toRfc4122() => ['vendor_category_unknown']], array_column($this->refused(2026, 9)->expenses, 'problems', 'expenseId'));
    }

    public function testACompanyWithoutItsMatriculeOrOutsideTunisiaOrWithNothingToDeclareIsRefused(): void
    {
        self::assertSame(['nothing_to_declare', ['year' => 2026, 'month' => 9]], [($r = $this->refused(2026, 9))->reason, $r->params]);

        $this->paid('1500', '2026-09-02', '1', TejOperationCode::Rs7_000002);
        $this->acme->reviseProfile(new CompanyProfile(legalName: 'Acme SARL'));
        self::assertSame('company_matricule_missing', $this->refused(2026, 9)->reason);
        $this->acme->reviseProfile(new CompanyProfile(legalName: 'Acme SARL', identifiers: ['matricule_fiscal' => '1234567A/B/Q/000']));
        self::assertSame('company_matricule_unreadable', $this->refused(2026, 9)->reason);

        $french = new Company('Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        try {
            (new DeclareTejWithholdings($this->repository()))->declare($french, 2026, 9);
            self::fail('a French company was declared to TEJ');
        } catch (TejDeclarationRefused $refused) {
            self::assertSame(['not_declared_to_tej', ['preset' => 'FR']], [$refused->reason, $refused->params]);
        }
    }

    private function paid(string $net, string $paidOn, string $rate, ?TejOperationCode $code = null, string $date = '2026-09-01', string $today = '2026-09-20', Vendor|bool $vendor = true): Expense
    {
        $from = true === $vendor ? $this->sotumag : (false === $vendor ? null : $vendor);
        $expense = Expense::create($this->acme, new ExpenseDetails(new \DateTimeImmutable($date), 'Achat '.$net, $net, 'F-'.$net), $from, $this->purchases, $this->vat19, 3, $this->now);
        $expense->record($this->now);
        $expense->pay(PaymentMethod::Transfer, new \DateTimeImmutable($paidOn), new \DateTimeImmutable($today), $this->now, $rate, 3, $code);
        self::assertSame(ExpenseStatus::Paid, $expense->getStatus());
        $this->stored[] = $expense;

        return $expense;
    }

    private function declare(int $year, int $month): \App\Module\Expenses\Application\Tej\TejDeclaration
    {
        return (new DeclareTejWithholdings($this->repository()))->declare($this->acme, $year, $month);
    }

    private function refused(int $year, int $month): TejDeclarationRefused
    {
        try {
            $this->declare($year, $month);
        } catch (TejDeclarationRefused $refused) {
            return $refused;
        }
        self::fail('the declaration was not refused');
    }

    private function repository(): ExpenseRepository
    {
        $stored = &$this->stored;

        return new class($stored) implements ExpenseRepository {
            /** @param list<Expense> $stored */
            public function __construct(private array &$stored)
            {
            }

            public function ofCompany(Uuid $companyId): array
            {
                return array_values(array_filter($this->stored, static fn (Expense $e): bool => $e->getCompany()->getId()->equals($companyId)));
            }

            public function paidBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
            {
                $paid = array_values(array_filter($this->ofCompany($companyId), static fn (Expense $e): bool => null !== $e->getPaidOn() && $e->getPaidOn() >= $from && $e->getPaidOn() < $until));
                usort($paid, static fn (Expense $a, Expense $b): int => [$a->getPaidOn(), $a->getCreatedAt()] <=> [$b->getPaidOn(), $b->getCreatedAt()]);

                return $paid;
            }

            public function search(Uuid $companyId, ExpenseSearch $search, PageRequest $page): Page
            {
                throw new \LogicException('not used');
            }

            public function statusCounts(Uuid $companyId, ExpenseSearch $search): array
            {
                throw new \LogicException('not used');
            }

            public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Expense
            {
                return null;
            }

            public function save(Expense $expense): void
            {
            }

            public function remove(Expense $expense): void
            {
            }
        };
    }
}

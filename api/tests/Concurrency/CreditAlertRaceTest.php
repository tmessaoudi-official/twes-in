<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Application\Establishment\EstablishmentDetails;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\IssueInvoiceAndLinger;
use App\Tests\Concurrency\Support\Steps\SellDocument;
use Symfony\Component\HttpFoundation\Response;

/**
 * A credit limit only warns, but it warns once: two invoices of one customer issued at the same moment tell those who
 * issue invoices exactly once that the account crossed its limit, whether the first of them crosses it (nothing owed,
 * a limit below one invoice) or the second does (a limit between one invoice and two). Decided from what was owed
 * once the other invoice committed: read after both commits, the first case tells nobody and the second tells twice.
 * Each invoice is worth one unit of the product, between 200 and 400 once issued.
 */
final class CreditAlertRaceTest extends SaleRaceTestCase
{
    protected function saleWithoutStock(): string
    {
        return 'allow';
    }

    protected function onHand(): string
    {
        return '10';
    }

    /** One series: the series' lock lines the issues up, and the alerts run after both commits. */
    public function testTwoInvoicesOfOneSeriesTheFirstOfWhichCrossesTheLimitTellItOnce(): void
    {
        $this->raceTwoInvoices('200', false);
    }

    public function testTwoInvoicesOfOneSeriesTheSecondOfWhichCrossesTheLimitTellItOnce(): void
    {
        $this->raceTwoInvoices('400', false);
    }

    /** Two establishments, two series: nothing but the customer's account lines the two issues up. */
    public function testTwoInvoicesOfTwoSeriesTheFirstOfWhichCrossesTheLimitTellItOnce(): void
    {
        $this->raceTwoInvoices('200', true);
    }

    public function testTwoInvoicesOfTwoSeriesTheSecondOfWhichCrossesTheLimitTellItOnce(): void
    {
        $this->raceTwoInvoices('400', true);
    }

    private function raceTwoInvoices(string $limit, bool $twoSeries): void
    {
        $customerId = $this->customer();
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($company), 'credit.limit', SettingLevel::Company, $limit, null);
        // Across two series the second invoice sells another product: one product's stock lock would line the two up
        // and hide what the account's hold does.
        $documents = [$this->draft('invoices', $customerId), $twoSeries ? $this->draftAt($customerId, $this->branch(), $this->secondProduct()) : $this->draft('invoices', $customerId)];

        // Across two series each issue stays open a moment after it decides, so the other one decides while it is still
        // uncommitted every time: only a hold on the account makes the second wait and read the first.
        $outcomes = $twoSeries
            ? Race::run(IssueInvoiceAndLinger::class, 2, ['company' => $this->company->getId()->toRfc4122(), 'documents' => $documents, 'actor' => $this->sellerId, 'lingerMs' => 400])
            : Race::run(SellDocument::class, 2, ['kinds' => ['invoice', 'invoice'], 'company' => $this->company->getId()->toRfc4122(), 'documents' => $documents, 'actor' => $this->sellerId]);

        $described = implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes));
        self::assertSame([Outcome::OK, Outcome::OK], array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes), $described);
        foreach ($this->amountsDue($documents) as $due) {
            self::assertTrue(Decimal::of($due)->compare(200) > 0 && Decimal::of($due)->compare(400) < 0, "an invoice worth $due, not between 200 and 400, proves nothing here");
        }
        self::assertSame(1, $this->told(), "the crossing of a limit of $limit is told exactly once\n$described");
    }

    /** A second establishment, whose invoices take a series of their own. */
    private function branch(): string
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        $branch = static::getContainer()->get(ManageEstablishments::class)->create($company, new EstablishmentDetails('001', 'Agence de Sfax', null, null, null, 'Sfax', null, null, false), null);

        return $branch->getId()->toRfc4122();
    }

    /** Another product at the first one's price, with no stock: « Sortie sans stock enregistré » allows its sale. */
    private function secondProduct(): string
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($piece);
        $product = Product::create($company, 'ART-002', new ProductDetails('Visseuse', null, ProductKind::Goods, '250'), $piece, null, [], self::heldNow());
        $this->em()->persist($product);
        $this->em()->flush();

        return $product->getId()->toRfc4122();
    }

    private function draftAt(string $customerId, string $establishmentId, string $productId): string
    {
        $this->postJson($this->path('invoices'), ['customerId' => $customerId, 'establishmentId' => $establishmentId, 'customerReference' => null, 'notesInternal' => null, 'lines' => [['productId' => $productId, 'quantity' => '1']], 'supplyDate' => null, 'paymentTermsDays' => null, 'notesPrinted' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /**
     * @param list<string> $invoices
     *
     * @return list<string>
     */
    private function amountsDue(array $invoices): array
    {
        return array_map(fn (string $id): string => Rows::text($this->em()->getConnection()->fetchOne('SELECT total_gross - withholding_amount FROM invoice WHERE id = ?', [$id])), $invoices);
    }

    private function told(): int
    {
        return Rows::int($this->em()->getConnection()->fetchOne(
            "SELECT count(*) FROM inbox_item WHERE type = 'invoice.credit_limit_passed' AND recipient_id = ?",
            [$this->sellerId],
        ));
    }
}

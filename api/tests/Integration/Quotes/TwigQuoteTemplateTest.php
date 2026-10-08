<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Quotes;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\Quotes\Application\QuotePage;
use App\Module\Quotes\Application\QuoteTemplate;
use App\Module\Quotes\Application\QuoteTotals;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteHeader;
use App\Module\Quotes\Domain\QuoteLineDetails;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\SellerSnapshot;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** The quote layout against a real draft: what a line was given off is printed as it was given. No database, no renderer. */
final class TwigQuoteTemplateTest extends KernelTestCase
{
    public function testALineDiscountIsPrintedAsItWasGivenAsARateOrAnAmount(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-07 09:00:00');
        $units = new InMemoryUnits();
        $taxes = new InMemoryTaxComponents();
        $establishments = new InMemoryEstablishments();
        $company = new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        new ProvisionCompany(ShippedFiscalPresets::presets(), $taxes, $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), new MockClock($now))->handle($company);
        $customer = Customer::create($company, 'CLI-0007', new CustomerProfile(CustomerKind::Company, 'Garage Martin'), null, new CustomerTaxRegime('FR', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $unit = $units->ofCodeInCompany('C62', $company->getId());
        $vat = $taxes->ofCodeInCompany('TVA20', $company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($vat);
        $quote = Quote::create($company, $establishments->ofCompany($company->getId())[0], $customer, new QuoteHeader(), [
            new QuoteLineDetails(null, 'Réglage', '2', $unit, '150', '10', [$vat]),
            new QuoteLineDetails(null, 'Pose', '1', $unit, '80', null, [$vat], discountAmount: '12.5'),
        ], $now);
        $totals = new QuoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());

        $html = static::getContainer()->get(QuoteTemplate::class)->html(new QuotePage(
            $quote,
            $totals->of($quote),
            CustomerSnapshot::of($customer),
            SellerSnapshot::of($company, $quote->getEstablishment()),
            QuotePage::DRAFT,
            'fr',
            '',
            false,
        ));

        self::assertMatchesRegularExpression('#<td class="number">10\s%</td>#u', $html, 'a rate with its sign');
        self::assertMatchesRegularExpression('#<td class="number">12,50</td>#u', $html, 'an amount as money is written');
        self::assertStringNotContainsString('data-testid="savings"', $html, 'only when the company asks');

        $html = static::getContainer()->get(QuoteTemplate::class)->html(new QuotePage(
            $quote,
            $totals->of($quote),
            CustomerSnapshot::of($customer),
            SellerSnapshot::of($company, $quote->getEstablishment()),
            QuotePage::DRAFT,
            'fr',
            '',
            false,
            savings: '42.500',
            quantities: [new QuantityTotal('pièce', 0, '3')],
        ));
        self::assertMatchesRegularExpression('#<p data-testid="savings">Vous économisez 42,50 HT grâce aux remises\.</p>#u', $html);
        self::assertMatchesRegularExpression('#data-testid="quantities">Quantités\x{00A0}: 3 pièce</p>#u', $html);
    }
}

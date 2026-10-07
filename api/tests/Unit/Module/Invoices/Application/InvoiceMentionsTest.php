<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\InvoiceMentions;
use App\Module\Invoices\Application\MentionDatumMissing;
use App\Module\Invoices\Application\PrintedMentions;
use App\Module\Invoices\Domain\InvoiceType;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\ShippedFiscalPresets;
use App\Tests\Support\ShippedMentionWording;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Which mentions a document prints and what fills them (docs/SPEC.md § 7, 2026-09-21 18:30): issuing refuses a mention
 * it cannot fill, naming the setting to give; a draft prints what can be filled and leaves the rest out.
 */
final class InvoiceMentionsTest extends TestCase
{
    private const string LATE = 'fiscal.mention.fr.late_payment';
    private const string EXEMPT = 'fiscal.mention.fr.exempt';
    private const array FR_ALWAYS = ['fiscal.mention.fr.recovery_indemnity', 'fiscal.mention.fr.no_early_discount'];

    private MockClock $clock;
    private ChangeSettings $change;
    private InvoiceMentions $mentions;
    private Company $atelier;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-07 09:00:00', 'UTC');
        $settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings()]);
        $resolve = new ResolveSettings($catalog, $settings);
        $this->change = new ChangeSettings($catalog, $settings, $resolve, new InMemoryAuditTrail($transactions = new FakeTransactions()), $this->clock, $transactions);
        $presets = ShippedFiscalPresets::presets();
        $this->mentions = new InvoiceMentions($presets, new ExcludedTaxFamilies($presets), ShippedMentionWording::wording(), new ReadSetting($resolve));
        $this->atelier = new Company('Atelier', 'FR', 'EUR', 'fr', 'Europe/Paris');
    }

    public function testAFrenchInvoiceWithoutItsLatePaymentRateIsRefusedNamingTheSetting(): void
    {
        $customer = $this->customer($this->atelier, 'standard', null);

        $refused = $this->refused(fn () => $this->mentions->forIssue($this->atelier, $customer, InvoiceType::Invoice, 'fr'));

        self::assertSame([self::LATE, 'document.late_payment_rate'], [$refused->mention, $refused->datum]);
    }

    public function testTheRateTheCustomersChainGivesFillsTheMentionAndIsKeptWithIt(): void
    {
        $customer = $this->customer($this->atelier, 'standard', null);
        $this->change->change(new SettingContext($this->atelier), 'document.late_payment_rate', SettingLevel::Company, 'trois fois le taux d’intérêt légal', null);
        $this->change->change(new SettingContext($this->atelier, customerId: $customer->getId()), 'document.late_payment_rate', SettingLevel::Customer, '  12 %  ', null);

        $printed = $this->mentions->forIssue($this->atelier, $customer, InvoiceType::Invoice, 'fr');

        self::assertEquals(new PrintedMentions([self::LATE, ...self::FR_ALWAYS], [self::LATE => ['rate' => '12 %']]), $printed);
    }

    public function testTheCompanysOwnLatePenaltyTextStandsForTheMentionAndIsNotPrintedTwice(): void
    {
        $this->atelier->reviseProfile(new CompanyProfile(latePenaltyText: 'Pénalités : trois fois le taux légal.'));
        $customer = $this->customer($this->atelier, 'standard', null);

        $printed = $this->mentions->forIssue($this->atelier, $customer, InvoiceType::Invoice, 'fr');

        self::assertEquals(new PrintedMentions(self::FR_ALWAYS), $printed);
    }

    public function testACreditNoteAsksForNoPaymentSoNeedsNoRate(): void
    {
        $customer = $this->customer($this->atelier, 'standard', null);

        self::assertEquals(new PrintedMentions(self::FR_ALWAYS), $this->mentions->forIssue($this->atelier, $customer, InvoiceType::CreditNote, 'fr'));
    }

    public function testAnExemptCustomerNeedsTheProvisionItIsExemptUnderOnEitherDocument(): void
    {
        $this->change->change(new SettingContext($this->atelier), 'document.late_payment_rate', SettingLevel::Company, '12 %', null);
        $customer = $this->customer($this->atelier, 'exempt', self::EXEMPT);

        foreach ([InvoiceType::Invoice, InvoiceType::CreditNote] as $type) {
            $refused = $this->refused(fn () => $this->mentions->forIssue($this->atelier, $customer, $type, 'en'));
            self::assertSame([self::EXEMPT, 'document.exemption_reference'], [$refused->mention, $refused->datum], $type->value);
        }

        $this->change->change(new SettingContext($this->atelier, customerId: $customer->getId()), 'document.exemption_reference', SettingLevel::Customer, 'article 261-4-4° du CGI', null);

        self::assertEquals(
            new PrintedMentions([self::EXEMPT, ...self::FR_ALWAYS], [self::EXEMPT => ['reference' => 'article 261-4-4° du CGI']]),
            $this->mentions->forIssue($this->atelier, $customer, InvoiceType::CreditNote, 'en'),
        );
    }

    public function testADraftPrintsWhatCanBeFilledAndLeavesTheRestOutWithoutRefusing(): void
    {
        $customer = $this->customer($this->atelier, 'exempt', self::EXEMPT);
        $this->change->change(new SettingContext($this->atelier), 'document.exemption_reference', SettingLevel::Company, 'article 261 du CGI', null);

        $printed = $this->mentions->asTheyStand($this->atelier, $customer, InvoiceType::Invoice, 'fr');

        self::assertEquals(new PrintedMentions([self::EXEMPT, ...self::FR_ALWAYS], [self::EXEMPT => ['reference' => 'article 261 du CGI']]), $printed);
    }

    public function testATunisianMentionWaitsForNothing(): void
    {
        $shop = new Company('Boutique', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $customer = $this->customer($shop, 'exempt', 'fiscal.mention.tn.exempt');

        self::assertEquals(new PrintedMentions(['fiscal.mention.tn.exempt']), $this->mentions->forIssue($shop, $customer, InvoiceType::Invoice, 'fr'));
    }

    /** @param \Closure(): mixed $issue */
    private function refused(\Closure $issue): MentionDatumMissing
    {
        try {
            $issue();
        } catch (MentionDatumMissing $refused) {
            return $refused;
        }
        self::fail('the mention was printed without what it states');
    }

    private function customer(Company $company, string $regime, ?string $mentionKey): Customer
    {
        $now = $this->clock->now();
        $families = null === $mentionKey ? [] : [TaxFamily::Vat];

        return Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Garage Martin'), null, new CustomerTaxRegime($company->getCountryCode(), $regime, 'fiscal.regime.'.$regime, $families, $mentionKey, 0, $now), [], $now);
    }
}

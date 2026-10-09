<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Module\Customers\Domain\Customer;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Invoices\Domain\OperationCategory;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;

/**
 * The legal mentions an invoice to a customer prints (docs/SPEC.md § 7, 2026-09-14): its customer regime's, its company
 * VAT regime's and its preset's, each once, as translation keys, and the débits mention where the preset carries one, the
 * company opted to pay VAT on the débits and the operations include services, which is all the option concerns
 * (docs/fiscal/FR.md § 4a). Issuing keeps them; a draft previews them as they stand.
 *
 * A wording that states something the company gives (`%rate%`, `%reference%`) is filled from the setting that gives it,
 * read along the customer's chain, and issuing is refused when it is not given (docs/SPEC.md § 7, 2026-09-21 18:30).
 * Which setting fills which placeholder is code: a new kind of datum is a new setting, as a new tax kind is.
 */
final readonly class InvoiceMentions
{
    public const string LATE_PAYMENT_RATE = 'document.late_payment_rate';
    public const string EXEMPTION_REFERENCE = 'document.exemption_reference';

    /** Each placeholder a wording may hold, and the setting that fills it. */
    public const array DATA = ['rate' => self::LATE_PAYMENT_RATE, 'reference' => self::EXEMPTION_REFERENCE];

    public function __construct(
        private FiscalPresets $presets,
        private ExcludedTaxFamilies $regimes,
        private MentionWording $wording,
        private ReadSetting $settings,
    ) {
    }

    /**
     * @param OperationCategory|null $operations what the document's operations are; null when not known yet
     *
     * @return list<string>
     */
    public function keys(Company $company, Customer $customer, ?OperationCategory $operations = null): array
    {
        $preset = $this->presets->get($company->getFiscalPreset());
        $companyRegime = $this->regimes->companyRegime($company);
        $debits = $company->getProfile()->vatOnDebits && true === $operations?->includesServices() ? $preset->invoiceFields->vatOnDebitsMentionKey : null;

        return array_values(array_unique(array_filter(
            [$customer->getTaxRegime()->getMentionKey(), $companyRegime?->mentionKey, ...$preset->invoiceMentions, $debits],
            static fn (?string $key): bool => null !== $key,
        )));
    }

    /**
     * What issuing keeps: every mention, filled.
     *
     * @throws MentionDatumMissing when a mention states something no setting gives
     */
    public function forIssue(Company $company, Customer $customer, InvoiceType $type, string $language, ?OperationCategory $operations = null): PrintedMentions
    {
        return $this->printed($company, $customer, $type, $language, true, $operations);
    }

    /** What a draft prints today: the mentions that can be filled; the others wait for issuing to name what they lack. */
    public function asTheyStand(Company $company, Customer $customer, InvoiceType $type, string $language, ?OperationCategory $operations = null): PrintedMentions
    {
        return $this->printed($company, $customer, $type, $language, false, $operations);
    }

    private function printed(Company $company, Customer $customer, InvoiceType $type, string $language, bool $issuing, ?OperationCategory $operations): PrintedMentions
    {
        $context = new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
        $keys = [];
        $parameters = [];
        foreach ($this->keys($company, $customer, $operations) as $key) {
            $filled = [];
            foreach ($this->wording->placeholders($key, $language) as $placeholder) {
                if ('rate' === $placeholder && $this->standsInForTheRate($company, $type)) {
                    continue 2;
                }
                $setting = self::DATA[$placeholder] ?? null;
                $value = null === $setting ? null : $this->settings->value($context, $setting);
                $value = \is_string($value) ? trim($value) : '';
                if ('' === $value) {
                    if ($issuing) {
                        throw new MentionDatumMissing($key, $setting ?? 'mention.'.$placeholder);
                    }
                    continue 2;
                }
                $filled[$placeholder] = $value;
            }
            $keys[] = $key;
            if ([] !== $filled) {
                $parameters[$key] = $filled;
            }
        }

        return new PrintedMentions($keys, $parameters);
    }

    /**
     * A late payment rate is not asked of a credit note, which asks for no payment, nor of a company whose own late
     * penalty text is printed in its place: the mention would then say it twice.
     */
    private function standsInForTheRate(Company $company, InvoiceType $type): bool
    {
        return InvoiceType::CreditNote === $type || null !== $company->getProfile()->latePenaltyText;
    }
}

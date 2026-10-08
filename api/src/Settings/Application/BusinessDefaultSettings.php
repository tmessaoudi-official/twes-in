<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;
use App\Shared\Domain\DocumentDesign;

/**
 * The business defaults a company sets once and its customers, products and documents override (docs/SPEC.md § 3
 * Settings): the parties chain for what a document says to its customer, the articles chain for what a product
 * starts with. The levels below the company are declared now and become writable as their screens arrive; tax
 * arithmetic and legal mentions are not here, they are preset data.
 */
final readonly class BusinessDefaultSettings implements DeclaresSettings
{
    public const string MODULE = 'core';

    public function settings(): iterable
    {
        $parties = [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document];
        $articles = [SettingLevel::Company, SettingLevel::ProductCategory, SettingLevel::Product];

        yield new SettingDefinition('document.payment_terms_days', SettingType::Int, 30, SettingChain::Parties, $parties, 'settings.document.payment_terms_days', self::MODULE, min: 0, max: 365);
        yield new SettingDefinition('document.language', SettingType::Enum, 'fr', SettingChain::Parties, $parties, 'settings.document.language', self::MODULE, choices: ['fr', 'en']);
        yield new SettingDefinition('document.printed_notes', SettingType::Text, '', SettingChain::Parties, $parties, 'settings.document.printed_notes', self::MODULE, maxLength: 2000);

        // The seller's bank details printed on an invoice as the way to pay it, its number the reference to give.
        yield new SettingDefinition('document.how_to_pay', SettingType::Bool, true, SettingChain::Parties, $parties, 'settings.document.how_to_pay', self::MODULE);

        // The total of an invoice or credit note written out in words beneath its figures, as Tunisian invoices customarily
        // close (« Arrêtée la présente facture à la somme de … »), so it is on unless a company turns it off.
        yield new SettingDefinition('document.amount_in_words', SettingType::Bool, true, SettingChain::Parties, $parties, 'settings.document.amount_in_words', self::MODULE);

        // What the discounts took off, printed under the totals as « Vous économisez … ». Always on screen; on paper only when
        // the company asks, as a line that sells is the seller's choice to make.
        yield new SettingDefinition('document.savings_line', SettingType::Bool, false, SettingChain::Parties, $parties, 'settings.document.savings_line', self::MODULE);

        // What a printed legal mention states and the law leaves to the seller (docs/SPEC.md § 7, 2026-09-21 18:30): the rate
        // of late payment penalties a French invoice must state, and the provision an exempt customer is exempt under.
        // Empty is not given, and issuing a document whose mention needs it is refused; a customer may differ from the rest.
        $partiesNotDocument = [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer];
        yield new SettingDefinition('document.late_payment_rate', SettingType::Text, '', SettingChain::Parties, $partiesNotDocument, 'settings.document.late_payment_rate', self::MODULE, maxLength: 200);
        yield new SettingDefinition('document.exemption_reference', SettingType::Text, '', SettingChain::Parties, $partiesNotDocument, 'settings.document.exemption_reference', self::MODULE, maxLength: 200);

        // How every document the company prints looks (docs/SPEC.md § 7, 2026-10-06 10:19): a built-in layout and an accent,
        // kept with a document when it is issued. The company's alone: a document is the company's, whoever it goes to.
        // Written out, so the labels gate can read them; a test keeps them DocumentLayout's cases.
        yield new SettingDefinition('document.layout', SettingType::Enum, 'classic', SettingChain::Parties, [SettingLevel::Company], 'settings.document.layout', self::MODULE, choices: ['classic', 'modern', 'compact']);
        yield new SettingDefinition('document.accent', SettingType::Colour, DocumentDesign::DEFAULT_ACCENT, SettingChain::Parties, [SettingLevel::Company], 'settings.document.accent', self::MODULE);

        // Whether an up-to-date copy stamps what became of the invoice: « Acquittée », « Soldée » or « Réglée partiellement ».
        // Off unless the company asks, because a stamp on a document reads as a statement the seller makes.
        yield new SettingDefinition('document.paid_stamp', SettingType::Bool, false, SettingChain::Parties, [SettingLevel::Company], 'settings.document.paid_stamp', self::MODULE);

        // What a customer may owe before a new delivery warns, in the company's currency; zero is no limit, so a customer
        // can be released from a group's limit by setting zero on it. A document has none: it is about the account.
        yield new SettingDefinition('credit.limit', SettingType::Money, '0', SettingChain::Parties, [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer], 'settings.credit.limit', self::MODULE, min: '0');

        yield new SettingDefinition('article.default_unit', SettingType::Text, 'C62', SettingChain::Articles, $articles, 'settings.article.default_unit', self::MODULE, pattern: '/^[A-Z0-9]{2,3}$/');
        yield new SettingDefinition('article.stock_tracking', SettingType::Bool, false, SettingChain::Articles, $articles, 'settings.article.stock_tracking', self::MODULE);
        // How a new product is followed when it does not say: by lot or by serial number, per company or per category. A
        // product keeps its own value in its own column, so the product level is not offered here.
        yield new SettingDefinition('article.traceability', SettingType::Enum, 'none', SettingChain::Articles, [SettingLevel::Company, SettingLevel::ProductCategory], 'settings.article.traceability', self::MODULE, choices: ['none', 'lot', 'serial']);
        // What faces a customer (the customer display, a phone's scan) shows the price with tax; with this on, the price
        // without tax is shown beside it, never instead.
        yield new SettingDefinition('article.show_price_excl_tax', SettingType::Bool, false, SettingChain::Articles, [SettingLevel::Company], 'settings.article.show_price_excl_tax', self::MODULE);
    }
}

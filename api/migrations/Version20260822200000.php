<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `product.vat_rate` may not be negative — the schema half of a guard the previous migration missed.
 *
 * **A SECOND MIGRATION RATHER THAN AN EDIT TO `Version20260822160000`, which landed hours earlier.** Editing an
 * applied migration is only safe if you know every database that has run it, and "only mine" is an assumption
 * about the world rather than a fact about the code. A migration is an append-only log by construction; the
 * habit of amending one is worth more than the tidiness of a single file.
 *
 * ## Why the rate needs a floor at all
 *
 * `Rate` permits negatives and is RIGHT to: it also serves as the PROFIT rate, where F4 explicitly rules that
 * selling below cost is real — clearance, a loss-leader — and must be surfaced rather than clamped. The same
 * type serving two roles is why the constraint belongs at each USE SITE, which is the argument
 * {@see \Twes\Domain\Document\DocumentLine} already makes for its own VAT rate.
 *
 * **`product` was the second use site and had no guard, so a product at `-19%` was storable and unusable.**
 * `DocumentLine` refuses a negative VAT rate, so every line ever created from such a product would be refused —
 * the defect surfacing at INVOICE time on a catalogue entry that saved cleanly weeks earlier. A landmine rather
 * than an error. `Product`'s constructor now refuses it and `NewProductInput` mirrors that at the edge; this is
 * the third statement of the rule, at the only level a hand-written `INSERT` cannot avoid.
 *
 * **NO FLOOR ON `profit_rate`, deliberately**, and the asymmetry is the whole point: that column is where F4's
 * negative rate legitimately lives.
 */
final class Version20260822200000 extends AbstractMigration
{
    private const string PRODUCT = 'product';

    public function getDescription(): string
    {
        return 'A product VAT rate may not be negative; a profit rate still may.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT product_vat_rate_is_not_negative CHECK (vat_rate >= 0)',
            self::PRODUCT,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(\sprintf(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS product_vat_rate_is_not_negative',
            self::PRODUCT,
        ));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The seller as an issued invoice or a validated delivery note printed it (SellerSnapshot). Documents issued before
 * this column existed are frozen as they print today, the only seller they can still be shown with; the rows that
 * already carry a customer snapshot are exactly the issued ones.
 */
final class Version20260927072536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seller snapshot on invoices and delivery notes';
    }

    public function up(Schema $schema): void
    {
        foreach (['invoice', 'delivery_note'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD seller_snapshot JSONB DEFAULT NULL', $table));
            $this->addSql(\sprintf(<<<'SQL'
                UPDATE %1$s d SET seller_snapshot = jsonb_build_object(
                    'name', COALESCE(c.legal_name, c.name),
                    'legalForm', c.legal_form,
                    'identifiers', c.identifiers,
                    'address', CASE WHEN e.address_line1 IS NOT NULL
                        THEN jsonb_build_object('line1', e.address_line1, 'line2', e.address_line2, 'postalCode', e.postal_code, 'city', e.city, 'countryCode', c.country_code)
                        ELSE jsonb_build_object('line1', c.address_line1, 'line2', c.address_line2, 'postalCode', c.postal_code, 'city', c.city, 'countryCode', c.country_code)
                    END,
                    'phone', COALESCE(e.phone, c.phone),
                    'email', COALESCE(e.email, c.email),
                    'iban', c.iban,
                    'bic', CASE WHEN c.iban IS NULL THEN NULL ELSE c.bic END,
                    'currency', c.currency,
                    'vatRegime', c.vat_regime
                )
                FROM company c, establishment e
                WHERE c.id = d.company_id AND e.id = d.establishment_id AND d.customer_snapshot IS NOT NULL
                SQL, $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP seller_snapshot');
        $this->addSql('ALTER TABLE delivery_note DROP seller_snapshot');
    }
}

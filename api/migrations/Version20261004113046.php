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
 * Cheques and traites received against an issued invoice: an instrument with a due day and a state, which becomes a
 * payment only when it is cashed. The invoice, the company and the payment it cashed all take it with them or let it go
 * as their own rows go, the payment's deletion leaving the instrument with none.
 */
final class Version20261004113046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The payment instrument table: cheques and traites received.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_instrument (
              id UUID NOT NULL,
              kind VARCHAR(8) NOT NULL,
              amount NUMERIC(14, 3) NOT NULL,
              due_on DATE NOT NULL,
              bank VARCHAR(80) DEFAULT NULL,
              number VARCHAR(64) DEFAULT NULL,
              status VARCHAR(12) NOT NULL,
              settled_on DATE DEFAULT NULL,
              recorded_by UUID DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              invoice_id UUID NOT NULL,
              company_id UUID NOT NULL,
              payment_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_949E8084C3A3BB ON payment_instrument (payment_id)');
        $this->addSql('CREATE INDEX idx_payment_instrument_invoice ON payment_instrument (invoice_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_payment_instrument_company_status_due ON payment_instrument (company_id, status, due_on)
        SQL);
        $this->addSql('CREATE INDEX IDX_949E808979B1AD6 ON payment_instrument (company_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              payment_instrument
            ADD
              CONSTRAINT FK_949E8082989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              payment_instrument
            ADD
              CONSTRAINT FK_949E808979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              payment_instrument
            ADD
              CONSTRAINT FK_949E8084C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_instrument DROP CONSTRAINT FK_949E8082989F1FD');
        $this->addSql('ALTER TABLE payment_instrument DROP CONSTRAINT FK_949E808979B1AD6');
        $this->addSql('ALTER TABLE payment_instrument DROP CONSTRAINT FK_949E8084C3A3BB');
        $this->addSql('DROP TABLE payment_instrument');
    }
}

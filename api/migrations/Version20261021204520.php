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
 * One submission, one write: the keys sent with writes that add, each claimed by its account before the work, marked
 * done inside the work's transaction and answered once the answer is written (SubmissionClaims). `attempt` names the
 * request holding the claim, so a request whose claim was taken back never touches the claim that took it. Owned through
 * DBAL and left out of Doctrine's schema by the connection's schema filter, as `sessions` is. A removed company or
 * account takes its claims with it.
 */
final class Version20261021204520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'submission_claim: the Idempotency-Key of each write that adds, and what it answered.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE submission_claim (
                account_id UUID NOT NULL,
                key UUID NOT NULL,
                attempt UUID NOT NULL,
                company_id UUID DEFAULT NULL,
                fingerprint CHAR(64) NOT NULL,
                claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                work_done BOOLEAN DEFAULT false NOT NULL,
                status SMALLINT DEFAULT NULL,
                headers JSONB DEFAULT NULL,
                body TEXT DEFAULT NULL,
                PRIMARY KEY (account_id, key),
                CONSTRAINT submission_claim_status CHECK (status IS NULL OR (status BETWEEN 100 AND 599 AND work_done AND body IS NOT NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_submission_claim_claimed_at ON submission_claim (claimed_at)');
        $this->addSql('CREATE INDEX idx_submission_claim_company ON submission_claim (company_id)');
        $this->addSql('ALTER TABLE submission_claim ADD CONSTRAINT fk_submission_claim_account FOREIGN KEY (account_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE submission_claim ADD CONSTRAINT fk_submission_claim_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE submission_claim');
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028020000;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Whether a company was ever opened decides what a suspension of it is: one never opened was refused, one opened was
 * suspended and is restored. The companies of before learn when they were opened from the journal, or from their own
 * creation when they were opened without anyone deciding, which every company an operator suspended was.
 */
final class CompanyDecisionMigrationTest extends ApiTestCase
{
    public function testEveryCompanyOfBeforeLearnsWhetherAndWhenItWasOpened(): void
    {
        $approved = $this->company('Approuvée', Company::STATUS_ACTIVE, '2026-09-01 08:00:00');
        $this->journal($approved, 'company.approved', '2026-09-03 10:15:00');
        $openFromTheStart = $this->company('Ouverte', Company::STATUS_ACTIVE, '2026-09-02 08:00:00');
        $suspended = $this->company('Suspendue', Company::STATUS_SUSPENDED, '2026-09-04 08:00:00');
        $this->journal($suspended, 'company.suspended', '2026-09-20 11:00:00');
        $refused = $this->company('Refusée', Company::STATUS_SUSPENDED, '2026-09-05 08:00:00');
        $this->journal($refused, 'company.rejected', '2026-09-06 09:00:00');
        $refusedThenOpened = $this->company('Rouverte', Company::STATUS_ACTIVE, '2026-09-07 08:00:00');
        $this->journal($refusedThenOpened, 'company.rejected', '2026-09-08 09:00:00');
        $this->journal($refusedThenOpened, 'company.approved', '2026-09-09 09:30:00');
        $waiting = $this->company('En attente', Company::STATUS_PENDING, '2026-09-10 08:00:00');
        $closed = $this->company('Fermée', Company::STATUS_CLOSED, '2026-09-11 08:00:00');

        $this->migrate();

        self::assertSame('2026-09-03 10:15:00', $this->openedAt($approved), 'the operator\'s approval, from the journal');
        self::assertSame('2026-09-02 08:00:00', $this->openedAt($openFromTheStart), 'opened without anyone deciding');
        self::assertSame('2026-09-04 08:00:00', $this->openedAt($suspended), 'a suspension is only ever of an open company');
        self::assertNull($this->openedAt($refused), 'never opened: refused');
        self::assertSame('2026-09-09 09:30:00', $this->openedAt($refusedThenOpened));
        self::assertNull($this->openedAt($waiting));
        self::assertSame('2026-09-11 08:00:00', $this->openedAt($closed), 'only an open company is closed down');
    }

    private function company(string $name, string $status, string $createdAt): string
    {
        $company = $this->createCompany($name);
        $id = $company->getId()->toRfc4122();
        $this->em()->getConnection()->executeStatement(
            'UPDATE company SET status = ?, created_at = ?, approved_at = NULL WHERE id = ?',
            [$status, $createdAt, $id],
        );

        return $id;
    }

    private function journal(string $companyId, string $action, string $at): void
    {
        $this->em()->getConnection()->insert('audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'company_id' => $companyId,
            'entity_type' => 'company',
            'entity_id' => $companyId,
            'action' => $action,
            'changes' => '{}',
            'at' => $at,
        ]);
    }

    private function openedAt(string $companyId): ?string
    {
        $at = $this->em()->getConnection()->fetchOne('SELECT approved_at FROM company WHERE id = ?', [$companyId]);
        self::assertTrue(null === $at || \is_string($at));

        return $at;
    }

    private function migrate(): void
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261028020000.php';
        $connection = $this->em()->getConnection();
        $migration = new Version20261028020000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $connection->executeStatement($query->getStatement());
        }
    }
}

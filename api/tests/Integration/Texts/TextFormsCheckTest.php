<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Texts;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\RunDataChecks;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A text saved before « Textes » checked its forms, or written straight into the database, may give a mail or a
 * printed document no form for a count, which then fails at that count alone: the night's data check names it to the
 * operators by its level, language and key, never its words, until it is worded again or taken back.
 */
final class TextFormsCheckTest extends KernelTestCase
{
    public function testAKeptTextNoCountChoosesFromIsFoundByItsAddressNeverItsWords(): void
    {
        self::bootKernel();
        $company = new Company('Formes gardées', 'TN', 'TND', 'fr', 'Africa/Tunis', new \DateTimeImmutable());
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($company);
        $entityManager->flush();
        $this->keep($company->getId(), 'ar', 'pdf', 'invoice.payment_terms', 'الأجل يوم %days%|الأجل %days% أيام');
        $this->keep(null, 'fr', 'emails', 'notification.types.invoice_reminder_due', '{0} Relance %stage% : %number% de %customer%, %amount_due% %currency%, %days_late%|[1,100] Relance %stage% : %number% de %customer%, %amount_due% %currency%, %days_late% jours');
        // Kept as they are: forms every count chooses from, and a « | » in a text no count reaches.
        $this->keep($company->getId(), 'fr', 'pdf', 'invoice.payment_terms', 'À %days% jour|À %days% jours');
        $this->keep($company->getId(), 'ar', 'pdf', 'invoice.title', 'فاتورة|بيع');

        $found = array_values(array_filter(
            static::getContainer()->get(RunDataChecks::class)->findings(),
            static fn (DataFinding $finding): bool => 'texts.forms' === $finding->check,
        ));

        self::assertCount(2, $found);
        $byCompany = [];
        foreach ($found as $finding) {
            $byCompany[$finding->companyId?->toRfc4122() ?? 'installation'] = $finding->detail;
        }
        self::assertStringContainsString('ar pdf invoice.payment_terms', $byCompany[$company->getId()->toRfc4122()] ?? '');
        self::assertStringContainsString('fr emails notification.types.invoice_reminder_due', $byCompany['installation'] ?? '');
        foreach ($found as $finding) {
            self::assertStringNotContainsString('%days%', $finding->detail, 'the words stay out of the finding');
        }
    }

    private function keep(?Uuid $companyId, string $language, string $domain, string $key, string $value): void
    {
        static::getContainer()->get(Connection::class)->insert('text_override', [
            'id' => Uuid::v7()->toRfc4122(),
            'company_id' => $companyId?->toRfc4122(),
            'language' => $language,
            'domain' => $domain,
            'text_key' => $key,
            'value' => $value,
            'original' => 'x',
            'updated_at' => '2026-11-01 10:00:00',
            'version' => 1,
        ]);
    }
}

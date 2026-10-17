<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\Repository\SettingRepository;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\RecordChanged;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two people giving a key its first value at one level both read no row before either writes, and nothing can hold a
 * row that is not there yet. The unique index is what decides, and the adapter answers its refusal as a save made
 * since (409), never as a server error.
 */
final class DoctrineSettingRepositoryTest extends KernelTestCase
{
    public function testASecondFirstValueOfOneKeyAtOneLevelReadsAsSavedSince(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingRepository::class);

        $company = new Company('Race', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $entityManager->persist($company);
        $entityManager->flush();
        $address = SettingAddress::company($company);

        $settings->save(new Setting($address, 'document.payment_terms_days', 45, new \DateTimeImmutable()));
        // The second write never passes through the use case's read: this is the index alone.
        $this->expectException(RecordChanged::class);
        $settings->save(new Setting($address, 'document.payment_terms_days', 60, new \DateTimeImmutable()));
    }

    /**
     * A single key's change is the last word: written over a save made since this request first read the row, it holds
     * the row and changes what is there, never failing on the version it read earlier.
     */
    public function testASingleKeyIsWrittenOverASaveMadeSinceTheRequestReadIt(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingRepository::class);

        $company = new Company('Last word', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $entityManager->persist($company);
        $entityManager->flush();
        $address = SettingAddress::company($company);
        $settings->save(new Setting($address, 'document.payment_terms_days', 45, new \DateTimeImmutable()));
        // The row stays in this request's memory at its first version while another saves it.
        $entityManager->getConnection()->executeStatement("UPDATE setting SET version = version + 1, value = '50' WHERE key = 'document.payment_terms_days' AND company_id = ?", [$company->getId()->toRfc4122()]);

        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($company), 'document.payment_terms_days', SettingLevel::Company, 60, null);

        self::assertSame(
            ['value' => '60', 'version' => 3],
            $entityManager->getConnection()->fetchAssociative("SELECT value, version FROM setting WHERE key = 'document.payment_terms_days' AND company_id = ?", [$company->getId()->toRfc4122()]),
        );
    }
}

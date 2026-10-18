<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261018094714;
use Psr\Log\NullLogger;

/**
 * An account's language followed its person's choice of screen language only from this release on: a person who chose
 * one before is given it on their account once, so their next mail is in it too.
 */
final class AccountLanguageMigrationTest extends ApiTestCase
{
    public function testAnAccountTakesTheScreenLanguageItsPersonChoseBefore(): void
    {
        $english = $this->createUser('english@twes.local', 'a-long-enough-password');
        $none = $this->createUser('none@twes.local', 'a-long-enough-password');
        $unspoken = $this->createUser('unspoken@twes.local', 'a-long-enough-password');
        $now = new \DateTimeImmutable('2026-10-18 09:00:00');
        $company = $this->createCompany('Acme');
        foreach ([
            new Setting(SettingAddress::user($english->getId()), 'presentation.language', 'en', $now),
            new Setting(SettingAddress::user($none->getId()), 'presentation.density', 'compact', $now),
            new Setting(SettingAddress::user($unspoken->getId()), 'presentation.language', 'xx', $now),
            new Setting(SettingAddress::company($company), 'presentation.language', 'en', $now),
        ] as $setting) {
            $this->em()->persist($setting);
        }
        $this->em()->flush();

        $this->migrate();

        self::assertSame('en', $this->localeOf($english->getId()->toRfc4122()));
        self::assertSame('fr', $this->localeOf($none->getId()->toRfc4122()), 'a company default is no person\'s choice');
        self::assertSame('fr', $this->localeOf($unspoken->getId()->toRfc4122()), 'a language the screens do not speak is never an account\'s');
    }

    private function localeOf(string $userId): string
    {
        $locale = $this->em()->getConnection()->fetchOne('SELECT locale FROM "user" WHERE id = ?', [$userId]);
        self::assertIsString($locale);

        return $locale;
    }

    private function migrate(): void
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261018094714.php';
        $connection = $this->em()->getConnection();
        $migration = new Version20261018094714($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $connection->executeStatement($query->getStatement());
        }
    }
}

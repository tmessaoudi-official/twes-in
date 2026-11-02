<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261102110000;
use Psr\Log\NullLogger;

/**
 * The swatches that sat on a status colour moved; a company or a person who had picked one keeps it, in its new shade,
 * on screen and on its documents. A colour typed by hand is not a swatch and stays.
 */
final class AccentPresetMigrationTest extends ApiTestCase
{
    public function testAPickedSwatchTakesItsNewShadeAndATypedColourStays(): void
    {
        $company = $this->createCompany('Rouge');
        $other = $this->createCompany('Sur mesure');
        $person = $this->createUser('rose@twes.local', 'a-long-enough-password', $company);
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');
        $this->em()->persist(new Setting(SettingAddress::company($company), 'presentation.accent', '#B3261E', $now));
        $this->em()->persist(new Setting(SettingAddress::company($company), 'document.accent', '#c25400', $now));
        $this->em()->persist(new Setting(SettingAddress::user($person->getId()), 'presentation.accent', '#c2185b', $now));
        $this->em()->persist(new Setting(SettingAddress::company($other), 'presentation.accent', '#123456', $now));
        $this->em()->persist(new Setting(SettingAddress::company($other), 'document.accent', '#1b7f3b', $now));
        $this->em()->flush();

        $this->migrate(up: true);
        $this->migrate(up: true);

        self::assertSame([
            'Rouge document.accent #ac5102',
            'Rouge presentation.accent #760906',
            'Sur mesure document.accent #058329',
            'Sur mesure presentation.accent #123456',
            'user presentation.accent #bc1d6f',
        ], $this->accents($company, $other, $person), 'each swatch in its new shade, a second run changing nothing, a typed colour kept');

        $this->migrate(up: false);

        self::assertSame([
            'Rouge document.accent #c25400',
            'Rouge presentation.accent #b3261e',
            'Sur mesure document.accent #1b7f3b',
            'Sur mesure presentation.accent #123456',
            'user presentation.accent #c2185b',
        ], $this->accents($company, $other, $person), 'down() gives each swatch its old shade back, written in lower case');
    }

    /** @return list<string> */
    private function accents(Company $company, Company $other, User $person): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT coalesce(c.name, s.level) AS owner, s.key, s.value #>> '{}' AS colour
            FROM setting s LEFT JOIN company c ON c.id = s.company_id AND s.level = 'company'
            WHERE s.key IN ('presentation.accent', 'document.accent')
              AND (s.company_id IN (?, ?) AND s.level = 'company' OR s.level = 'user' AND s.level_id = ?)
            ORDER BY 1, 2
            SQL, [$company->getId()->toRfc4122(), $other->getId()->toRfc4122(), $person->getId()->toRfc4122()]);

        return array_map(static function (array $row): string {
            $owner = $row['owner'];
            $key = $row['key'];
            $colour = $row['colour'];
            self::assertIsString($owner);
            self::assertIsString($key);
            self::assertIsString($colour);

            return "{$owner} {$key} {$colour}";
        }, $rows);
    }

    private function migrate(bool $up): void
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261102110000.php';
        $connection = $this->em()->getConnection();
        $migration = new Version20261102110000($connection, new NullLogger());
        if ($up) {
            $migration->up(new Schema());
        } else {
            $migration->down(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $connection->executeStatement($query->getStatement());
        }
    }
}

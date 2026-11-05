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
use DoctrineMigrations\Version20261102070000;
use Psr\Log\NullLogger;

/**
 * A new account starts on the vivid colour style; the accounts that existed when the style came keep the calmer look
 * they had, so nobody's screen changes under them on the update. A person who already chose keeps the choice.
 */
final class ThemePresetMigrationTest extends ApiTestCase
{
    public function testEveryAccountThatExistsKeepsTheCalmerLookAndAChoiceStands(): void
    {
        $before = $this->createUser('before@twes.local', 'a-long-enough-password');
        $chose = $this->createUser('chose@twes.local', 'a-long-enough-password');
        $this->em()->persist(new Setting(SettingAddress::user($chose->getId()), 'presentation.theme-preset', 'vif', new \DateTimeImmutable('2026-11-02 07:00:00')));
        $this->em()->flush();

        $this->migrate();
        $this->migrate();

        self::assertSame(['calme'], $this->presetsOf($before->getId()->toRfc4122()));
        self::assertSame(['vif'], $this->presetsOf($chose->getId()->toRfc4122()), 'a choice already made stands, and a second run adds nothing');
    }

    public function testAnAccountMadeAfterwardsStartsVivid(): void
    {
        $this->migrate();
        $after = $this->createUser('after@twes.local', 'a-long-enough-password');

        self::assertSame([], $this->presetsOf($after->getId()->toRfc4122()));
        $definition = self::getContainer()->get(\App\Settings\Application\PresentationSettings::class);
        self::assertInstanceOf(\App\Settings\Application\PresentationSettings::class, $definition);
        foreach ($definition->settings() as $setting) {
            if ('presentation.theme-preset' === $setting->key) {
                self::assertSame('vif', $setting->default);

                return;
            }
        }
        self::fail('presentation.theme-preset is declared');
    }

    /**
     * Going back leaves every person's colour style as it is, the ones chosen after the update included: the release
     * before reads only the settings it declares, so the rows do no harm under it, while deleting them took choices the
     * migration never wrote. A rollback runs the previous release on this schema and never runs down() (docs/SPEC.md
     * § 7, the update rollback).
     */
    public function testGoingBackKeepsEveryPersonsChoice(): void
    {
        $migration = $this->migration();
        $migration->down(new Schema());

        self::assertSame([], $migration->getSql());
    }

    /** @return list<mixed> */
    private function presetsOf(string $userId): array
    {
        return array_map(
            static fn (mixed $value): mixed => json_decode(\is_string($value) ? $value : 'null', true),
            $this->em()->getConnection()->fetchFirstColumn("SELECT value FROM setting WHERE level = 'user' AND level_id = ? AND key = 'presentation.theme-preset'", [$userId]),
        );
    }

    private function migrate(): void
    {
        $connection = $this->em()->getConnection();
        $migration = $this->migration();
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            $connection->executeStatement($query->getStatement());
        }
    }

    private function migration(): Version20261102070000
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261102070000.php';

        return new Version20261102070000($this->em()->getConnection(), new NullLogger());
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Domain\Model\DemoSandbox;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use Symfony\Component\Uid\Uuid;

/** Demo sandboxes made by hand, without a worker build, and what the demo tests read back. */
trait MakesSandboxes
{
    private function readySandbox(string $country): DemoSandbox
    {
        $sandbox = $this->openedSandbox($country, 'now');
        $sandbox->ready(new \DateTimeImmutable());

        return $sandbox;
    }

    /** A sandbox being built since `$since`, its company and owner already opened. */
    private function openedSandbox(string $country, string $since): DemoSandbox
    {
        $company = $this->createCompany("Bac à sable $country ".bin2hex(random_bytes(3)), false);
        $owner = $this->createUser(\sprintf('demo-%s@example.com', bin2hex(random_bytes(16))), 'not-used-here', $company);
        $sandbox = new DemoSandbox(Uuid::v7(), $country, new \DateTimeImmutable($since));
        $sandbox->startBuilding(new \DateTimeImmutable($since));
        $sandbox->opened($company, $owner);
        $this->em()->persist($sandbox);

        return $sandbox;
    }

    private function platform(string $key, mixed $value): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(), $key, SettingLevel::Platform, $value, null);
    }

    /** @return list<array{0: string, 1: string}> */
    private function sandboxes(): array
    {
        return array_map(
            static fn (array $row): array => [\is_string($row['country']) ? $row['country'] : '', \is_string($row['state']) ? $row['state'] : ''],
            $this->rows('SELECT country, state FROM demo_sandbox ORDER BY country, state', []),
        );
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function row(string $sql, array $parameters): array
    {
        $row = $this->em()->getConnection()->fetchAssociative($sql, $parameters);

        return false === $row ? self::fail("No row for $sql") : $row;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        return $this->em()->getConnection()->fetchAllAssociative($sql, $parameters);
    }

    /** @param list<mixed> $parameters */
    private function numberOf(string $sql, array $parameters): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql, $parameters);

        return \is_int($count) ? $count : (is_numeric($count) ? (int) $count : self::fail("$sql counts nothing"));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Watch;

use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\ComesDueWithTime;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * When « À surveiller » is read again with nothing written: the earliest moment a part the member is shown changes, else
 * the next day. A moment not after now is never taken, whichever part says it, as a screen would then read without end.
 */
final class WatchCatalogueReadAgainTest extends KernelTestCase
{
    private const string NOW = '2026-11-06 10:00:00';
    private const string NEXT_DAY = '2026-11-06 23:00:00';

    public function testTheEarliestMomentOfAPartShownWinsAndAMomentNotAfterNowIsNeverTaken(): void
    {
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $nextDay = new \DateTimeImmutable(self::NEXT_DAY, new \DateTimeZone('UTC'));
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis', $now);
        $catalogue = new WatchCatalogue([
            self::part('a', 'a.read', '2026-11-06 12:00:00'),
            self::part('b', 'b.read', '2026-11-06 11:00:00'),
            self::part('c', 'c.read', self::NOW),
            self::part('d', 'd.read', '2026-11-06 09:00:00'),
            self::part('e', 'e.read', null),
        ], static::getContainer()->get(ModuleStates::class));
        $mayAll = static fn (string $permission): bool => true;

        self::assertSame('2026-11-06 11:00:00', $catalogue->readAgainAt($company, $now, $nextDay, $mayAll)->format('Y-m-d H:i:s'), 'now and an hour ago are not after now');
        self::assertSame(
            '2026-11-06 12:00:00',
            $catalogue->readAgainAt($company, $now, $nextDay, static fn (string $permission): bool => 'b.read' !== $permission)->format('Y-m-d H:i:s'),
            'a part the member is not shown asks nothing of their screen',
        );
        self::assertSame(self::NEXT_DAY, $catalogue->readAgainAt($company, $now, $nextDay, static fn (string $permission): bool => \in_array($permission, ['c.read', 'd.read', 'e.read'], true))->format('Y-m-d H:i:s'));
    }

    private static function part(string $key, string $permission, ?string $next): DeclaresWatch
    {
        return new readonly class($key, $permission, $next) implements DeclaresWatch, ComesDueWithTime {
            public function __construct(private string $key, private string $permission, private ?string $next)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function module(): ?string
            {
                return null;
            }

            public function permission(): string
            {
                return $this->permission;
            }

            public function kinds(): array
            {
                return [$this->key.'.kind'];
            }

            public function changedBy(): array
            {
                return [];
            }

            public function count(string $kind, Company $company, \DateTimeImmutable $today): int
            {
                return 0;
            }

            public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
            {
                return new Page([], 0, $request);
            }

            public function nextChangeAt(Company $company, \DateTimeImmutable $now): ?\DateTimeImmutable
            {
                return null === $this->next ? null : new \DateTimeImmutable($this->next, new \DateTimeZone('UTC'));
            }
        };
    }
}

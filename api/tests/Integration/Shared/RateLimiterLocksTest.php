<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Every budget is taken under a lock: FrankenPHP serves several requests of one container at once, and without a lock
 * a burst reads the same count before any of them writes it back, so one address gets several mails and a code more
 * guesses than its budget. Symfony gives a limiter no lock when the Lock component is missing, silently.
 */
final class RateLimiterLocksTest extends KernelTestCase
{
    public function testEveryRateLimiterAndTheLoginThrottlingTakeTheirBudgetUnderALock(): void
    {
        self::bootKernel();
        $names = [...self::declaredLimiters(), '_login_local_main', '_login_global_main'];
        self::assertGreaterThanOrEqual(15, \count($names), 'the thirteen declared limiters and the sign-in throttling are read');

        $unlocked = [];
        foreach ($names as $name) {
            $factory = static::getContainer()->get('limiter.'.$name);
            self::assertInstanceOf(RateLimiterFactory::class, $factory, $name);
            if (!new \ReflectionProperty(RateLimiterFactory::class, 'lockFactory')->getValue($factory) instanceof LockFactory) {
                $unlocked[] = $name;
            }
        }

        self::assertSame([], $unlocked, 'these limiters count without a lock');
    }

    /** @return list<string> */
    private static function declaredLimiters(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/rate_limiter.yaml');
        self::assertIsArray($config);
        $framework = $config['framework'] ?? null;
        self::assertIsArray($framework);
        $limiters = $framework['rate_limiter'] ?? null;
        self::assertIsArray($limiters);

        return array_map(strval(...), array_keys($limiters));
    }
}

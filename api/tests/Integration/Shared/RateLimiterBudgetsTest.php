<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every rate limiter's budget, how many and over how long, is a parameter of `config/services.yaml` (everything
 * parametrized, docs/SPEC.md § 7 2026-10-09), never a number written in the limiter's own configuration: production's
 * value is the parameter's, and an environment that needs another sets its own there, as development raises the
 * invitation link's for the browser scenarios. The sign-in throttling is a budget too.
 */
final class RateLimiterBudgetsTest extends KernelTestCase
{
    private const string CONFIG = __DIR__.'/../../../config';

    public function testEveryBudgetIsReadFromItsParameters(): void
    {
        self::bootKernel();
        $limiters = self::section(self::yaml('packages/rate_limiter.yaml'), 'framework', 'rate_limiter');
        self::assertGreaterThanOrEqual(13, \count($limiters), 'the thirteen declared limiters are read');

        $fixed = [];
        foreach ($limiters as $name => $limiter) {
            self::assertIsArray($limiter, (string) $name);
            foreach (['limit', 'interval'] as $field) {
                $parameter = "app.rate_limit.$name.$field";
                if (($limiter[$field] ?? null) !== "%$parameter%") {
                    $fixed[] = "$name.$field";
                    continue;
                }
                $value = static::getContainer()->getParameter($parameter);
                // A positive count, over a span that moves the clock forward.
                self::assertTrue('limit' === $field ? \is_int($value) && $value > 0 : \is_string($value) && new \DateTimeImmutable('@0')->add(\DateInterval::createFromDateString($value))->getTimestamp() > 0, "$parameter: $field");
            }
        }
        $throttling = self::section(self::yaml('packages/security.yaml'), 'security', 'firewalls', 'main', 'login_throttling');
        foreach (['max_attempts' => 'limit', 'interval' => 'interval'] as $field => $budget) {
            if (($throttling[$field] ?? null) !== "%app.rate_limit.login.$budget%") {
                $fixed[] = "login.$budget";
            }
        }

        self::assertSame([], $fixed, 'these budgets are written in the limiter\'s configuration instead of read from a parameter');
        self::assertArrayNotHasKey('when@dev', self::yaml('packages/rate_limiter.yaml'), 'an environment\'s own budget is a parameter it sets');
    }

    /**
     * The browser scenarios run on the development target, every scenario of a CI shard from one address, and each
     * invitation accepted spends two of the link's budget: development raises that one alone, production keeps twenty.
     */
    public function testDevelopmentRaisesOnlyTheInvitationLinksBudget(): void
    {
        $services = self::yaml('services.yaml');
        $raised = array_filter(self::section($services, 'when@dev', 'parameters'), static fn (string|int $key): bool => str_starts_with((string) $key, 'app.rate_limit.'), \ARRAY_FILTER_USE_KEY);

        self::assertSame(['app.rate_limit.invitation_link.limit' => 200], $raised);
        self::assertSame(20, self::section($services, 'parameters')['app.rate_limit.invitation_link.limit'] ?? null);
    }

    /** @return array<mixed> */
    private static function yaml(string $file): array
    {
        $parsed = Yaml::parseFile(self::CONFIG.'/'.$file, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($parsed, $file);

        return $parsed;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    private static function section(array $tree, string ...$path): array
    {
        foreach ($path as $key) {
            $tree = $tree[$key] ?? null;
            self::assertIsArray($tree, implode(' > ', $path));
        }

        return $tree;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Infrastructure\ErrorReporting\ScrubPersonalData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What config/packages/sentry.yaml must keep for an error report to leave with nothing personal in it and for a tracker
 * that is down never to hold a request (docs/START.md § 15). The SDK loads in production only, so the file is read here.
 */
final class ErrorReportingConfigTest extends TestCase
{
    public function testAReportCarriesNoPersonAndPassesTheScrubber(): void
    {
        self::assertFalse(self::setting('sentry', 'options', 'send_default_pii'));
        self::assertSame('never', self::setting('sentry', 'options', 'max_request_body_size'));
        self::assertSame(ScrubPersonalData::class, self::setting('sentry', 'options', 'before_send'));
    }

    public function testATrackerThatIsDownCostsSecondsAtMost(): void
    {
        self::assertLessThanOrEqual(1, self::setting('sentry', 'options', 'http_connect_timeout'));
        self::assertLessThanOrEqual(2, self::setting('sentry', 'options', 'http_timeout'));
    }

    /** Monolog is the one road in; the SDK's own listeners would report each error a second time. */
    public function testEachErrorIsReportedOnce(): void
    {
        self::assertFalse(self::setting('sentry', 'register_error_listener'));
        self::assertFalse(self::setting('sentry', 'register_error_handler'));
        self::assertFalse(self::setting('sentry', 'messenger', 'enabled'));
        self::assertSame('Sentry\Monolog\Handler', self::setting('monolog', 'handlers', 'sentry', 'id'));
    }

    /** The value under the production section at that path, each level on the way an array. */
    private static function setting(string ...$path): mixed
    {
        $value = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/sentry.yaml', Yaml::PARSE_CONSTANT);
        foreach (['when@prod', ...$path] as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}

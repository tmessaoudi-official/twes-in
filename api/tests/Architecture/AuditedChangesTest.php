<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Audit\Application\AuditTrail;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Application\Login\RecordLogout;
use App\Identity\Application\Login\RecordSuccessfulLogin;
use App\Identity\Application\Mfa\FinishPasskeyLogin;
use App\Identity\Application\Mfa\VerifySecondFactor;
use App\Shared\Application\Transactions;
use PHPUnit\Framework\TestCase;

/**
 * docs/SPEC.md § 7, 2026-09-16 (audit P1-4): a use case that audits what it changes commits the change and its audit row
 * together, so it takes the transaction port. The unit tests' InMemoryAuditTrail then refuses a row written outside it.
 */
final class AuditedChangesTest extends TestCase
{
    /** Use cases whose audit row is the record of an authentication event, kept even when the request that caused it fails. */
    private const array AUTH_EVENTS = [
        RecordFailedLogin::class => 'a refused password is on record even though the login fails',
        RecordSuccessfulLogin::class => 'the login event itself',
        RecordLogout::class => 'the logout event itself',
        VerifySecondFactor::class => 'a refused code is on record even though the verification fails',
        FinishPasskeyLogin::class => 'a refused passkey is on record even though the login fails',
    ];

    public function testEveryUseCaseThatAuditsTakesTheTransactionPort(): void
    {
        $audited = 0;
        $missing = [];
        foreach (self::applicationClasses() as $class) {
            $parameters = new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
            $types = array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $parameters);
            if (!\in_array(AuditTrail::class, $types, true) || isset(self::AUTH_EVENTS[$class])) {
                continue;
            }
            ++$audited;
            if (!\in_array(Transactions::class, $types, true)) {
                $missing[] = $class;
            }
        }

        self::assertGreaterThanOrEqual(30, $audited, 'the sweep finds the audited use cases');
        self::assertSame([], $missing, 'these use cases audit a change outside a transaction');
    }

    /** @return list<class-string> */
    private static function applicationClasses(): array
    {
        $root = \dirname(__DIR__, 2).'/src';
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : '';
            if (!str_ends_with($path, '.php') || !str_contains($path, '/Application/')) {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($root) + 1, -4));
            if (class_exists($class) && !new \ReflectionClass($class)->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}

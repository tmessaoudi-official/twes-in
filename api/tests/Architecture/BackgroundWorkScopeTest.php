<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\DataHealth\Infrastructure\Scheduler\CheckDataEveryNight;
use App\Erasure\Infrastructure\Scheduler\EndErasuresEveryQuarterHour;
use App\Identity\Infrastructure\Password\PasswordResetAskedHandler;
use App\Inbox\Infrastructure\Mail\NotificationToMailHandler;
use App\Module\Invoices\Infrastructure\Scheduler\RemindLateInvoicesEveryHour;
use App\Module\Recurring\Infrastructure\Scheduler\RunRecurringInvoicesEveryHour;
use App\Module\Scanning\Infrastructure\Scheduler\ClearUntakenPhotos;
use App\Tenancy\Infrastructure\Invitation\InvitationToMailHandler;
use App\Tenancy\Infrastructure\Signup\SignupAskedHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * A request scopes a company's rows by acting for the company in its path; the worker has no request, so the company
 * filter is off there and every read sees every company. What keeps a handler or a scheduled task to one company is
 * that it names the company itself, in every query. Each one is listed here with how it does, so a new one is a
 * decision taken here rather than a leak found later.
 */
final class BackgroundWorkScopeTest extends TestCase
{
    private const array SCOPES = [
        CheckDataEveryNight::class => 'every company at once, on purpose: each check groups its findings by company and tells the operators counts, never contents',
        ClearUntakenPhotos::class => 'every company at once, on purpose: a phone photo nobody took is cleared by its age alone',
        EndErasuresEveryQuarterHour::class => 'each due erasure, locked by its own id, ends the company or account it names',
        InvitationToMailHandler::class => 'the invitation by its id, and the company that invitation names',
        NotificationToMailHandler::class => 'the person and the company the message names, both by id',
        PasswordResetAskedHandler::class => 'an address, no company: a password belongs to the account',
        RemindLateInvoicesEveryHour::class => 'each active company with the invoices module on, handed to the use case one at a time',
        RunRecurringInvoicesEveryHour::class => 'each active company with the recurring module on, handed to the use case one at a time',
        SignupAskedHandler::class => 'an address, no company: the company is made only when the link is used',
    ];

    public function testEveryHandlerAndScheduledTaskSaysHowItNamesItsCompany(): void
    {
        $found = [];
        foreach (self::classes() as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ([AsMessageHandler::class, AsPeriodicTask::class, AsCronTask::class] as $attribute) {
                if ([] !== $reflection->getAttributes($attribute)) {
                    $found[] = $class;

                    break;
                }
            }
        }
        sort($found);
        $listed = array_keys(self::SCOPES);
        sort($listed);

        self::assertSame($listed, $found, 'a handler or scheduled task runs with no company scope of its own: say here how it names its company');
    }

    /** @return list<class-string> */
    private static function classes(): array
    {
        $root = \dirname(__DIR__, 2).'/src';
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : '';
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($root) + 1, -4));
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}

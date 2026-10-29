<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Audit\Infrastructure\Scheduler\PurgeFailedSignInAddressesEveryNight;
use App\DataHealth\Infrastructure\Scheduler\CheckDataEveryNight;
use App\Demo\Infrastructure\Messenger\SandboxToBuildHandler;
use App\Demo\Infrastructure\Scheduler\TendDemoSandboxesEveryFiveMinutes;
use App\Erasure\Infrastructure\Scheduler\EndErasuresEveryQuarterHour;
use App\Identity\Infrastructure\Mail\SecurityNoticeToMailHandler;
use App\Identity\Infrastructure\Password\PasswordResetAskedHandler;
use App\Identity\Infrastructure\Scheduler\FinalizeAccountDeletionsEveryNight;
use App\Inbox\Infrastructure\Mail\NotificationToMailHandler;
use App\Module\Customers\Infrastructure\Scheduler\MarkKeptCustomerContactsEveryNight;
use App\Licensing\Infrastructure\Scheduler\TellLapsingSubscriptionsEveryHour;
use App\Module\Invoices\Infrastructure\Scheduler\RemindLateInvoicesEveryHour;
use App\Module\Recurring\Infrastructure\Scheduler\RunRecurringInvoicesEveryHour;
use App\Module\RecurringExpenses\Infrastructure\Scheduler\RunRecurringExpensesEveryHour;
use App\Module\Scanning\Infrastructure\Scheduler\ClearUntakenPhotos;
use App\Module\Vendors\Infrastructure\Scheduler\MarkKeptVendorContactsEveryNight;
use App\Records\Infrastructure\Scheduler\EndRemovalsEveryQuarterHour;
use App\Shared\Infrastructure\Scheduler\PurgeSubmissionsEveryHour;
use App\Tenancy\Infrastructure\Invitation\InvitationToMailHandler;
use App\Tenancy\Infrastructure\Scheduler\RemoveClosedCompaniesEveryNight;
use App\Tenancy\Infrastructure\Scheduler\TellRunOutInvitationsEveryHour;
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
        TendDemoSandboxesEveryFiveMinutes::class => 'each sandbox done with, locked by its own id, removed with the one company and the one owner it names; then no company: it counts the sandboxes, read across companies, and queues new ones, which have none yet',
        SandboxToBuildHandler::class => 'the sandbox by its id, and the company it opens for that sandbox alone, named by id in every step',
        EndErasuresEveryQuarterHour::class => 'each due erasure, locked by its own id, ends the company or account it names',
        EndRemovalsEveryQuarterHour::class => 'each due delete, locked by its own id and its company, ends in that company',
        FinalizeAccountDeletionsEveryNight::class => 'each due account, locked by its own id, no company: an account belongs to none, and its memberships go with it',
        InvitationToMailHandler::class => 'the invitation by its id, and the company that invitation names',
        NotificationToMailHandler::class => 'the person and the company the message names, both by id',
        PasswordResetAskedHandler::class => 'an address, no company: a password belongs to the account',
        PurgeFailedSignInAddressesEveryNight::class => 'every company at once, on purpose: a failed sign-in is purged by its age alone, and it names no company',
        SecurityNoticeToMailHandler::class => 'the account by its id, no company: how a person signs in belongs to the account',
        PurgeSubmissionsEveryHour::class => 'every company at once, on purpose: a write\'s key is forgotten by its age alone',
        RemoveClosedCompaniesEveryNight::class => 'each closed company whose retention has ended, by its own id, removed whole in its own transaction',
        MarkKeptCustomerContactsEveryNight::class => 'each customer keeping a refused value, of an active company with the customers module on, handed to the use case with its company',
        MarkKeptVendorContactsEveryNight::class => 'each vendor keeping a refused value, of an active company with the vendors module on, handed to the use case with its company',
        TellRunOutInvitationsEveryHour::class => 'every company at once, on purpose: an invitation runs out by its date alone, and each one told names its own company, to its sender only while still a member of it',
        TellLapsingSubscriptionsEveryHour::class => 'every company at once, on purpose: a subscription ends by its date alone, and each one told names its own company, to that company\'s owners only',
        RemindLateInvoicesEveryHour::class => 'each active company with the invoices module on, handed to the use case one at a time',
        RunRecurringExpensesEveryHour::class => 'each active company with the recurring expenses module on, handed to the use case one at a time',
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

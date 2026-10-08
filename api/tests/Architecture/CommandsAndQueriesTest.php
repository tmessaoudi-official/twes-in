<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Audit\Application\AuditTrail;
use App\Files\Application\Attachments;
use App\Files\Application\Files;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Identity\Application\Login\PasswordAttempts;
use App\Identity\Application\Mfa\BeginTotpEnrolment;
use App\Identity\Application\Mfa\PasskeyAssertions;
use App\ImportExport\Application\RunImport;
use App\Inbox\Application\NotificationCentre;
use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\Inventory\Application\DrawStockMap;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Invoices\Application\PrintInvoice;
use App\Module\Invoices\Application\RemindLateInvoices;
use App\Module\Scanning\Application\PhonePairings;
use App\Settings\Application\ForgetSettings;
use App\Shared\Application\DomainEvents;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Invitation\MailInvitation;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Application\Session\PinCompanyAtSignIn;
use App\Tenancy\Application\Session\SwitchWorkingCompany;
use App\Tenancy\Application\Signup\RequestSignup;
use PHPUnit\Framework\TestCase;

/**
 * Light CQRS (docs/SPEC.md § 3): every use case in a context's `Application/` is a command, which changes state in one
 * transaction and records its audit entry (also the live signal screens reload on), or a query, which only reads.
 * There is no bus, so the split is read off the use case itself: a command takes the transaction port, a query does
 * not. What departs from it is listed below with its reason; a new use case that departs reds until it is split or
 * listed, and a listed one that no longer departs reds until it is struck off.
 */
final class CommandsAndQueriesTest extends TestCase
{
    /** A call on a held dependency that stores, removes or records something; `recorded…` reads what was. */
    private const string WRITE = '/\$this->\w+->(save\w*|remove\w*|delete\w*|store\w*|record(?!ed)\w*|persist|flush)\(/';

    /** Use cases that write without the transaction port of their own, and why. */
    private const array WRITE_OUTSIDE_A_TRANSACTION = [
        Attachments::class => 'a part of the use case attaching a file, inside its transaction and its audit entry',
        Files::class => 'the bytes of a file, stored by the use case that keeps them, inside its transaction',
        ProvisionCompany::class => 'a part of creating a company and of the seed, inside their transaction',
        SyncCustomerTaxRegimes::class => 'release data converged at every start, which no person changes',
        BeginTotpEnrolment::class => 'a pending secret that changes nothing about the account; confirming it is audited',
        PasskeyAssertions::class => "a passkey's signature counter, kept by the sign-in or step-up that audits it",
        NotificationCentre::class => 'whether a person has read their own notifications',
        PrintDeliveryNote::class => 'the PDF of a note already issued, stored once when issuing could not render it',
        PrintInvoice::class => 'the PDF of a document already issued, stored once when issuing could not render it',
        RemindLateInvoices::class => 'a scheduled run with no actor; the stage it records stops it telling twice, and its notification is the signal',
        ForgetSettings::class => 'a part of deleting the subject, whose own use case records the deletion',
        SeedPlatform::class => 'the seed and the convergence at start, which no person changes',
        PinCompanyAtSignIn::class => "a person's own choice of the company a sign-in opens",
        SwitchWorkingCompany::class => "the company a person's own session works in",
        RequestSignup::class => 'an anonymous request held until its link is followed; the account it leads to is audited',
    ];

    /** Commands whose change is on record otherwise than by an audit entry of their own, and how. */
    private const array RECORDED_OTHERWISE = [
        PasswordAttempts::class => 'a count of wrong guesses toward the lockout, not a change to the account',
        RunImport::class => "every row goes through the subject's own use case, which records it",
        DrawStockMap::class => 'it composes the venue and the stock locations, each recording its own entry',
        KeepStock::class => 'the movements are the record, each staged as a live change',
        MoveStockForDeliveryNotes::class => 'a part of validating or cancelling a delivery note, whose use case records it',
        PhonePairings::class => 'a phone lent to a tab for a while, nothing the company keeps',
        MailInvitation::class => 'the worker mailing an invitation whose creation was recorded',
        AllocateNumber::class => 'a part of issuing, inside the transaction of the document it numbers, which records it',
    ];

    /** @var array<class-string, list<string>>|null */
    private static ?array $useCases = null;

    public function testAQueryWritesNothing(): void
    {
        self::assertSame([], array_diff_key(self::writingOutsideATransaction(), self::listedWriters()), 'these use cases write without the transaction port: make them commands, or list them with the reason');
    }

    public function testACommandRecordsItsAuditEntry(): void
    {
        self::assertSame([], array_diff(self::unaudited(), array_keys(self::RECORDED_OTHERWISE)), 'these commands change state without an audit entry: record one, or list them with the reason');
    }

    public function testEveryListedUseCaseStillDeparts(): void
    {
        self::assertSame([], array_keys(array_diff_key(self::listedWriters(), self::writingOutsideATransaction())), 'these no longer write outside a transaction: strike them off');
        self::assertSame([], array_values(array_diff(array_keys(self::RECORDED_OTHERWISE), self::unaudited())), 'these commands now record an audit entry: strike them off');
    }

    public function testTheSweepFindsTheUseCases(): void
    {
        $commands = array_filter(self::useCases(), static fn (array $types): bool => \in_array(Transactions::class, $types, true));

        self::assertGreaterThanOrEqual(60, \count($commands), 'the sweep finds the commands');
        self::assertGreaterThanOrEqual(75, \count(self::useCases()) - \count($commands), 'the sweep finds the queries');
    }

    /** @return array<class-string, string> the use cases that write without the transaction port, with what they call */
    private static function writingOutsideATransaction(): array
    {
        $writing = [];
        foreach (self::useCases() as $class => $types) {
            if (\in_array(Transactions::class, $types, true)) {
                continue;
            }
            preg_match_all(self::WRITE, self::source($class), $calls);
            $reasons = [...array_intersect($types, [AuditTrail::class, DomainEvents::class]), ...array_unique($calls[1])];
            if ([] !== $reasons) {
                $writing[$class] = implode(', ', $reasons);
            }
        }

        return $writing;
    }

    /** @return list<class-string> the commands that take no audit trail */
    private static function unaudited(): array
    {
        $unaudited = [];
        foreach (self::useCases() as $class => $types) {
            if (\in_array(Transactions::class, $types, true) && !\in_array(AuditTrail::class, $types, true)) {
                $unaudited[] = $class;
            }
        }

        return $unaudited;
    }

    /** @return array<class-string, string> */
    private static function listedWriters(): array
    {
        return [...self::WRITE_OUTSIDE_A_TRANSACTION, ...AuditedChangesTest::AUTH_EVENTS];
    }

    /**
     * Every concrete class under an `Application/` directory that is handed a port: the use cases. A result, a request
     * or a refusal is handed values only, and an interface is the port itself.
     *
     * @return array<class-string, list<string>> the use case and the types its constructor takes
     */
    private static function useCases(): array
    {
        if (null !== self::$useCases) {
            return self::$useCases;
        }
        $root = \dirname(__DIR__, 2).'/src';
        $useCases = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : '';
            if (!str_ends_with($path, '.php') || !str_contains($path, '/Application/')) {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($root) + 1, -4));
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isEnum() || $reflection->implementsInterface(\Throwable::class)) {
                continue;
            }
            $types = [];
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
            }
            if ([] !== array_filter($types, interface_exists(...))) {
                $useCases[$class] = $types;
            }
        }
        ksort($useCases);

        return self::$useCases = $useCases;
    }

    /** @param class-string $class */
    private static function source(string $class): string
    {
        $path = new \ReflectionClass($class)->getFileName();

        return false === $path ? '' : (string) file_get_contents($path);
    }
}

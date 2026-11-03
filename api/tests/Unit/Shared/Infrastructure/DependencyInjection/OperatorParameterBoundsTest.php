<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\DependencyInjection;

use App\Demo\Infrastructure\Http\DemoBounds;
use App\Erasure\Infrastructure\Http\ErasureUndoBounds;
use App\Identity\Infrastructure\Mfa\PendingSecondFactorBounds;
use App\Identity\Infrastructure\Mfa\RecoveryCodeBounds;
use App\Identity\Infrastructure\Password\PasswordResetBounds;
use App\Identity\Infrastructure\Security\LockoutBounds;
use App\Identity\Infrastructure\Session\SessionBounds;
use App\Kernel;
use App\Licensing\Infrastructure\Scheduler\LapseNoticeBounds;
use App\Module\DeliveryNotes\Infrastructure\Watch\DraftWatchBounds;
use App\Records\Infrastructure\Http\RecordUndoBounds;
use App\Records\Infrastructure\Http\RestoreStepUpBounds;
use App\Shared\Infrastructure\ErrorReporting\ClientErrorBounds;
use App\Shared\Infrastructure\ErrorReporting\ErrorReportingMasksBounds;
use App\Shared\Infrastructure\Health\HealthBounds;
use App\Shared\Infrastructure\Http\LongRequestBounds;
use App\Shared\Infrastructure\Realtime\RealtimeTokenBounds;
use App\Shared\Infrastructure\Submissions\SubmissionRetryBounds;
use App\Tenancy\Infrastructure\Console\EarlierNumberingDaysBounds;
use App\Tenancy\Infrastructure\Http\SupportVisitBounds;
use App\Tenancy\Infrastructure\Invitation\InvitationBounds;
use App\Tenancy\Infrastructure\Scheduler\RemovalNoticeBounds;
use App\Tenancy\Infrastructure\Session\CompanyProofBounds;
use App\Tenancy\Infrastructure\Signup\SignupBounds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * What only the operator may set is a parameter of services.yaml, each held between a floor and a ceiling when the
 * container is built, so a deployment outside them never starts: no value an operator types can weaken the protection
 * the shipped value gives (a session's use noted an hour late, a support visit's pages unrecorded, a recovery set of
 * three codes, a reset link open for a week, an invitation open for a year, a mask that is no regular expression, a restore
 * switch written as text, a browser's report cut to nothing or sent without end, a lost write's key held past the API's
 * own memory of it, a signup link gone before its mail arrives, a delete or an erasure that cannot be taken back, or
 * whose copy outlives what the bin is for, a session or a company proof longer than ruled, a lockout of no time, a
 * realtime token outliving its session by a quarter hour, a long request shorter than an import).
 */
final class OperatorParameterBoundsTest extends TestCase
{
    /** What a shop sees before an operator edits anything: today's values, every one of them within its bounds. */
    public function testTheShippedValuesAreTodaysAndWithinTheBounds(): void
    {
        $parameters = $this->shipped();

        self::assertSame('PT5M', $parameters['app.session.touch_every']);
        self::assertSame('PT15M', $parameters['app.support.read_recorded_every']);
        self::assertSame(10, $parameters['app.recovery_codes.per_set']);
        self::assertSame('PT1H', $parameters['app.password_reset.valid_for']);
        self::assertSame('P7D', $parameters['app.invitation.valid_for']);
        self::assertSame([], $parameters['app.error_reporting.extra_masks']);
        self::assertFalse($parameters['app.step_up.on_restore'], 'a restore asks no proof until the operator says so');
        // ClientErrorController's constants and the web's MAX_REPORTS_PER_PAGE before they were parameters.
        self::assertSame(16_384, $parameters['app.client_errors.max_body']);
        self::assertSame(500, $parameters['app.client_errors.max_message']);
        self::assertSame(4_000, $parameters['app.client_errors.max_stack']);
        self::assertSame(5, $parameters['app.client_errors.per_page']);
        // The web's SUBMISSION_KEY_KEPT_MS (15 minutes) before it was the API's, below the day the API keeps a key.
        self::assertSame(15, $parameters['app.submissions.retry_minutes']);
        self::assertSame(24, $parameters['app.submissions.kept_hours']);
        // docs/START.md's « valid 24 hours », and the Corbeille's day of row 162.
        self::assertSame('PT24H', $parameters['app.signup.valid_for']);
        self::assertSame('PT24H', $parameters['app.records.undoable_for']);
        self::assertSame('PT24H', $parameters['app.erasure.undoable_for']);
        // The 30 minutes idle and 12 hours in all authentication was ruled with, the lockout's fifteen minutes, the company
        // proof's 30 minutes and 12 hours, the demo visitor's two hours away, the realtime token's two minutes.
        self::assertSame(1_800, $parameters['app.session.idle_ttl']);
        self::assertSame(43_200, $parameters['app.session.absolute_ttl']);
        self::assertSame('PT15M', $parameters['app.auth.lock_duration']);
        self::assertSame('PT5M', $parameters['app.mfa.pending_ttl']);
        self::assertSame('PT30M', $parameters['app.company_proof.idle']);
        self::assertSame('PT12H', $parameters['app.company_proof.lifetime']);
        self::assertSame(120, $parameters['app.realtime.token_lifetime']);
        self::assertSame('P30D', $parameters['app.invitation.expired_shown_for']);
        self::assertSame(7_200, $parameters['app.demo.idle_ttl']);
        self::assertSame('PT1H', $parameters['app.demo.build_gives_up_after']);
        self::assertSame(240, $parameters['app.long_request.max_seconds']);
        self::assertSame(600, $parameters['app.health.oldest_waiting_seconds']);
        self::assertSame(2, $parameters['app.watch.drafts_to_validate_after_hours']);
        self::assertSame(30, $parameters['app.company.removal_notice_days']);
        self::assertSame(7, $parameters['app.subscription.lapse_notice_days']);
        self::assertSame(72, $parameters['app.numbering.earlier_days_hours_max']);

        $container = new ContainerBuilder();
        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
        foreach (self::passes() as $pass) {
            $pass->process($container);
        }
        $this->addToAssertionCount(1);
    }

    /** A pass the kernel does not add checks nothing: every one is added where the container is built. */
    public function testTheKernelAddsEveryPass(): void
    {
        $container = new ContainerBuilder();
        $build = new \ReflectionMethod(Kernel::class, 'build');
        $build->invoke(new Kernel('test', false), $container);

        $added = array_map(static fn (CompilerPassInterface $pass): string => $pass::class, $container->getCompilerPassConfig()->getPasses());
        foreach (self::passes() as $pass) {
            self::assertContains($pass::class, $added);
        }
    }

    /** @return iterable<string, array{0: string, 1: array<mixed>|bool|int|string, 2?: array<string, int|string>}> */
    public static function withinBounds(): iterable
    {
        yield 'every restore asks a proof' => ['app.step_up.on_restore', true];
        yield 'a restore asks no proof' => ['app.step_up.on_restore', false];
        yield 'a session noted every minute' => ['app.session.touch_every', 'PT1M'];
        yield 'a session noted every quarter hour' => ['app.session.touch_every', 'PT15M'];
        yield 'every support read recorded' => ['app.support.read_recorded_every', 'PT0M'];
        yield 'a support read recorded once an hour' => ['app.support.read_recorded_every', 'PT1H'];
        yield 'eight recovery codes' => ['app.recovery_codes.per_set', 8];
        yield 'sixteen recovery codes' => ['app.recovery_codes.per_set', 16];
        yield 'a reset link for a quarter hour' => ['app.password_reset.valid_for', 'PT15M'];
        yield 'a reset link for a day' => ['app.password_reset.valid_for', 'PT24H'];
        yield 'an invitation for a day' => ['app.invitation.valid_for', 'P1D'];
        yield 'an invitation for thirty days' => ['app.invitation.valid_for', 'P30D'];
        yield 'a report body of 1 KiB' => ['app.client_errors.max_body', 1_024];
        yield 'a report body of 64 KiB' => ['app.client_errors.max_body', 65_536];
        yield 'a message of 100 characters' => ['app.client_errors.max_message', 100];
        yield 'a message of 2,000 characters' => ['app.client_errors.max_message', 2_000];
        yield 'a stack of 500 characters' => ['app.client_errors.max_stack', 500];
        yield 'a stack of 16,000 characters' => ['app.client_errors.max_stack', 16_000];
        yield 'no report from a page' => ['app.client_errors.per_page', 0];
        yield 'twenty reports from a page' => ['app.client_errors.per_page', 20];
        yield 'a lost write retried within a minute' => ['app.submissions.retry_minutes', 1];
        yield 'a lost write retried within a minute less than a day' => ['app.submissions.retry_minutes', 1_439];
        yield 'a signup link for an hour' => ['app.signup.valid_for', 'PT1H'];
        yield 'a signup link for a week' => ['app.signup.valid_for', 'P7D'];
        yield 'a delete taken back within an hour' => ['app.records.undoable_for', 'PT1H'];
        yield 'a delete taken back within thirty days' => ['app.records.undoable_for', 'P30D'];
        yield 'an erasure taken back within an hour' => ['app.erasure.undoable_for', 'PT1H'];
        yield 'an erasure taken back within a week' => ['app.erasure.undoable_for', 'P7D'];
        yield 'a session idle for five minutes' => ['app.session.idle_ttl', 300];
        yield 'a session idle for thirty minutes' => ['app.session.idle_ttl', 1_800];
        // A demo visitor is away no longer than any session lasts, so an hour in all asks the demo's hour too.
        yield 'a session of an hour in all' => ['app.session.absolute_ttl', 3_600, ['app.demo.idle_ttl' => 3_600]];
        yield 'a session of twelve hours in all' => ['app.session.absolute_ttl', 43_200];
        yield 'a lock of five minutes' => ['app.auth.lock_duration', 'PT5M'];
        yield 'a lock of a day' => ['app.auth.lock_duration', 'PT24H'];
        yield 'a second factor awaited a minute' => ['app.mfa.pending_ttl', 'PT1M'];
        yield 'a second factor awaited a quarter hour' => ['app.mfa.pending_ttl', 'PT15M'];
        yield 'a company proof idle for five minutes' => ['app.company_proof.idle', 'PT5M'];
        yield 'a company proof idle for thirty minutes' => ['app.company_proof.idle', 'PT30M'];
        yield 'a company proof of an hour' => ['app.company_proof.lifetime', 'PT1H'];
        yield 'a company proof of twelve hours' => ['app.company_proof.lifetime', 'PT12H'];
        yield 'a realtime token of a minute' => ['app.realtime.token_lifetime', 60];
        yield 'a realtime token of five minutes' => ['app.realtime.token_lifetime', 300];
        yield 'an expired invitation shown a day' => ['app.invitation.expired_shown_for', 'P1D'];
        yield 'an expired invitation shown ninety days' => ['app.invitation.expired_shown_for', 'P90D'];
        yield 'a demo visitor away thirty minutes' => ['app.demo.idle_ttl', 1_800];
        yield 'a demo visitor away as long as a session lasts' => ['app.demo.idle_ttl', 43_200];
        yield 'a demo build given ten minutes' => ['app.demo.build_gives_up_after', 'PT10M'];
        yield 'a demo build given six hours' => ['app.demo.build_gives_up_after', 'PT6H'];
        yield 'a long request of a minute' => ['app.long_request.max_seconds', 60];
        yield 'a long request of a quarter hour' => ['app.long_request.max_seconds', 900];
        yield 'a message waiting a minute' => ['app.health.oldest_waiting_seconds', 60];
        yield 'a message waiting an hour' => ['app.health.oldest_waiting_seconds', 3_600];
        yield 'a draft watched after an hour' => ['app.watch.drafts_to_validate_after_hours', 1];
        yield 'a draft watched after three days' => ['app.watch.drafts_to_validate_after_hours', 72];
        yield 'a removal told a week before' => ['app.company.removal_notice_days', 7];
        yield 'a removal told ninety days before' => ['app.company.removal_notice_days', 90];
        yield 'a lapse told a day before' => ['app.subscription.lapse_notice_days', 1];
        yield 'a lapse told thirty days before' => ['app.subscription.lapse_notice_days', 30];
        yield 'earlier days for an hour' => ['app.numbering.earlier_days_hours_max', 1];
        yield 'earlier days for three days' => ['app.numbering.earlier_days_hours_max', 72];
        yield 'a mask of the operator' => ['app.error_reporting.extra_masks', [['pattern' => '/\bTN\d{2}\b/', 'replacement' => '[branch]']]];
    }

    /**
     * @param array<mixed>|bool|int|string $value
     * @param array<string, int|string>    $beside other parameters a bound is measured against, set first
     */
    #[DataProvider('withinBounds')]
    public function testAValueWithinItsBoundsIsKept(string $parameter, array|bool|int|string $value, array $beside = []): void
    {
        $container = $this->withShipped($parameter, $value, $beside);
        foreach (self::passes() as $pass) {
            $pass->process($container);
        }
        self::assertSame($value, $container->getParameter($parameter));
    }

    /** @return iterable<string, array{0: string, 1: array<mixed>|int|string, 2?: array<string, int|string>}> */
    public static function outOfBounds(): iterable
    {
        yield 'a session noted every 59 seconds' => ['app.session.touch_every', 'PT59S'];
        yield 'a session noted every 16 minutes' => ['app.session.touch_every', 'PT16M'];
        yield 'a session noted by no duration' => ['app.session.touch_every', 'five minutes'];
        yield 'support reads recorded every 61 minutes' => ['app.support.read_recorded_every', 'PT61M'];
        yield 'support reads recorded once a day' => ['app.support.read_recorded_every', 'P1D'];
        yield 'seven recovery codes' => ['app.recovery_codes.per_set', 7];
        yield 'seventeen recovery codes' => ['app.recovery_codes.per_set', 17];
        yield 'recovery codes as text' => ['app.recovery_codes.per_set', '10'];
        yield 'a reset link for 14 minutes' => ['app.password_reset.valid_for', 'PT14M'];
        yield 'a reset link for 25 hours' => ['app.password_reset.valid_for', 'PT25H'];
        yield 'an invitation for 23 hours' => ['app.invitation.valid_for', 'PT23H'];
        yield 'an invitation for 31 days' => ['app.invitation.valid_for', 'P31D'];
        yield 'a mask that is no regular expression' => ['app.error_reporting.extra_masks', [['pattern' => '/(unclosed/', 'replacement' => 'x']]];
        yield 'a mask with no replacement' => ['app.error_reporting.extra_masks', [['pattern' => '/x/']]];
        yield 'a mask with a key of its own' => ['app.error_reporting.extra_masks', [['pattern' => '/x/', 'replacement' => 'y', 'flags' => 'i']]];
        yield 'masks as a map' => ['app.error_reporting.extra_masks', ['/x/' => 'y']];
        yield 'masks as text' => ['app.error_reporting.extra_masks', '/x/'];
        // YAML reads « yes », « no », « on » and « off » as text, which a bool argument would take as true.
        yield 'a restore proof as « no »' => ['app.step_up.on_restore', 'no'];
        yield 'a restore proof as 1' => ['app.step_up.on_restore', 1];
        yield 'a report body of 1,023 bytes' => ['app.client_errors.max_body', 1_023];
        yield 'a report body of 64 KiB and one' => ['app.client_errors.max_body', 65_537];
        yield 'a report body as text' => ['app.client_errors.max_body', '16384'];
        yield 'a message of 99 characters' => ['app.client_errors.max_message', 99];
        yield 'a message of 2,001 characters' => ['app.client_errors.max_message', 2_001];
        yield 'a stack of 499 characters' => ['app.client_errors.max_stack', 499];
        yield 'a stack of 16,001 characters' => ['app.client_errors.max_stack', 16_001];
        yield 'reports from a page below none' => ['app.client_errors.per_page', -1];
        yield 'twenty-one reports from a page' => ['app.client_errors.per_page', 21];
        yield 'a lost write retried at once' => ['app.submissions.retry_minutes', 0];
        yield 'a lost write retried as long as the API keeps it' => ['app.submissions.retry_minutes', 1_440];
        yield 'a lost write retried longer than the API keeps it' => ['app.submissions.retry_minutes', 2_000];
        yield 'a lost write retried as text' => ['app.submissions.retry_minutes', '15'];
        yield 'a signup link for 59 minutes' => ['app.signup.valid_for', 'PT59M'];
        yield 'a signup link for eight days' => ['app.signup.valid_for', 'P8D'];
        yield 'a signup link by no duration' => ['app.signup.valid_for', '24 hours'];
        yield 'a delete taken back within 59 minutes' => ['app.records.undoable_for', 'PT59M'];
        yield 'a delete taken back within 31 days' => ['app.records.undoable_for', 'P31D'];
        yield 'a delete taken back by no duration' => ['app.records.undoable_for', 'a day'];
        yield 'an erasure taken back within 59 minutes' => ['app.erasure.undoable_for', 'PT59M'];
        yield 'an erasure taken back within eight days' => ['app.erasure.undoable_for', 'P8D'];
        yield 'an erasure taken back by no duration' => ['app.erasure.undoable_for', 'PT24'];
        yield 'a session idle for 299 seconds' => ['app.session.idle_ttl', 299];
        yield 'a session idle for 1,801 seconds' => ['app.session.idle_ttl', 1_801];
        yield 'a session idle as text' => ['app.session.idle_ttl', '1800'];
        yield 'a session of 3,599 seconds in all' => ['app.session.absolute_ttl', 3_599];
        yield 'a session of 43,201 seconds in all' => ['app.session.absolute_ttl', 43_201];
        yield 'a session in all as no number' => ['app.session.absolute_ttl', 'PT12H'];
        yield 'a lock of none' => ['app.auth.lock_duration', 'PT0S'];
        yield 'a lock of 4 minutes' => ['app.auth.lock_duration', 'PT4M'];
        yield 'a lock of 25 hours' => ['app.auth.lock_duration', 'PT25H'];
        yield 'a lock by no duration' => ['app.auth.lock_duration', '15 minutes'];
        yield 'a second factor awaited 59 seconds' => ['app.mfa.pending_ttl', 'PT59S'];
        yield 'a second factor awaited 16 minutes' => ['app.mfa.pending_ttl', 'PT16M'];
        yield 'a second factor awaited by no duration' => ['app.mfa.pending_ttl', 'PT5'];
        yield 'a company proof idle for 4 minutes' => ['app.company_proof.idle', 'PT4M'];
        yield 'a company proof idle for 31 minutes' => ['app.company_proof.idle', 'PT31M'];
        yield 'a company proof idle by no duration' => ['app.company_proof.idle', 1_800];
        yield 'a company proof of 59 minutes' => ['app.company_proof.lifetime', 'PT59M'];
        yield 'a company proof of 13 hours' => ['app.company_proof.lifetime', 'PT13H'];
        yield 'a company proof by no duration' => ['app.company_proof.lifetime', 'twelve hours'];
        yield 'a realtime token of 59 seconds' => ['app.realtime.token_lifetime', 59];
        yield 'a realtime token of 301 seconds' => ['app.realtime.token_lifetime', 301];
        yield 'a realtime token as text' => ['app.realtime.token_lifetime', '120'];
        yield 'an expired invitation shown 23 hours' => ['app.invitation.expired_shown_for', 'PT23H'];
        yield 'an expired invitation shown 91 days' => ['app.invitation.expired_shown_for', 'P91D'];
        yield 'an expired invitation shown by no duration' => ['app.invitation.expired_shown_for', '30 days'];
        yield 'a demo visitor away 1,799 seconds' => ['app.demo.idle_ttl', 1_799];
        yield 'a demo visitor away 43,201 seconds' => ['app.demo.idle_ttl', 43_201];
        yield 'a demo visitor away as text' => ['app.demo.idle_ttl', '7200'];
        // SessionTimeoutListener ends every session past app.session.absolute_ttl, a demo's too: a longer stay would never be.
        yield 'a demo visitor away longer than a session lasts' => ['app.demo.idle_ttl', 7_200, ['app.session.absolute_ttl' => 3_600]];
        yield 'a demo build given 9 minutes' => ['app.demo.build_gives_up_after', 'PT9M'];
        yield 'a demo build given 7 hours' => ['app.demo.build_gives_up_after', 'PT7H'];
        yield 'a demo build by no duration' => ['app.demo.build_gives_up_after', 'an hour'];
        yield 'a long request of 59 seconds' => ['app.long_request.max_seconds', 59];
        yield 'a long request of 901 seconds' => ['app.long_request.max_seconds', 901];
        yield 'a long request as text' => ['app.long_request.max_seconds', '240'];
        yield 'a message waiting 59 seconds' => ['app.health.oldest_waiting_seconds', 59];
        yield 'a message waiting 3,601 seconds' => ['app.health.oldest_waiting_seconds', 3_601];
        yield 'a message waiting as text' => ['app.health.oldest_waiting_seconds', '600'];
        yield 'a draft watched at once' => ['app.watch.drafts_to_validate_after_hours', 0];
        yield 'a draft watched after 73 hours' => ['app.watch.drafts_to_validate_after_hours', 73];
        yield 'a draft watched as text' => ['app.watch.drafts_to_validate_after_hours', '2'];
        yield 'a removal told 6 days before' => ['app.company.removal_notice_days', 6];
        yield 'a removal told 91 days before' => ['app.company.removal_notice_days', 91];
        yield 'a removal told as text' => ['app.company.removal_notice_days', '30'];
        yield 'a lapse told the day it lapses' => ['app.subscription.lapse_notice_days', 0];
        yield 'a lapse told 31 days before' => ['app.subscription.lapse_notice_days', 31];
        yield 'a lapse told as text' => ['app.subscription.lapse_notice_days', '7'];
        yield 'earlier days for no hour' => ['app.numbering.earlier_days_hours_max', 0];
        yield 'earlier days for 73 hours' => ['app.numbering.earlier_days_hours_max', 73];
        yield 'earlier days as text' => ['app.numbering.earlier_days_hours_max', '72'];
        // The window is held below what the API keeps, so what the API keeps must be a number to compare with.
        yield 'keys kept for no hour' => ['app.submissions.kept_hours', 0];
        yield 'keys kept as text' => ['app.submissions.kept_hours', '24'];
    }

    /**
     * @param array<mixed>|int|string   $value
     * @param array<string, int|string> $beside other parameters a bound is measured against, set first
     */
    #[DataProvider('outOfBounds')]
    public function testAValueOutOfItsBoundsStopsTheContainer(string $parameter, array|int|string $value, array $beside = []): void
    {
        $container = $this->withShipped($parameter, $value, $beside);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($parameter, '/').'/');

        foreach (self::passes() as $pass) {
            $pass->process($container);
        }
    }

    /** @return iterable<string, array{string, int, array<string, int>, string}> */
    public static function boundByAnother(): iterable
    {
        yield 'a demo visitor away longer than a session lasts' => ['app.demo.idle_ttl', 7_200, ['app.session.absolute_ttl' => 3_600], 'app.demo.idle_ttl is a whole number from 1800 to 3600 (at most app.session.absolute_ttl); 7200 is not.'];
        yield 'a lost write retried longer than the API keeps it' => ['app.submissions.retry_minutes', 2_000, [], 'app.submissions.retry_minutes is a whole number from 1 to 1439 (less than app.submissions.kept_hours in minutes); 2000 is not.'];
    }

    /**
     * A ceiling read from another parameter says which: an operator who shortened that one is refused a value they did
     * not touch, and reads where the ceiling came from.
     *
     * @param array<string, int> $beside
     */
    #[DataProvider('boundByAnother')]
    public function testACeilingTakenFromAnotherParameterNamesIt(string $parameter, int $value, array $beside, string $said): void
    {
        $container = $this->withShipped($parameter, $value, $beside);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($said);

        foreach (self::passes() as $pass) {
            $pass->process($container);
        }
    }

    /** @return list<CompilerPassInterface> */
    private static function passes(): array
    {
        return [new SessionBounds(), new SupportVisitBounds(), new RecoveryCodeBounds(), new PasswordResetBounds(), new InvitationBounds(), new ErrorReportingMasksBounds(), new RestoreStepUpBounds(), new ClientErrorBounds(), new SubmissionRetryBounds(), new SignupBounds(), new RecordUndoBounds(), new ErasureUndoBounds(), new LockoutBounds(), new PendingSecondFactorBounds(), new CompanyProofBounds(), new RealtimeTokenBounds(), new DemoBounds(), new LongRequestBounds(), new HealthBounds(), new DraftWatchBounds(), new RemovalNoticeBounds(), new LapseNoticeBounds(), new EarlierNumberingDaysBounds()];
    }

    /**
     * @param array<mixed>|bool|float|int|string|null $value
     * @param array<string, int|string>               $beside
     */
    private function withShipped(string $parameter, mixed $value, array $beside = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ($this->shipped() as $name => $shipped) {
            $container->setParameter($name, $shipped);
        }
        foreach ($beside as $name => $besideValue) {
            $container->setParameter($name, $besideValue);
        }
        $container->setParameter($parameter, $value);

        return $container;
    }

    /** @return array<string, array<mixed>|bool|float|int|string|null> the operator parameters as services.yaml ships them */
    private function shipped(): array
    {
        $file = Yaml::parseFile(__DIR__.'/../../../../../config/services.yaml', Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($file);
        self::assertIsArray($file['parameters'] ?? null);
        $names = ['app.session.touch_every', 'app.support.read_recorded_every', 'app.recovery_codes.per_set', 'app.password_reset.valid_for', 'app.invitation.valid_for', 'app.error_reporting.extra_masks', 'app.step_up.on_restore', 'app.client_errors.max_body', 'app.client_errors.max_message', 'app.client_errors.max_stack', 'app.client_errors.per_page', 'app.submissions.retry_minutes', 'app.submissions.kept_hours', 'app.signup.valid_for', 'app.records.undoable_for', 'app.erasure.undoable_for', 'app.session.idle_ttl', 'app.session.absolute_ttl', 'app.auth.lock_duration', 'app.mfa.pending_ttl', 'app.company_proof.idle', 'app.company_proof.lifetime', 'app.realtime.token_lifetime', 'app.invitation.expired_shown_for', 'app.demo.idle_ttl', 'app.demo.build_gives_up_after', 'app.long_request.max_seconds', 'app.health.oldest_waiting_seconds', 'app.watch.drafts_to_validate_after_hours', 'app.company.removal_notice_days', 'app.subscription.lapse_notice_days', 'app.numbering.earlier_days_hours_max'];
        $shipped = [];
        foreach ($names as $name) {
            self::assertArrayHasKey($name, $file['parameters']);
            $value = $file['parameters'][$name];
            self::assertTrue(\is_array($value) || \is_scalar($value) || null === $value, $name.' is a value a container holds');
            $shipped[$name] = $value;
        }

        return $shipped;
    }
}

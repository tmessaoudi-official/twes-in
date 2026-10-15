<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Recurring\Application\ManageRecurringInvoices;
use App\Module\Recurring\Application\RunRecurringInvoices;
use App\Module\Recurring\Infrastructure\Scheduler\RunRecurringInvoicesEveryHour;
use App\Shared\Domain\Schedule;
use App\Shared\Domain\ScheduleAnchor;
use App\Shared\Domain\ScheduleUnit;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261015212800;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Recurring invoices (docs/SPEC.md § 7, 2026-09-20, row 86): made from one of the company's invoices, each occurrence
 * of the schedule becomes a new draft copied from it, for a person to issue; nothing is issued. A pass drafts each
 * occurrence once however often it runs, a paused schedule drafts nothing and drafts again from the next occurrence to
 * come, and one past its last day is over. Whoever writes invoices is told a draft waits. The schedule is the shared
 * one (docs/SPEC.md § 7, 2026-10-15 17:46): any interval, a month or a year on a date, its last day or a weekday.
 */
final class RecurringInvoicesTest extends ApiTestCase
{
    private const array WEEK = ['unit' => 'week', 'every' => 1];

    private Company $company;
    private string $customerId;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->userId = $this->createUser('sales@twes.local', 'password-1234', $this->company, ['company.read', 'customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit'], 'member')->getId()->toRfc4122();
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['company.read', 'invoice.read'], 'reader');
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnInvoiceMadeRecurringIsDraftedOnceOnEachOccurrence(): void
    {
        $model = $this->issued('250');
        $recurring = $this->recurring($model, $this->monthly($this->day(0)), $this->day(0));
        $this->getJson($this->path().'/recurring-invoices/'.$recurring);
        $made = $this->json();
        self::assertSame(['month', 1, 'date', $this->monthDay($this->day(0)), $this->day(0), $this->day(0), 0, false, 'Carthage Conseil'], [$made['unit'], $made['every'], $made['anchor'], $made['monthDay'], $made['startsOn'], $made['nextOn'], $made['drafted'], $made['paused'], $made['customerName']]);
        self::assertNotNull($made['modelNumber'] ?? null, 'the model is issued, so it has its number');

        $this->passEachDay();
        $this->passEachDay();

        $drafts = $this->draftsOf($recurring);
        self::assertCount(1, $drafts, 'a pass run twice drafts the occurrence once');
        $this->getJson($this->path().'/invoices/'.$drafts[0]);
        $lines = $this->arrayAt($this->json(), 'lines');
        self::assertSame(['draft', $this->customerId, '250.0000'], [$this->json()['status'], $this->json()['customerId'], \is_array($lines[0] ?? null) ? $lines[0]['unitPriceNet'] ?? null : null], 'a draft copied from the model, never issued');

        $this->getJson($this->path().'/recurring-invoices/'.$recurring);
        $after = $this->json();
        self::assertSame([1, $drafts[0], new \DateTimeImmutable($this->day(0))->modify('+1 month')->format('Y-m-d')], [$after['drafted'], $after['lastInvoiceId'], $after['nextOn']]);
        self::assertSame(['invoice.recurring_drafted'], $this->told(), 'whoever writes invoices is told a draft waits');
    }

    public function testOccurrencesMissedByTheWorkerAreDraftedOnTheNextPass(): void
    {
        $recurring = $this->recurring($this->issued('100'), self::WEEK, $this->day(0));

        $this->passEachDay(15);

        self::assertCount(3, $this->draftsOf($recurring), 'today, a week on and two weeks on');
        $this->getJson($this->path().'/recurring-invoices/'.$recurring);
        self::assertSame($this->day(21), $this->json()['nextOn']);
    }

    public function testAPausedOneDraftsNothingAndTakesUpFromTheNextOccurrenceToCome(): void
    {
        $recurring = $this->recurring($this->issued('100'), self::WEEK, $this->day(0));
        $this->revise($recurring, [...self::WEEK, 'endsOn' => null, 'paused' => true]);

        $this->passEachDay(10);
        self::assertSame([], $this->draftsOf($recurring), 'paused, nothing is drafted');

        // Taken up again on the tenth day, through the use case: a session does not outlive a clock moved forward.
        $day = $this->day(10);
        Clock::set(new MockClock(new \DateTimeImmutable($day.' 09:00', new \DateTimeZone($this->company->getTimezone()))));
        $resumed = $this->manage()->revise($this->company(), Uuid::fromString($recurring), new Schedule(ScheduleUnit::Week, 1), null, false, null);
        self::assertSame(new \DateTimeImmutable($day)->modify('+4 days')->format('Y-m-d'), $resumed->getRecurrence()->nextOn?->format('Y-m-d'), 'what fell due while paused is skipped');
        $this->runOn($day);
        self::assertSame([], $this->draftsOf($recurring));
    }

    public function testOnePastItsLastDayIsOver(): void
    {
        $recurring = $this->recurring($this->issued('100'), self::WEEK, $this->day(0), $this->day(8));

        $this->passEachDay(30);

        self::assertCount(2, $this->draftsOf($recurring), 'today and a week on; the next falls after the last day');
        $this->getJson($this->path().'/recurring-invoices/'.$recurring);
        self::assertNull($this->json()['nextOn'] ?? null);

        $going = $this->recurring($this->issued('100'), self::WEEK, $this->day(0));
        $this->getJson($this->path().'/recurring-invoices');
        self::assertSame([$going, $recurring], array_column($this->jsonList(), 'id'), 'the ones still drafting first, the ended after');
    }

    public function testAMonthKeepsItsDateAndFallsBackToTheMonthsLastDay(): void
    {
        $model = $this->issued('100');
        Clock::set(new MockClock(new \DateTimeImmutable('2027-01-20 09:00', new \DateTimeZone($this->company->getTimezone()))));
        $recurring = $this->manage()->create($this->company(), Uuid::fromString($model), new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Date, 31), new \DateTimeImmutable('2027-01-31'), null, null)->getId()->toRfc4122();
        $this->runOn('2027-01-31');
        $this->runOn('2027-02-28');
        $this->runOn('2027-03-31');

        self::assertCount(3, $this->draftsOf($recurring));
        self::assertSame('2027-04-30', $this->manage()->get($this->company(), Uuid::fromString($recurring))->getRecurrence()->nextOn?->format('Y-m-d'));
    }

    public function testAnyIntervalAndAMonthsLastWeekdayAreDraftedOnTheirDays(): void
    {
        $model = $this->issued('100');
        Clock::set(new MockClock(new \DateTimeImmutable('2027-01-20 09:00', new \DateTimeZone($this->company->getTimezone()))));
        $lastFriday = new Schedule(ScheduleUnit::Month, 2, ScheduleAnchor::LastWeekday, null, null, 5);
        $recurring = $this->manage()->create($this->company(), Uuid::fromString($model), $lastFriday, new \DateTimeImmutable('2027-01-20'), null, null)->getId()->toRfc4122();
        $this->runOn('2027-01-28');
        $this->runOn('2027-01-29');
        $this->runOn('2027-03-25');
        Clock::set(new NativeClock());

        self::assertCount(1, $this->draftsOf($recurring), 'the last Friday of January, and nothing on the days before the next');
        $this->getJson($this->path().'/recurring-invoices/'.$recurring);
        $read = $this->json();
        self::assertSame(['month', 2, 'last_weekday', null, null, 5, '2027-03-26'], [$read['unit'], $read['every'], $read['anchor'], $read['monthDay'] ?? null, $read['ordinal'] ?? null, $read['weekday'], $read['nextOn']], 'two months on, the last Friday of March');
    }

    public function testTheMigrationWritesEachFrequencyAsItsScheduleAndRefusesToGoBackFromOneItCannotSay(): void
    {
        $model = Uuid::fromString($this->issued('100'));
        Clock::set(new MockClock(new \DateTimeImmutable('2027-01-20 09:00', new \DateTimeZone($this->company->getTimezone()))));
        $made = [];
        foreach ([
            'weekly' => [new Schedule(ScheduleUnit::Week, 1), '2027-01-21'],
            'monthly' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Date, 31), '2027-01-31'],
            'quarterly' => [new Schedule(ScheduleUnit::Month, 3, ScheduleAnchor::Date, 30), '2027-04-30'],
            'yearly' => [new Schedule(ScheduleUnit::Year, 1, ScheduleAnchor::Date, 29), '2027-01-29'],
            'unsaid: a last weekday' => [new Schedule(ScheduleUnit::Month, 2, ScheduleAnchor::LastWeekday, null, null, 5), '2027-01-20'],
            'unsaid: every two months' => [new Schedule(ScheduleUnit::Month, 2, ScheduleAnchor::Date, 20), '2027-01-20'],
            'unsaid: not the first day\'s date' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Date, 25), '2027-01-20'],
            'unsaid: a year on its last day' => [new Schedule(ScheduleUnit::Year, 1, ScheduleAnchor::LastDay), '2027-01-20'],
            'unsaid: every day' => [new Schedule(ScheduleUnit::Day, 1), '2027-01-20'],
        ] as $frequency => [$schedule, $startsOn]) {
            $made[$frequency] = $this->manage()->create($this->company(), $model, $schedule, new \DateTimeImmutable($startsOn), null, null)->getId()->toRfc4122();
        }
        Clock::set(new NativeClock());
        $this->onACopyOfTheTable();

        try {
            $this->migrate('down');
            self::fail('a schedule no frequency says was dropped');
        } catch (AbortMigration $refused) {
            self::assertStringContainsString('5 recurring invoices recur on a schedule', $refused->getMessage());
        }
        $connection = $this->em()->getConnection();
        // The count is each of the five: none of them is said by a frequency, and none of the four said is counted.
        foreach ($made as $frequency => $id) {
            if (str_starts_with($frequency, 'unsaid')) {
                $connection->executeStatement('DELETE FROM recurring_invoice WHERE id = ?', [$id]);
                unset($made[$frequency]);
            }
        }
        $before = $this->schedules();

        $this->migrate('down');
        foreach ($made as $frequency => $id) {
            self::assertSame($frequency, $connection->fetchOne('SELECT frequency FROM recurring_invoice WHERE id = ?', [$id]), $frequency);
        }
        $this->migrate('up');
        self::assertSame($before, $this->schedules(), 'each frequency comes back as the schedule it was, its count and next day kept');
    }

    public function testOnlyAnInvoiceOfTheCompanyIsMadeRecurringAndFromTodayOn(): void
    {
        $issued = $this->issued('100');
        $this->postJson($this->path().'/invoices/'.$issued.'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $credit = $this->stringAt($this->json(), 'id');
        $cancelled = $this->drafted('100');
        $this->postJson($this->path().'/invoices/'.$cancelled.'/cancel', null);
        self::assertResponseIsSuccessful();

        foreach ([
            [[$credit, $this->day(0), null], [], 'modelInvoiceId', 'not_copiable'],
            [[$cancelled, $this->day(0), null], [], 'modelInvoiceId', 'not_copiable'],
            [['0199a1b2-0000-7000-8000-000000000000', $this->day(0), null], [], 'modelInvoiceId', 'unknown_choice'],
            [[$issued, $this->day(-1), null], [], 'startsOn', 'first_day_past'],
            [[$issued, '2026-02-30', null], [], 'startsOn', 'not_a_date'],
            [[$issued, $this->day(1), $this->day(0)], [], 'endsOn', 'last_day_before_first'],
            [[$issued, $this->day(0), null], ['every' => 0], 'every', 'invalid_value'],
            [[$issued, $this->day(0), null], ['unit' => 'month'], 'anchor', 'invalid_value'],
            [[$issued, $this->day(0), null], ['unit' => 'month', 'anchor' => 'weekday', 'ordinal' => 5, 'weekday' => 1], 'ordinal', 'invalid_value'],
        ] as [[$model, $startsOn, $endsOn], $changes, $field, $reason]) {
            $this->postJson($this->path().'/recurring-invoices', ['modelInvoiceId' => $model, ...self::WEEK, ...$changes, 'startsOn' => $startsOn, 'endsOn' => $endsOn]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertSame([['propertyPath' => $field, 'reason' => $reason, 'params' => []]], $this->refusals(), $field);
        }
        $this->postJson($this->path().'/recurring-invoices', ['modelInvoiceId' => $issued, 'unit' => 'fortnight', 'every' => 1, 'startsOn' => $this->day(0), 'endsOn' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'no such unit');
        self::assertSame('unit', $this->refusals()[0]['propertyPath'] ?? null);
    }

    public function testAModelThatCanNoLongerBeCopiedPausesItAndSaysSo(): void
    {
        $model = $this->drafted('100');
        $recurring = $this->recurring($model, self::WEEK, $this->day(0));
        $this->postJson($this->path().'/invoices/'.$model.'/cancel', null);
        self::assertResponseIsSuccessful();

        $this->passEachDay(8);

        self::assertSame([], $this->draftsOf($recurring), 'nothing is copied from a cancelled invoice');
        $this->getJson($this->path().'/recurring-invoices/'.$recurring);
        self::assertTrue($this->json()['paused'], 'it is paused rather than tried again every hour');
        self::assertSame(['invoice.recurring_stopped'], $this->told(), 'whoever writes invoices is told once, never left to find it stopped');
    }

    public function testReadingIsInvoiceReadAndTheRestInvoiceWriteAndDeletingKeepsTheDrafts(): void
    {
        $recurring = $this->recurring($this->issued('100'), $this->monthly($this->day(0)), $this->day(0));
        $this->passEachDay();
        $draft = $this->draftsOf($recurring)[0];

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path().'/recurring-invoices');
        self::assertResponseIsSuccessful();
        self::assertSame([$recurring], array_column($this->jsonList(), 'id'));
        $this->sendJson('DELETE', $this->path().'/recurring-invoices/'.$recurring);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a reader deletes nothing');

        $this->login('sales@twes.local', 'password-1234');
        $this->sendJson('DELETE', $this->path().'/recurring-invoices/'.$recurring);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path().'/invoices/'.$draft);
        self::assertResponseIsSuccessful('the draft it made stays');
    }

    public function testSwitchedOffItIsNeitherReadNorRun(): void
    {
        $recurring = $this->recurring($this->issued('100'), $this->monthly($this->day(0)), $this->day(0));
        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/modules/recurring', ['enabled' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a member does not switch modules');
        $this->login('owner@twes.local', 'password-1234');
        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/modules/recurring', ['enabled' => false]);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path().'/recurring-invoices');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        static::getContainer()->get(RunRecurringInvoicesEveryHour::class)();
        self::assertSame([], $this->draftsOf($recurring), 'the worker skips a company with the module off');
    }

    /**
     * Shadows the table with a temporary copy of its rows and checks: the role the suite connects as alters only what it
     * owns, and the case's transaction drops the copy. A temporary table comes first in PostgreSQL's search path, so the
     * migration's unqualified statements read and change the copy.
     */
    private function onACopyOfTheTable(): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('CREATE TEMPORARY TABLE recurring_invoice (LIKE public.recurring_invoice INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
        $connection->executeStatement('INSERT INTO recurring_invoice SELECT * FROM public.recurring_invoice');
    }

    /**
     * Runs the schedule migration's `up()` or `down()` inside the case's own transaction.
     *
     * @param 'up'|'down' $way
     */
    private function migrate(string $way): void
    {
        // Doctrine loads migrations from their directory, not the autoloader.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261015212800.php';
        $connection = $this->em()->getConnection();
        $migration = new Version20261015212800($connection, new NullLogger());
        $migration->{$way}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    /** @return list<array<string, mixed>> every recurring invoice's schedule and place in it, by id */
    private function schedules(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT id, schedule_unit, schedule_every, schedule_anchor, schedule_month_day, schedule_ordinal, schedule_weekday, starts_on, next_index, next_on FROM recurring_invoice ORDER BY id');
    }

    /** The recurring invoice's id. */
    /** @param array<string, int|string> $schedule */
    private function recurring(string $model, array $schedule, string $startsOn, ?string $endsOn = null): string
    {
        $this->postJson($this->path().'/recurring-invoices', ['modelInvoiceId' => $model, ...$schedule, 'startsOn' => $startsOn, 'endsOn' => $endsOn]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function revise(string $id, array $body): array
    {
        $this->sendJson('PUT', $this->path().'/recurring-invoices/'.$id, $body);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * Each month on the given day's date, as monthly was.
     *
     * @return array<string, int|string>
     */
    private function monthly(string $day): array
    {
        return ['unit' => 'month', 'every' => 1, 'anchor' => 'date', 'monthDay' => $this->monthDay($day)];
    }

    private function monthDay(string $day): int
    {
        return (int) new \DateTimeImmutable($day)->format('j');
    }

    /**
     * Runs the pass on each day from today to `$days` on, as the hourly task would early on each day, then puts the
     * clock back, so the session signed in today is still alive for what the case asks next.
     */
    private function passEachDay(int $days = 0): void
    {
        $days = array_map($this->day(...), range(0, $days));
        foreach ($days as $day) {
            $this->runOn($day);
        }
        Clock::set(new NativeClock());
    }

    private function runOn(string $day): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable($day.' 00:30', new \DateTimeZone($this->company->getTimezone()))));
        static::getContainer()->get(RunRecurringInvoices::class)->handle($this->company());
    }

    private function company(): Company
    {
        return $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
    }

    private function manage(): ManageRecurringInvoices
    {
        return static::getContainer()->get(ManageRecurringInvoices::class);
    }

    /** @return list<string> the drafts a recurring invoice made, oldest first */
    private function draftsOf(string $recurringId): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT entity_id FROM audit_log WHERE entity_type = 'invoice' AND action = 'invoice.created' AND changes->>'recurringInvoiceId' = :r ORDER BY at, entity_id",
            ['r' => $recurringId],
        );

        return array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', $ids);
    }

    /** @return list<string> what the member who writes invoices was told, oldest first */
    private function told(): array
    {
        return array_map(static fn (mixed $type): string => \is_string($type) ? $type : '', $this->em()->getConnection()->fetchFirstColumn("SELECT type FROM inbox_item WHERE recipient_id = :u AND type LIKE 'invoice.recurring_%' ORDER BY created_at", ['u' => $this->userId]));
    }

    /** The company's day, `$offset` days from today. */
    private function day(int $offset): string
    {
        return new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()))->modify(\sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function issued(string $net): string
    {
        $id = $this->drafted($net);
        $this->postJson($this->path().'/invoices/'.$id.'/issue', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    private function drafted(string $net): string
    {
        $this->postJson($this->path().'/invoices', [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Maintenance mensuelle', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}

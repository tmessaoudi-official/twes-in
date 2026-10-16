<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\RecurringExpenses\Application\RunRecurringExpenses;
use App\Module\RecurringExpenses\Infrastructure\Scheduler\RunRecurringExpensesEveryHour;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recurring expenses (docs/SPEC.md § 7, 2026-10-15 17:46, row 179): made from one of the company's expenses, each
 * occurrence of a schedule on any interval becomes a draft copy dated on its day, which a person checks and records;
 * nothing is recorded here. A variable amount drafts flagged « montant à vérifier », on the draft and in « À surveiller »,
 * until the draft is recorded. Whoever writes expenses is told a draft waits, and told once when the model can no
 * longer be copied.
 */
final class RecurringExpensesTest extends ApiTestCase
{
    private Company $company;
    private Vendor $vendor;
    private string $userId;
    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Steg'), new \DateTimeImmutable());
        $this->em()->persist($this->vendor);
        $this->em()->flush();
        $this->userId = $this->createUser('buyer@twes.local', 'password-1234', $this->company, ['company.read', 'expense.read', 'expense.write'], 'member')->getId()->toRfc4122();
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['expense.read'], 'reader');
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->login('buyer@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        $this->postJson($this->path().'/expense-categories', ['name' => 'Énergie', 'parentId' => null, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->categoryId = $this->stringAt($this->json(), 'id');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnExpenseMadeRecurringIsDraftedOnceOnEachOccurrenceAsACopyDatedOnItsDay(): void
    {
        $model = $this->expense();
        $recurring = $this->recurring($model, ['unit' => 'week', 'every' => 1]);
        $this->getJson($this->path().'/recurring-expenses/'.$recurring);
        $made = $this->json();
        self::assertSame(
            ['week', 1, null, $this->day(0), $this->day(0), 0, false, false, 'Électricité du local', 'Steg', '119.006', 'TND'],
            [$made['unit'], $made['every'], $made['anchor'], $made['startsOn'], $made['nextOn'], $made['drafted'], $made['paused'], $made['variableAmount'], $made['description'], $made['payee'], $made['amountGross'], $made['currency']],
        );

        $this->passEachDay();
        $this->passEachDay();

        $drafts = $this->draftsOf($recurring);
        self::assertCount(1, $drafts, 'a pass run twice drafts the occurrence once');
        $this->getJson($this->path().'/expenses/'.$drafts[0]);
        $draft = $this->json();
        $line = $this->arrayAt($draft, 'lines')[0] ?? null;
        self::assertIsArray($line);
        self::assertSame(
            ['draft', $this->day(0), null, 'Électricité du local', $this->vendor->getId()->toRfc4122(), $this->categoryId, 'Compteur 2', '100.005', [$this->taxId('TVA19')], false],
            [$draft['status'], $draft['date'], $draft['reference'], $draft['description'], $draft['vendorId'], $draft['categoryId'], $draft['notes'], $line['amountNet'], $line['taxComponentIds'], $draft['amountToCheck']],
            'a draft copied from the model on its day, without the supplier\'s own reference, never recorded',
        );

        $this->getJson($this->path().'/recurring-expenses/'.$recurring);
        $after = $this->json();
        self::assertSame([1, $drafts[0], $this->day(7)], [$after['drafted'], $after['lastExpenseId'], $after['nextOn']]);
        self::assertSame(['expense.recurring_drafted'], $this->told(), 'whoever writes expenses is told a draft waits');
    }

    public function testAVariableAmountDraftsFlaggedForCheckingUntilTheDraftIsRecorded(): void
    {
        $recurring = $this->recurring($this->expense(), ['unit' => 'week', 'every' => 1, 'variableAmount' => true]);

        $this->passEachDay();

        $draft = $this->draftsOf($recurring)[0];
        $this->getJson($this->path().'/expenses/'.$draft);
        self::assertTrue($this->json()['amountToCheck']);
        $this->getJson($this->path().'/watch/expenses.amount_to_check');
        self::assertResponseIsSuccessful();
        self::assertSame([$draft], array_column($this->jsonList(), 'subjectId'), 'and on « À surveiller »');

        $this->postJson($this->path().'/expenses/'.$draft.'/record', null);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['amountToCheck'], 'recording it is the check');
        $this->getJson($this->path().'/watch/expenses.amount_to_check');
        self::assertSame([], $this->jsonList());
    }

    public function testOccurrencesMissedByTheWorkerAreDraftedOnTheNextPassEverySoManyDays(): void
    {
        $recurring = $this->recurring($this->expense(), ['unit' => 'day', 'every' => 3]);

        $this->passEachDay(7);

        self::assertSame([$this->day(0), $this->day(3), $this->day(6)], array_map($this->dateOf(...), $this->draftsOf($recurring)), 'each draft on its own day');
        $this->getJson($this->path().'/recurring-expenses/'.$recurring);
        self::assertSame($this->day(9), $this->json()['nextOn']);
    }

    public function testAMonthFallsOnTheDayItIsAnchoredTo(): void
    {
        $recurring = $this->recurring($this->expense(), ['unit' => 'month', 'every' => 1, 'anchor' => 'last_day']);

        $this->getJson($this->path().'/recurring-expenses/'.$recurring);
        $today = new \DateTimeImmutable($this->day(0));
        self::assertSame(['last_day', $today->format('Y-m-t')], [$this->json()['anchor'], $this->json()['nextOn']]);

        $second = $this->recurring($this->expense(), ['unit' => 'year', 'every' => 1, 'anchor' => 'weekday', 'ordinal' => 2, 'weekday' => 2, 'monthDay' => 9]);
        $this->getJson($this->path().'/recurring-expenses/'.$second);
        self::assertSame([2, 2, null], [$this->json()['ordinal'], $this->json()['weekday'], $this->json()['monthDay']], 'what the anchor does not read is left out');
    }

    public function testAScheduleThatCannotRecurIsRefusedOnTheFieldThatSaysSo(): void
    {
        $model = $this->expense();
        $week = ['unit' => 'week', 'every' => 1];
        foreach ([
            [['modelExpenseId' => '0199a1b2-0000-7000-8000-000000000000'], 'modelExpenseId', 'unknown_choice'],
            [['startsOn' => $this->day(-1)], 'startsOn', 'first_day_past'],
            [['endsOn' => $this->day(-1)], 'endsOn', 'last_day_before_first'],
            [['every' => 0], 'every', 'invalid_value'],
            [['every' => 100], 'every', 'invalid_value'],
            [['unit' => 'month'], 'anchor', 'invalid_value'],
            [['unit' => 'month', 'anchor' => 'date', 'monthDay' => 32], 'monthDay', 'invalid_value'],
            [['unit' => 'month', 'anchor' => 'weekday', 'ordinal' => 5, 'weekday' => 1], 'ordinal', 'invalid_value'],
        ] as [$changes, $field, $reason]) {
            $this->postJson($this->path().'/recurring-expenses', [...$this->body($model, $week), ...$changes]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertSame([['propertyPath' => $field, 'reason' => $reason, 'params' => []]], $this->refusals(), $field);
        }
        $this->postJson($this->path().'/recurring-expenses', [...$this->body($model, $week), 'unit' => 'fortnight']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'no such unit');
        self::assertSame('unit', $this->refusals()[0]['propertyPath'] ?? null);
    }

    public function testAPausedOneDraftsNothingAndANewScheduleTakesUpFromToday(): void
    {
        $recurring = $this->recurring($this->expense(), ['unit' => 'week', 'every' => 1]);
        $this->revise($recurring, ['unit' => 'week', 'every' => 1, 'paused' => true]);

        $this->passEachDay(3);
        self::assertSame([], $this->draftsOf($recurring), 'paused, nothing is drafted');

        $revised = $this->revise($recurring, ['unit' => 'day', 'every' => 2, 'paused' => false, 'variableAmount' => true]);
        self::assertSame(['day', 2, $this->day(0), true], [$revised['unit'], $revised['every'], $revised['nextOn'], $revised['variableAmount']]);
    }

    public function testAModelThatCanNoLongerBeCopiedPausesItAndSaysSoOnce(): void
    {
        $deleted = $this->expense();
        $gone = $this->recurring($deleted, ['unit' => 'week', 'every' => 1]);
        $this->sendJson('DELETE', $this->path().'/expenses/'.$deleted);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->passEachDay(8);

        self::assertSame([], $this->draftsOf($gone), 'nothing is copied from an expense deleted since');
        $this->getJson($this->path().'/recurring-expenses/'.$gone);
        self::assertTrue($this->json()['paused'], 'it is paused rather than tried again every hour');
        self::assertSame(['expense.recurring_stopped'], $this->told(), 'told once, never left to find it stopped');
    }

    public function testAModelNamingAVendorStopsOnceVendorsIsSwitchedOff(): void
    {
        $recurring = $this->recurring($this->expense(), ['unit' => 'week', 'every' => 1]);
        $this->switchModule('vendors', false);

        $this->passEachDay();

        self::assertSame([], $this->draftsOf($recurring), 'a new expense names no vendor while Vendors is off, so no copy is made');
        $this->getJson($this->path().'/recurring-expenses/'.$recurring);
        self::assertTrue($this->json()['paused']);
    }

    public function testReadingIsExpenseReadAndTheRestExpenseWriteAndDeletingKeepsTheDrafts(): void
    {
        $model = $this->expense();
        $recurring = $this->recurring($model, ['unit' => 'month', 'every' => 1, 'anchor' => 'last_day', 'startsOn' => $this->lastDayOfThisMonth()]);
        $this->runOn($this->lastDayOfThisMonth());
        Clock::set(new NativeClock());
        $draft = $this->draftsOf($recurring)[0];

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path().'/recurring-expenses');
        self::assertResponseIsSuccessful();
        self::assertSame([$recurring], array_column($this->jsonList(), 'id'));
        $this->postJson($this->path().'/recurring-expenses', $this->body($model, ['unit' => 'week', 'every' => 1]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a reader makes nothing recurring');
        $this->sendJson('DELETE', $this->path().'/recurring-expenses/'.$recurring);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a reader deletes nothing');

        $this->login('buyer@twes.local', 'password-1234');
        $this->sendJson('DELETE', $this->path().'/recurring-expenses/'.$recurring);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path().'/expenses/'.$draft);
        self::assertResponseIsSuccessful('the draft it made stays');
    }

    public function testSwitchedOffItIsNeitherReadNorRun(): void
    {
        $recurring = $this->recurring($this->expense(), ['unit' => 'week', 'every' => 1]);
        $this->switchModule('recurring_expenses', false);
        $this->login('buyer@twes.local', 'password-1234');

        $this->getJson($this->path().'/recurring-expenses');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        Clock::set(new MockClock(new \DateTimeImmutable($this->day(0).' 00:30', new \DateTimeZone($this->company->getTimezone()))));
        static::getContainer()->get(RunRecurringExpensesEveryHour::class)();
        self::assertSame([], $this->draftsOf($recurring), 'the worker skips a company with the module off');
    }

    /**
     * The recurring expense's id.
     *
     * @param array<string, mixed> $schedule
     */
    private function recurring(string $model, array $schedule): string
    {
        $this->postJson($this->path().'/recurring-expenses', $this->body($model, $schedule));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /**
     * @param array<string, mixed> $schedule
     *
     * @return array<string, mixed>
     */
    private function body(string $model, array $schedule): array
    {
        return [...['modelExpenseId' => $model, 'anchor' => null, 'monthDay' => null, 'ordinal' => null, 'weekday' => null, 'startsOn' => $this->day(0), 'endsOn' => null, 'variableAmount' => false], ...$schedule];
    }

    /**
     * @param array<string, mixed> $schedule
     *
     * @return array<string, mixed>
     */
    private function revise(string $id, array $schedule): array
    {
        $this->sendJson('PUT', $this->path().'/recurring-expenses/'.$id, [...['anchor' => null, 'monthDay' => null, 'ordinal' => null, 'weekday' => null, 'endsOn' => null, 'paused' => false, 'variableAmount' => false], ...$schedule]);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** A draft expense to copy: one line at the standard rate, under a category, from a vendor, with its reference. */
    private function expense(): string
    {
        $this->postJson($this->path().'/expenses', [
            'date' => $this->day(-20),
            'reference' => 'F-2026-118',
            'description' => 'Électricité du local',
            'vendorId' => $this->vendor->getId()->toRfc4122(),
            'categoryId' => $this->categoryId,
            'lines' => [['amountNet' => '100.005', 'taxComponentIds' => [$this->taxId('TVA19')]]],
            'notes' => 'Compteur 2',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function switchModule(string $key, bool $enabled): void
    {
        $this->login('owner@twes.local', 'password-1234');
        $this->sendJson('PUT', $this->path().'/modules/'.$key, ['enabled' => $enabled]);
        self::assertResponseIsSuccessful($key);
    }

    /**
     * Runs the pass on each day from today to `$days` on, as the hourly task would early on each day, then puts the
     * clock back, so the session signed in today is still alive for what the case asks next.
     */
    private function passEachDay(int $days = 0): void
    {
        foreach (array_map($this->day(...), range(0, $days)) as $day) {
            $this->runOn($day);
        }
        Clock::set(new NativeClock());
    }

    private function runOn(string $day): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable($day.' 00:30', new \DateTimeZone($this->company->getTimezone()))));
        static::getContainer()->get(RunRecurringExpenses::class)->handle($this->company());
    }

    private function company(): Company
    {
        return $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
    }

    /** @return list<string> the drafts a recurring expense made, oldest first */
    private function draftsOf(string $recurringId): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT entity_id FROM audit_log WHERE entity_type = 'expense' AND action = 'expense.created' AND changes->>'recurringExpenseId' = :r ORDER BY at, entity_id",
            ['r' => $recurringId],
        );

        return array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', $ids);
    }

    private function dateOf(string $expenseId): string
    {
        $date = $this->em()->getConnection()->fetchOne('SELECT expense_date FROM expense WHERE id = ?', [$expenseId]);
        self::assertIsString($date);

        return $date;
    }

    /** @return list<string> what the member who writes expenses was told, oldest first */
    private function told(): array
    {
        return array_map(static fn (mixed $type): string => \is_string($type) ? $type : '', $this->em()->getConnection()->fetchFirstColumn("SELECT type FROM inbox_item WHERE recipient_id = :u AND type LIKE 'expense.recurring_%' ORDER BY created_at", ['u' => $this->userId]));
    }

    /** The company's day, `$offset` days from today. */
    private function day(int $offset): string
    {
        return new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()))->modify(\sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function lastDayOfThisMonth(): string
    {
        return new \DateTimeImmutable($this->day(0))->format('Y-m-t');
    }

    private function taxId(string $code): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->company->getId()->toRfc4122(), $code]);
        self::assertIsString($id, $code);

        return $id;
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}

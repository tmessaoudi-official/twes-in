<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\RecurringExpenses\Application\RecurringExpenseModel;
use App\Module\RecurringExpenses\Domain\RecurringExpense;
use App\Shared\Domain\ScheduleAnchor;
use App\Shared\Domain\ScheduleUnit;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's recurring expenses: read with expense.read, made from one of its expenses, revised and deleted with
 * expense.write. The schedule is written flat: every so many days, weeks, months or years, a month or a year on the
 * day it is anchored to. A schedule that cannot recur, a model that is not an expense of the company, or a first day
 * already past answers 422 on the field that says so.
 */
#[ApiResource(
    shortName: 'RecurringExpense',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/recurring-expenses',
            provider: RecurringExpenseCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/recurring-expenses/{recurringExpenseId}',
            provider: RecurringExpenseItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/recurring-expenses',
            processor: CreateRecurringExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::CREATE]],
            validationContext: ['groups' => [self::CREATE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/recurring-expenses/{recurringExpenseId}',
            processor: ReviseRecurringExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::REVISE]],
            validationContext: ['groups' => [self::REVISE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/recurring-expenses/{recurringExpenseId}',
            processor: DeleteRecurringExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class RecurringExpenseResource
{
    public const string READ = 'recurring_expense:read';
    public const string CREATE = 'recurring_expense:create';
    public const string REVISE = 'recurring_expense:revise';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The expense each draft copies. */
    #[Assert\NotBlank(groups: [self::CREATE], payload: ['reason' => 'value_required'])]
    #[Assert\Uuid(groups: [self::CREATE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::READ, self::CREATE])]
    public string $modelExpenseId = '';

    /** The model's description, empty once it is gone. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $description = '';

    /** The model's vendor, or the payee it names in words. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $payee = null;

    /** What the model comes to, in its currency's decimals; what each draft starts from. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $amountGross = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $currency = null;

    /** One of day, week, month, year. */
    #[Assert\NotBlank(groups: [self::CREATE, self::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Choice(callback: [self::class, 'units'], groups: [self::CREATE, self::REVISE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public string $unit = '';

    /** Every so many units, 1 to 99. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public int $every = 1;

    /** A month's or a year's day: date, last_day, weekday or last_weekday; null for a day or a week. */
    #[Assert\Choice(callback: [self::class, 'anchors'], groups: [self::CREATE, self::REVISE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?string $anchor = null;

    /** The date of the month a date anchor keeps, 1 to 31. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?int $monthDay = null;

    /** Which of the month's weekdays a weekday anchor reads, 1 to 4. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?int $ordinal = null;

    /** The day of the week, 1 for Monday to 7 for Sunday. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?int $weekday = null;

    /** The first occurrence's earliest day, the company's own. */
    #[Assert\NotBlank(groups: [self::CREATE], payload: ['reason' => 'value_required'])]
    #[Assert\Date(groups: [self::CREATE], payload: ['reason' => 'not_a_date'])]
    #[Groups([self::READ, self::CREATE])]
    public string $startsOn = '';

    /** The last day an occurrence may fall on; null when it goes on. */
    #[Assert\Date(groups: [self::CREATE, self::REVISE], payload: ['reason' => 'not_a_date'])]
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public ?string $endsOn = null;

    /** Whether each bill comes to a different amount, so each draft is flagged for its amount to be checked. */
    #[Groups([self::READ, self::CREATE, self::REVISE])]
    public bool $variableAmount = false;

    #[Groups([self::READ, self::REVISE])]
    public bool $paused = false;

    /** The day of the next draft; null once past the last day. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $nextOn = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $drafted = 0;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $lastExpenseId = null;

    /** @return list<string> */
    public static function units(): array
    {
        return array_map(static fn (ScheduleUnit $unit): string => $unit->value, ScheduleUnit::cases());
    }

    /** @return list<string> */
    public static function anchors(): array
    {
        return array_map(static fn (ScheduleAnchor $anchor): string => $anchor->value, ScheduleAnchor::cases());
    }

    public static function of(RecurringExpense $recurring, ?RecurringExpenseModel $model): self
    {
        $recurrence = $recurring->getRecurrence();
        $schedule = $recurrence->schedule;
        $resource = new self();
        $resource->id = $recurring->getId()->toRfc4122();
        $resource->modelExpenseId = $recurring->getModelExpenseId()->toRfc4122();
        $resource->description = $model->description ?? '';
        $resource->payee = $model?->payee;
        $resource->amountGross = $model?->amountGross;
        $resource->currency = $model?->currency;
        $resource->unit = $schedule->unit->value;
        $resource->every = $schedule->every;
        $resource->anchor = $schedule->anchor?->value;
        $resource->monthDay = $schedule->monthDay;
        $resource->ordinal = $schedule->ordinal;
        $resource->weekday = $schedule->weekday;
        $resource->startsOn = $recurrence->startsOn->format('Y-m-d');
        $resource->endsOn = $recurrence->endsOn?->format('Y-m-d');
        $resource->variableAmount = $recurring->hasVariableAmount();
        $resource->paused = $recurrence->paused;
        $resource->nextOn = $recurrence->nextOn?->format('Y-m-d');
        $resource->drafted = $recurrence->drafted;
        $resource->lastExpenseId = $recurring->getLastExpenseId()?->toRfc4122();

        return $resource;
    }
}

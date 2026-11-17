<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How long a company's deletes, merges and imports can be taken back: its own number of hours, from one up to what the
 * operator keeps (app.records.undoable_for), which is the default. The operator's value bounds it because « Récemment
 * supprimés » keeps copies (a vendor's bank details among them) no longer than the installation allows; a value saved
 * above an operator value lowered since reads as the operator's. Each delete, merge and import keeps the window in force
 * when it was made, so a change moves no undo already offered; RecordsUndoWindow reads it. Declared last of the
 * parties' settings, after how screens name a merged vendor (-200), on a row of its own.
 */
#[AsTaggedItem(priority: -210)]
final readonly class RecordsUndoSettings implements DeclaresSettings
{
    public const string UNDOABLE_FOR = 'records.undoable_for';
    /** Not a module: every delete is undoable. */
    private const string MODULE = 'core';
    private const int HOUR = 3_600;

    public function __construct(
        #[Autowire(param: 'app.records.undoable_for')]
        private string $operatorWindow,
    ) {
    }

    public function settings(): iterable
    {
        $hours = self::operatorHours($this->operatorWindow);
        yield new SettingDefinition(self::UNDOABLE_FOR, SettingType::Int, $hours, SettingChain::Parties, [SettingLevel::Company], 'settings.records.undoable_for', self::MODULE, min: 1, max: $hours);
    }

    /**
     * A company's hours within the operator's window: an hour at least, and the operator's whole window once the company
     * asks as much or more, so an operator's value off the hour (PT90M) is kept as it is rather than cut to its hours.
     */
    public static function secondsOf(int $hours, string $operatorWindow): int
    {
        $operator = self::secondsIn($operatorWindow);

        return $hours >= self::operatorHours($operatorWindow) ? $operator : max(1, $hours) * self::HOUR;
    }

    /** The most whole hours a company may choose: the operator's window in hours, an hour at least. */
    public static function operatorHours(string $operatorWindow): int
    {
        return max(1, intdiv(self::secondsIn($operatorWindow), self::HOUR));
    }

    private static function secondsIn(string $window): int
    {
        $epoch = new \DateTimeImmutable('@0');

        return $epoch->add(new \DateInterval($window))->getTimestamp() - $epoch->getTimestamp();
    }
}

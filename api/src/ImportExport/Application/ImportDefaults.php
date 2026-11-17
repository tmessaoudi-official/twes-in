<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\ImportExport\Application\Notation\DayOrder;
use App\ImportExport\Application\Notation\DecimalMark;
use App\ImportExport\Application\Notation\ImportNotation;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Model\Company;

/**
 * What a company set for its imports. Where it starts each switch of an import whose subject names a setting for it
 * (ImportSwitch::$setting): a switch ticked or not on the screen, a choice's answer, which a request naming none takes;
 * one reader for the guide and the run, so the screen offers what a run left alone does. And how its CSV files write
 * numbers and days.
 */
final readonly class ImportDefaults
{
    public function __construct(private ReadSetting $settings)
    {
    }

    /** @return array<string, bool|string> by switch key, only the switches naming a setting */
    public function of(Company $company, ImportSubject $subject): array
    {
        $defaults = [];
        foreach ($subject->switches as $switch) {
            if (null === $switch->setting) {
                continue;
            }
            $value = $this->settings->value(new SettingContext($company), $switch->setting);
            $defaults[$switch->key] = match (true) {
                [] === $switch->choices && \is_bool($value) => $value,
                [] !== $switch->choices && \is_string($value) => $value,
                default => throw new \LogicException(\sprintf('%s is declared as a %s.', $switch->setting, [] === $switch->choices ? 'switch' : 'choice of answers')),
            };
        }

        return $defaults;
    }

    /** How the company's CSV files write numbers and days; a value the settings no longer offer reads as the default. */
    public function notation(Company $company): ImportNotation
    {
        $context = new SettingContext($company);
        $mark = $this->settings->value($context, ImportSettings::DECIMAL_MARK);
        $order = $this->settings->value($context, ImportSettings::DAY_ORDER);

        return new ImportNotation(
            (\is_string($mark) ? DecimalMark::tryFrom($mark) : null) ?? DecimalMark::Automatic,
            (\is_string($order) ? DayOrder::tryFrom($order) : null) ?? DayOrder::DayFirst,
        );
    }
}

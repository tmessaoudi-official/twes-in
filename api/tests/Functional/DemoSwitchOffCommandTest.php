<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Application\DemoSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A shop's installation switches the demo off as it installs (scripts/onprem/twes install): a visitor there would own a
 * sandbox and pass every identity check. Only where no one has chosen yet: an operator who switched it on keeps it.
 */
final class DemoSwitchOffCommandTest extends ApiTestCase
{
    public function testWithNoChoiceStoredTheDemoIsSwitchedOffAndASecondRunWritesNothing(): void
    {
        self::assertTrue($this->demoEnabled(), 'the shipped default is on');

        $tester = $this->switchOff();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('demo switched off', $tester->getDisplay());
        self::assertFalse($this->demoEnabled());

        $again = $this->switchOff();
        self::assertSame(0, $again->getStatusCode());
        self::assertStringContainsString('left as chosen', $again->getDisplay());
        self::assertFalse($this->demoEnabled());
    }

    public function testAnOperatorsChoiceIsKept(): void
    {
        $this->changeSettings()->change(new SettingContext(), DemoSettings::ENABLED, SettingLevel::Platform, true, null);

        $tester = $this->switchOff();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('left as chosen', $tester->getDisplay());
        self::assertTrue($this->demoEnabled());
    }

    private function switchOff(): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:demo:switch-off-at-install'));
        $tester->execute([]);

        return $tester;
    }

    private function demoEnabled(): bool
    {
        $read = static::getContainer()->get(ReadSetting::class);
        self::assertInstanceOf(ReadSetting::class, $read);

        return true === $read->value(new SettingContext(), DemoSettings::ENABLED);
    }

    private function changeSettings(): ChangeSettings
    {
        $change = static::getContainer()->get(ChangeSettings::class);
        self::assertInstanceOf(ChangeSettings::class, $change);

        return $change;
    }
}

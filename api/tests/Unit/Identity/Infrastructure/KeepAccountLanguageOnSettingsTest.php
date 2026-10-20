<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Application\Account\KeepAccountLanguage;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Infrastructure\Settings\KeepAccountLanguageOnSettings;
use App\Settings\Domain\Event\PersonalSettingChanged;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class KeepAccountLanguageOnSettingsTest extends TestCase
{
    /** Only the screen language is the account's: another preference whose value happens to name a language is not. */
    public function testOnlyTheScreenLanguageMovesTheAccount(): void
    {
        $users = new InMemoryUsers();
        $user = new User(Email::fromString('someone@twes.local'), 'Someone', 'fr');
        $users->save($user);
        $listener = new KeepAccountLanguageOnSettings(new KeepAccountLanguage($users, new FakeTransactions(), ShippedLanguages::catalogue()), new NullLogger());

        $listener->onChanged(new PersonalSettingChanged($user->getId(), 'presentation.date-format', 'en'));
        self::assertSame('fr', $user->getLocale());

        $listener->onChanged(new PersonalSettingChanged($user->getId(), 'presentation.language', 'en'));
        self::assertSame('en', $user->getLocale());
    }

    /** Going back to the company's language moves the account to the language now in force there. */
    public function testGoingBackTakesTheLanguageInForce(): void
    {
        $users = new InMemoryUsers();
        $user = new User(Email::fromString('someone@twes.local'), 'Someone', 'ar');
        $users->save($user);
        $listener = new KeepAccountLanguageOnSettings(new KeepAccountLanguage($users, new FakeTransactions(), ShippedLanguages::catalogue()), new NullLogger());

        $listener->onChanged(new PersonalSettingChanged($user->getId(), 'presentation.language', null, 'fr'));

        self::assertSame('fr', $user->getLocale());
    }
}

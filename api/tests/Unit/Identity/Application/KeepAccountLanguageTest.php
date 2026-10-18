<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Account\KeepAccountLanguage;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class KeepAccountLanguageTest extends TestCase
{
    private InMemoryUsers $users;
    private KeepAccountLanguage $keep;
    private User $user;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->keep = new KeepAccountLanguage($this->users, new FakeTransactions(), ShippedLanguages::catalogue());
        $this->user = new User(Email::fromString('someone@twes.local'), 'Someone', 'fr');
        $this->users->save($this->user);
    }

    /** The language a person picks for the screens is the one their mails and the API's own words are written in. */
    public function testTheLanguageAPersonChoosesBecomesTheirAccountsLanguage(): void
    {
        $this->keep->chosen($this->user->getId(), 'en');

        self::assertSame('en', $this->users->ofId($this->user->getId())?->getLocale());
    }

    /** Going back to the company's choice leaves the account on the last language the person chose. */
    public function testNoChoiceAtAllLeavesTheAccountAsItWas(): void
    {
        $this->keep->chosen($this->user->getId(), 'en');
        $this->keep->chosen($this->user->getId(), null);

        self::assertSame('en', $this->user->getLocale());
    }

    public function testALanguageTheScreensDoNotSpeakOrAnAccountGoneChangesNothing(): void
    {
        $this->keep->chosen($this->user->getId(), 'ar');
        $this->keep->chosen(Uuid::v7(), 'en');

        self::assertSame('fr', $this->user->getLocale());
    }
}

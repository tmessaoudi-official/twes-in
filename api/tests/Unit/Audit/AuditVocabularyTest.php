<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Audit\Application\AuditVocabulary;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use PHPUnit\Framework\TestCase;

final class AuditVocabularyTest extends TestCase
{
    public function testItGathersEveryDeclarationAndAgreesWithItself(): void
    {
        $vocabulary = new AuditVocabulary([
            self::declaring(['invoice.issued' => new Recorded('invoice', AuditScope::Company), 'setting.changed' => new Recorded('setting', AuditScope::Either), 'password.breach_check_skipped' => new Recorded('signup', AuditScope::Company)]),
            self::declaring(['auth.login_failed' => new Recorded('user', AuditScope::Outside), 'invoice.issued' => new Recorded('invoice', AuditScope::Company), 'password.breach_check_skipped' => new Recorded('invitation', AuditScope::Company)]),
        ]);

        self::assertSame(['auth.login_failed', 'invoice.issued', 'password.breach_check_skipped', 'setting.changed'], array_keys($vocabulary->all()));
        self::assertSame(AuditScope::Either, $vocabulary->scopeOf('setting.changed'));
        self::assertNull($vocabulary->scopeOf('nobody.declared'));
        self::assertSame(['invoice'], $vocabulary->kindsOf('invoice.issued'));
        self::assertSame(['invitation', 'signup'], $vocabulary->kindsOf('password.breach_check_skipped'), 'one action, two kinds of record');
        self::assertSame([], $vocabulary->kindsOf('nobody.declared'));
        self::assertSame(['invitation', 'invoice', 'setting', 'signup'], $vocabulary->kindsShownInAJournal(), 'a user is an account\'s alone here');
    }

    public function testTwoDeclarationsDisagreeingOnWhereAnActionIsRecordedAreRefused(): void
    {
        $vocabulary = new AuditVocabulary([
            self::declaring(['setting.changed' => new Recorded('setting', AuditScope::Company)]),
            self::declaring(['setting.changed' => new Recorded('setting', AuditScope::Outside)]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"setting.changed" is declared outside');
        $vocabulary->all();
    }

    public function testAScopeSaysWhereItIsRecordedAndWhetherAJournalShowsIt(): void
    {
        self::assertSame([true, false, true], [AuditScope::Company->allows(true), AuditScope::Company->allows(false), AuditScope::Outside->allows(false)]);
        self::assertSame([false, true, true], [AuditScope::Outside->allows(true), AuditScope::Either->allows(true), AuditScope::Either->allows(false)]);
        self::assertSame([true, false, true], [AuditScope::Company->shownInAJournal(), AuditScope::Outside->shownInAJournal(), AuditScope::Either->shownInAJournal()]);
    }

    /** @param array<string, Recorded> $actions */
    private static function declaring(array $actions): DeclaresAuditActions
    {
        return new readonly class($actions) implements DeclaresAuditActions {
            /** @param array<string, Recorded> $actions */
            public function __construct(private array $actions)
            {
            }

            public function auditActions(): array
            {
                return $this->actions;
            }
        };
    }
}

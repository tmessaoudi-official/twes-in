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
use PHPUnit\Framework\TestCase;

final class AuditVocabularyTest extends TestCase
{
    public function testItGathersEveryDeclarationAndAgreesWithItself(): void
    {
        $vocabulary = new AuditVocabulary([
            self::declaring(['invoice.issued' => AuditScope::Company, 'setting.changed' => AuditScope::Either]),
            self::declaring(['auth.login_failed' => AuditScope::Outside, 'invoice.issued' => AuditScope::Company]),
        ]);

        self::assertSame(['auth.login_failed', 'invoice.issued', 'setting.changed'], array_keys($vocabulary->all()));
        self::assertSame(AuditScope::Either, $vocabulary->scopeOf('setting.changed'));
        self::assertNull($vocabulary->scopeOf('nobody.declared'));
    }

    public function testTwoDeclarationsDisagreeingOnWhereAnActionIsRecordedAreRefused(): void
    {
        $vocabulary = new AuditVocabulary([
            self::declaring(['setting.changed' => AuditScope::Company]),
            self::declaring(['setting.changed' => AuditScope::Outside]),
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

    /** @param array<string, AuditScope> $actions */
    private static function declaring(array $actions): DeclaresAuditActions
    {
        return new readonly class($actions) implements DeclaresAuditActions {
            /** @param array<string, AuditScope> $actions */
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

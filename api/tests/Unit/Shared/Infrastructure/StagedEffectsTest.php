<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\Effect;
use App\Shared\Infrastructure\Effects\StagedEffects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a request did beyond its subject is said in its answer only once the unit of work that did it has committed:
 * a receipt rolled back never says the price it would have moved.
 */
#[CoversClass(StagedEffects::class)]
final class StagedEffectsTest extends TestCase
{
    public function testAnEffectIsKeptOnceItsUnitCommits(): void
    {
        $effects = new StagedEffects();

        $effects->begin();
        $effects->said(new Effect('product.price_followed', null));
        self::assertSame([], $this->kinds($effects), 'nothing is said while the unit may still roll back');
        $effects->commit();

        self::assertSame(['product.price_followed'], $this->kinds($effects));
    }

    public function testAnEffectOfARolledBackUnitIsNeverSaid(): void
    {
        $effects = new StagedEffects();

        $effects->begin();
        $effects->said(new Effect('product.price_followed', null));
        $effects->rollBack();

        self::assertSame([], $this->kinds($effects));
    }

    public function testAnInnerUnitRolledBackTakesBackOnlyWhatItSaid(): void
    {
        $effects = new StagedEffects();

        $effects->begin();
        $effects->said(new Effect('outer', null));
        $effects->begin();
        $effects->said(new Effect('inner', null));
        $effects->rollBack();
        $effects->begin();
        $effects->said(new Effect('kept', null));
        $effects->commit();
        self::assertSame([], $this->kinds($effects), 'an inner commit waits for the outer one');
        $effects->commit();

        self::assertSame(['outer', 'kept'], $this->kinds($effects));
    }

    public function testAnEffectSaidOutsideAnyUnitIsKeptAtOnceAndARequestForgetsWhatTheLastOneSaid(): void
    {
        $effects = new StagedEffects();

        $effects->said(new Effect('alone', null));
        self::assertSame(['alone'], $this->kinds($effects));

        $effects->begin();
        $effects->said(new Effect('open', null));
        $effects->reset();
        $effects->commit();

        self::assertSame([], $this->kinds($effects), 'one kernel serves the next person\'s request in worker mode');
    }

    /** @return list<string> */
    private function kinds(StagedEffects $effects): array
    {
        return array_map(static fn (Effect $effect): string => $effect->kind, $effects->saidHere());
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ModuleRegistry\Domain;

use App\ModuleRegistry\Domain\ModuleState;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;

final class ModuleStateTest extends TestCase
{
    public function testAModuleSwitchedOnRemembersSinceWhenAndSwitchedOffForgetsIt(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $morning = new \DateTimeImmutable('2026-09-14 09:00:00');
        $noon = new \DateTimeImmutable('2026-09-14 12:00:00');

        $state = ModuleState::of($company, 'customers', false, $morning);
        self::assertSame([$company, 'customers', false, null], [$state->getCompany(), $state->getKey(), $state->isEnabled(), $state->getEnabledAt()]);

        self::assertTrue($state->switchTo(true, $noon));
        self::assertSame([true, $noon], [$state->isEnabled(), $state->getEnabledAt()]);

        self::assertFalse($state->switchTo(true, new \DateTimeImmutable('2026-09-14 18:00:00')), 'already on');
        self::assertSame($noon, $state->getEnabledAt());

        self::assertTrue($state->switchTo(false, $noon));
        self::assertSame([false, null], [$state->isEnabled(), $state->getEnabledAt()]);
        self::assertFalse($state->switchTo(false, $noon));

        self::assertSame($morning, ModuleState::of($company, 'products', true, $morning)->getEnabledAt());
    }
}

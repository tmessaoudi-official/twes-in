<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Reports\Application;

use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportCatalogue;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Infrastructure\Module\ReportsModule;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleStates;
use App\ModuleRegistry\Domain\Model\ModuleState;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\InMemoryModuleStates;
use PHPUnit\Framework\TestCase;

final class ReportCatalogueTest extends TestCase
{
    private Company $company;
    private InMemoryModuleStates $states;
    private ModuleStates $modules;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->states = new InMemoryModuleStates();
        $this->modules = new ModuleStates(new ModuleCatalog([new CustomersModule(), new ProductsModule(), new InvoicesModule(), new ReportsModule()]), $this->states);
    }

    public function testReportsAreOfferedInTheRuledOrderThenByKey(): void
    {
        $catalogue = new ReportCatalogue([self::report('zeta'), self::report('aged-balance'), self::report('alpha'), self::report('takings')], $this->modules);

        self::assertSame(['takings', 'aged-balance', 'alpha', 'zeta'], array_map(static fn (DeclaresReport $report): string => $report->key(), $catalogue->offered($this->context())));
    }

    public function testTheReportsModuleSwitchedOffOffersNothing(): void
    {
        $catalogue = new ReportCatalogue([self::report('takings', module: null)], $this->modules);
        $this->states->save(ModuleState::of($this->company, ReportsModule::KEY, false, new \DateTimeImmutable()));

        self::assertSame([], $catalogue->offered($this->context()));
        self::assertNull($catalogue->declarationOf('takings', $this->context()));
    }

    public function testAReportOfAModuleSwitchedOffIsNotOffered(): void
    {
        $catalogue = new ReportCatalogue([self::report('takings', module: InvoicesModule::KEY), self::report('core', module: null)], $this->modules);
        $this->states->save(ModuleState::of($this->company, InvoicesModule::KEY, false, new \DateTimeImmutable()));

        self::assertSame(['core'], array_map(static fn (DeclaresReport $report): string => $report->key(), $catalogue->offered($this->context())));
    }

    public function testAReportWhosePermissionTheRoleLacksIsTheSameAbsenceAsNone(): void
    {
        $catalogue = new ReportCatalogue([self::report('takings', permission: 'invoice.read')], $this->modules);
        $without = $this->context(static fn (string $permission): bool => 'invoice.read' !== $permission);

        self::assertSame([], $catalogue->offered($without));
        self::assertNull($catalogue->declarationOf('takings', $without));
        self::assertNull($catalogue->declarationOf('nobody-declared-this', $this->context()));
        self::assertSame('takings', $catalogue->declarationOf('takings', $this->context())?->key());
    }

    public function testAReportDeclaredTwiceIsAFault(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The report takings is declared twice.');
        new ReportCatalogue([self::report('takings'), self::report('takings')], $this->modules);
    }

    public function testAReportKeyIsLowercaseWordsJoinedByDashes(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A report is named in lowercase words joined by dashes: Takings_Day.');
        new ReportCatalogue([self::report('Takings_Day')], $this->modules);
    }

    /** @param ?\Closure(string): bool $may */
    private function context(?\Closure $may = null): ReportContext
    {
        return new ReportContext($this->company, new \DateTimeImmutable('2026-10-18'), $may ?? static fn (string $permission): bool => true);
    }

    private static function report(string $key, ?string $module = InvoicesModule::KEY, string $permission = 'invoice.read'): DeclaresReport
    {
        return new class($key, $module, $permission) implements DeclaresReport {
            public function __construct(private readonly string $key, private readonly ?string $module, private readonly string $permission)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function module(): ?string
            {
                return $this->module;
            }

            public function permission(): string
            {
                return $this->permission;
            }

            public function byEstablishment(): bool
            {
                return true;
            }

            public function periodic(): bool
            {
                return true;
            }

            public function columns(ReportContext $context): array
            {
                return [];
            }

            public function rows(ReportContext $context, ReportPeriod $period): array
            {
                return [];
            }

            public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
            {
                return [];
            }
        };
    }
}

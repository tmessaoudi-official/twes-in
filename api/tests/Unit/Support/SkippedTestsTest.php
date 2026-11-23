<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\SkippedTests;
use PHPUnit\Framework\TestCase;

// A skipped test leaves PHPUnit's exit at 0, so a suite that could not run a case read as green: a cloud session's
// three Factur-X and CII schema cases were skipped for want of their schemas and nothing said so. The suite's run reads
// the skips from its JUnit log and holds them to a named list (docs/SPEC.md § 7, a cloud session never silently worse).
final class SkippedTestsTest extends TestCase
{
    private const string SKIPPED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="all" tests="3" skipped="2">
            <testsuite name="App\Tests\Unit\SchemaTest" tests="2" skipped="1">
              <testcase name="testTheFileIsValid" class="App\Tests\Unit\SchemaTest"><skipped/></testcase>
              <testcase name="testTheSchemaRefuses" class="App\Tests\Unit\SchemaTest"/>
            </testsuite>
            <testcase name="testEachRow with data set &quot;dash&quot;" class="App\Tests\Unit\RowsTest"><skipped/></testcase>
          </testsuite>
        </testsuites>
        XML;

    public function testASkipNoLineNamesIsUnexpected(): void
    {
        self::assertSame(
            ['App\Tests\Unit\SchemaTest::testTheFileIsValid', 'App\Tests\Unit\RowsTest::testEachRow with data set "dash"'],
            SkippedTests::unexpected(self::SKIPPED, ''),
        );
    }

    public function testASkipItsLineNamesIsExpectedEveryDataSetOfIt(): void
    {
        $allowed = "# a line says why the case may skip\n\nApp\\Tests\\Unit\\RowsTest::testEachRow  only where the source answers\n";

        self::assertSame(['App\Tests\Unit\SchemaTest::testTheFileIsValid'], SkippedTests::unexpected(self::SKIPPED, $allowed));
    }

    public function testASuiteWithoutASkipHasNoneUnexpected(): void
    {
        $none = '<?xml version="1.0"?><testsuites><testsuite name="all"><testcase name="testA" class="X"/></testsuite></testsuites>';

        self::assertSame([], SkippedTests::unexpected($none, ''));
    }

    public function testALogThatCannotBeReadIsRefusedNeverReadAsNoSkip(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        SkippedTests::unexpected('<testsuites><testcase', '');
    }

    public function testOnCiASkipIsAWarningOnItsRunAndTheExitStays(): void
    {
        [$status, $said] = SkippedTests::verdict(['X::testA'], 0, true);

        self::assertSame(0, $status, 'a source down on CI is a warning on the run, as fetch-xsd.sh says it (2026-11-04)');
        self::assertSame(["::warning title=Test skipped::X::testA was skipped: name it in api/tests/skips-allowed.txt with why, or make it run\n"], $said);
    }

    public function testElsewhereASkipRedsAPassingRun(): void
    {
        [$status, $said] = SkippedTests::verdict(['X::testA', 'Y::testB'], 0, false);

        self::assertSame(1, $status);
        self::assertStringContainsString('2 test(s) skipped that api/tests/skips-allowed.txt does not name', $said[0]);
        self::assertStringContainsString('X::testA', $said[1]);
    }

    public function testARunAlreadyRedKeepsItsOwnExit(): void
    {
        self::assertSame(2, SkippedTests::verdict(['X::testA'], 2, false)[0]);
        self::assertSame([0, []], SkippedTests::verdict([], 0, false));
    }
}

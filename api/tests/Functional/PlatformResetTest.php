<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\RecordingPlatformContents;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * `app:platform:reset`, the in-place reset of an installation: what it refuses before anything is emptied. Anything
 * fiscal (app:fiscal:inventory's exit 3) is never emptied in place: such an installation is reset whole, its volumes
 * removed, by twes reset, deploy.sh reset or make reset. With nothing in it, a confirmation; with a company or an
 * account, --force and the installation's name typed. The emptying itself is stood in for in every test (services.yaml,
 * when@test), as it runs on the owner's connection, outside this case's transaction, and would empty the suite's own
 * database: OwnerPlatformContentsTest empties a database it makes for itself.
 */
final class PlatformResetTest extends ApiTestCase
{
    private RecordingPlatformContents $contents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contents = static::getContainer()->get(RecordingPlatformContents::class);
        $this->contents->emptied = false;
    }

    public function testAnInstallationHoldingFiscalRecordsIsNeverEmptiedInPlace(): void
    {
        $this->createUser('operator@twes.local', 'operator-secret', operator: true);
        $load = $this->console(['command' => 'doctrine:fixtures:load', '--append' => true]);
        self::assertSame(0, $load->getStatusCode(), $load->getDisplay());

        $tester = $this->reset(['--force' => true, '--installation' => 'twes-in'], ['twes-in']);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('fiscal records', $tester->getDisplay());
        self::assertStringContainsString('reset whole', $tester->getDisplay());
        self::assertFalse($this->contents->emptied);
    }

    public function testACompanyNeedsForce(): void
    {
        $this->createCompany('Essai');

        $tester = $this->reset(['--installation' => 'twes-in'], ['twes-in']);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertFalse($this->contents->emptied);
    }

    public function testAnAccountNeedsForceToo(): void
    {
        $this->createUser('someone@example.test', 'a-password');

        $tester = $this->reset(['--installation' => 'twes-in'], ['twes-in']);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFalse($this->contents->emptied);
    }

    public function testACompanyNeedsTheInstallationNamed(): void
    {
        $this->createCompany('Essai');

        $tester = $this->reset(['--force' => true], ['twes-in']);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('--installation', $tester->getDisplay());
        self::assertFalse($this->contents->emptied);
    }

    public function testAWrongNameTypedEmptiesNothing(): void
    {
        $this->createCompany('Essai');

        $tester = $this->reset(['--force' => true, '--installation' => 'twes-in'], ['twes']);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('nothing emptied', $tester->getDisplay());
        self::assertFalse($this->contents->emptied);
    }

    public function testNoInputToTypeTheNameIntoEmptiesNothing(): void
    {
        $this->createCompany('Essai');

        $tester = $this->reset(['--force' => true, '--installation' => 'twes-in'], null);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFalse($this->contents->emptied);
    }

    public function testTheNameTypedEmptiesItAndTheRecordSaysWhatWasThere(): void
    {
        $this->createCompany('Essai');
        $this->createCompany('Autre');

        $tester = $this->reset(['--force' => true, '--installation' => 'twes-in', '--by' => 'shop-pc', '--json' => true], ['twes-in']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue($this->contents->emptied);
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $line): bool => str_starts_with($line, '{')));
        self::assertCount(1, $lines, $tester->getDisplay());
        $record = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame('installation', $record['scope'] ?? null);
        self::assertSame('in-place', $record['mode'] ?? null);
        self::assertSame('twes-in', $record['installation'] ?? null);
        self::assertSame('shop-pc', $record['by'] ?? null);
        self::assertSame(2, $record['companies'] ?? null);
        self::assertSame(0, $record['fiscalRecords'] ?? null);
        self::assertSame(['tables' => 7, 'files' => 3], ['tables' => $record['tables'] ?? null, 'files' => $record['files'] ?? null]);
        self::assertSame(self::heldNow()->format(\DATE_ATOM), $record['at'] ?? null);
    }

    public function testAnEmptyInstallationAsksAConfirmation(): void
    {
        $refused = $this->reset([], ['no']);
        self::assertSame(1, $refused->getStatusCode(), $refused->getDisplay());
        self::assertFalse($this->contents->emptied);

        $confirmed = $this->reset([], ['yes']);
        self::assertSame(0, $confirmed->getStatusCode(), $confirmed->getDisplay());
        self::assertTrue($this->contents->emptied);
    }

    public function testAnEmptyInstallationWithForceAsksNothing(): void
    {
        $tester = $this->reset(['--force' => true], null);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue($this->contents->emptied);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>|null    $typed   what the person types, or null for a run with no input to ask
     */
    private function reset(array $options, ?array $typed): ApplicationTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        if (null !== $typed) {
            $tester->setInputs($typed);
        }
        $tester->run(['command' => 'app:platform:reset', ...$options], ['interactive' => null !== $typed]);

        return $tester;
    }

    /** @param array<string, mixed> $input */
    private function console(array $input): ApplicationTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, ['interactive' => false]);

        return $tester;
    }
}

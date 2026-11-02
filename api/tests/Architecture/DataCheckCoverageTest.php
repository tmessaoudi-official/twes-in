<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A context keeping money or stock, a decimal column on one of its entities, declares what its figures must still say
 * in its own `Infrastructure/DataHealth/` (`DeclaresDataChecks`), so a direct write, a bug or a failed restore breaking
 * them is found by the night's check; or it is listed here with why nothing it keeps adds up to anything else.
 */
final class DataCheckCoverageTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    /** Each context keeping decimals that sum to nothing, and why. */
    private const array UNCHECKED = [
        'Fiscal' => 'a tax component\'s rate or amount is a value typed in, which documents copy when they are issued',
        'Licensing' => 'a plan\'s price and a declared payment are typed by the operator; a subscription is worked out from dates on every read',
        'Module/PriceLists' => 'a price list holds prices typed in, which documents copy when they are drafted',
        'Module/Products' => 'a product\'s price and cost are values typed in or taken from a receipt, which documents copy',
        'Venue' => 'a plan\'s areas and spots are measured in metres, neither money nor stock',
    ];

    public function testEveryContextKeepingMoneyOrStockDeclaresItsDataChecks(): void
    {
        $keeping = $this->contextsKeepingDecimals();
        self::assertGreaterThanOrEqual(12, \count($keeping), 'the entities\' decimal columns were found: a scan finding few has stopped reading them');

        $missing = [];
        foreach ($keeping as $context) {
            if (!isset(self::UNCHECKED[$context]) && !$this->declaresChecks($context)) {
                $missing[] = $context;
            }
        }
        self::assertSame([], $missing, 'Each context keeping money or stock declares a DeclaresDataChecks in its Infrastructure/DataHealth/, or is listed in UNCHECKED with its reason.');

        $stale = [];
        foreach (array_keys(self::UNCHECKED) as $context) {
            if (!\in_array($context, $keeping, true) || $this->declaresChecks($context)) {
                $stale[] = $context;
            }
        }
        self::assertSame([], $stale, 'A context listed in UNCHECKED keeps no decimal any more, or declares its checks now: strike it off.');
    }

    /** @return list<string> each context, `Module/<Name>` for a module, with an entity mapping a decimal column */
    private function contextsKeepingDecimals(): array
    {
        $contexts = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen(self::SRC) + 1);
            if (1 !== preg_match('#^((?:Module/)?[A-Za-z]+)/Domain/Model/#', $relative, $match)) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'Types::DECIMAL')) {
                $contexts[$match[1]] = true;
            }
        }
        $found = array_keys($contexts);
        sort($found);

        return $found;
    }

    private function declaresChecks(string $context): bool
    {
        foreach (glob(self::SRC.'/'.$context.'/Infrastructure/DataHealth/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'implements DeclaresDataChecks')) {
                return true;
            }
        }

        return false;
    }
}

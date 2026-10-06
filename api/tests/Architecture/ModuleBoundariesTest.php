<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A module reaches another only through a port it owns (docs/SPEC.md § 7, audit 2026-10-06 C-4): a file of one module
 * may name another module's Application or Infrastructure class only when it is a contract, never a class that runs.
 * A contract is an interface or an enum, an exception, a value a port carries (a readonly class with nothing public but
 * its constructor), or a class whose constants alone are read (`Permission::READ`, `Module::KEY`, `Resource::class`).
 * Domain classes are left to the layer rules. The check reads `use` statements and inline fully-qualified names alike.
 */
final class ModuleBoundariesTest extends TestCase
{
    private const string MODULES = __DIR__.'/../../src/Module';

    /**
     * Imports that predate the rule and wait for the developer's word (§ 7, audit 2026-10-06 C-4, ASSUMED 2026-10-06):
     * the pickers read another module's records through its own read use case, which the audit counted apart from the
     * concrete calls the ruling named. Each is either converted to a port or ruled a sanctioned read; none is added.
     */
    private const array AWAITING_A_RULING = [
        'DeliveryNotes/Infrastructure/ApiPlatform/DeliveryNoteCustomerPickProvider.php' => 'App\Module\Customers\Application\PickCustomers',
        'Invoices/Infrastructure/ApiPlatform/InvoiceCustomerPickProvider.php' => 'App\Module\Customers\Application\PickCustomers',
        'DeliveryNotes/Infrastructure/ApiPlatform/DeliveryNoteProductPickProvider.php' => 'App\Module\Products\Application\PickProducts',
        'Inventory/Infrastructure/ApiPlatform/StockProductPickProvider.php' => 'App\Module\Products\Application\PickProducts',
        'Invoices/Infrastructure/ApiPlatform/InvoiceProductPickProvider.php' => 'App\Module\Products\Application\PickProducts',
        'Expenses/Infrastructure/ApiPlatform/ExpenseVendorPickProvider.php' => 'App\Module\Vendors\Application\PickVendors',
        'Inventory/Infrastructure/ApiPlatform/StockVendorPickProvider.php' => 'App\Module\Vendors\Application\PickVendors',
    ];

    public function testAModuleNamesNoClassOfAnotherThatRuns(): void
    {
        $violations = [];
        $seen = 0;
        foreach ($this->files() as $relative => $path) {
            $module = strstr($relative, '/', true);
            $content = (string) file_get_contents($path);
            foreach ($this->foreignNames($content, (string) $module) as $name => $short) {
                ++$seen;
                if ($this->isContract($name) || $this->onlyConstantsRead($content, $name, $short)) {
                    continue;
                }
                if ((self::AWAITING_A_RULING[$relative] ?? null) === $name) {
                    continue;
                }
                $violations[] = "$relative runs $name";
            }
        }
        self::assertGreaterThan(50, $seen, 'the modules name each other\'s Application and Infrastructure classes: a scan finding few has stopped reading them');
        self::assertSame([], $violations, "A module reaches another through a port it owns, answered in the other's Infrastructure:\n".implode("\n", $violations));
    }

    public function testEveryImportAwaitingARulingIsStillThere(): void
    {
        foreach (self::AWAITING_A_RULING as $relative => $name) {
            $content = (string) file_get_contents(self::MODULES.'/'.$relative);
            self::assertStringContainsString("use $name;", $content, "$relative no longer imports $name: take it off the list, which only shrinks");
        }
    }

    /** @return array<string, string> every PHP file of every module, by its path under src/Module */
    private function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULES, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && 'php' === $entry->getExtension()) {
                $files[substr($entry->getPathname(), \strlen(self::MODULES) + 1)] = $entry->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Another module's Application and Infrastructure classes a file names, with the short name its code uses.
     *
     * @return array<string, string>
     */
    private function foreignNames(string $content, string $module): array
    {
        $names = [];
        preg_match_all('/^use (App\\\\Module\\\\(\w+)\\\\(?:Application|Infrastructure)\\\\[A-Za-z0-9_\\\\]+)(?: as (\w+))?;/m', $content, $uses, \PREG_SET_ORDER);
        foreach ($uses as $use) {
            if ($use[2] !== $module) {
                $names[$use[1]] = '' !== ($use[3] ?? '') ? $use[3] : substr((string) strrchr($use[1], '\\'), 1);
            }
        }
        preg_match_all('/\\\\(App\\\\Module\\\\(\w+)\\\\(?:Application|Infrastructure)\\\\[A-Za-z0-9_\\\\]+)/', $content, $inline, \PREG_SET_ORDER);
        foreach ($inline as $reference) {
            if ($reference[2] !== $module) {
                $names[$reference[1]] ??= '\\'.$reference[1];
            }
        }

        return $names;
    }

    private function isContract(string $name): bool
    {
        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            self::fail("$name is named but does not exist");
        }
        $class = new \ReflectionClass($name);
        if ($class->isInterface() || $class->isEnum() || $class->isSubclassOf(\Throwable::class)) {
            return true;
        }
        $public = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $class->getMethods(\ReflectionMethod::IS_PUBLIC));

        return $class->isReadOnly() && ['__construct'] === $public;
    }

    /** Whether every mention of the class past its import reads a constant of it, or its name. */
    private function onlyConstantsRead(string $content, string $name, string $short): bool
    {
        $code = (string) preg_replace('/^use [^;]+;$/m', '', $content);
        preg_match_all('/(?<![\w\\\\])'.preg_quote($short, '/').'(?![\w\\\\])(::[A-Z][A-Z0-9_]*\b|::class\b)?/', $code, $mentions, \PREG_SET_ORDER);
        if ([] === $mentions) {
            return false;
        }
        foreach ($mentions as $mention) {
            if ('' === ($mention[1] ?? '')) {
                return false;
            }
        }

        return true;
    }
}

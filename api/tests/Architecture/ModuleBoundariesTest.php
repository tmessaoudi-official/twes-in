<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A module reaches another only through a port it owns (docs/SPEC.md § 7): a file of one module may name another
 * module's Application, Domain or Infrastructure class only when it is a contract, never a class that runs. A contract
 * is an interface or an enum, an exception, a value a port carries (a readonly class with nothing public but its
 * constructor), or a class whose constants alone are read (`Permission::READ`, `Module::KEY`, `Resource::class`).
 * Another module's repository is never one: it is that module's storage. The check reads `use` statements and inline
 * fully-qualified names alike.
 */
final class ModuleBoundariesTest extends TestCase
{
    private const string MODULES = __DIR__.'/../../src/Module';

    /**
     * Crossings that predate the rule and wait for the developer's word, each a module and the class of another it
     * names. The pickers read another module's records through its own read use case, which the audit counted apart from
     * the concrete calls the ruling named; the Domain crossings are the entities and services one module's records hold
     * of another's. Each is either turned into a port or ruled a sanctioned read; none is added. Delivery notes reach the
     * invoices through their own ports alone, as ruled, so no crossing of theirs into the invoices is here.
     */
    private const array AWAITING_A_RULING = [
        'DeliveryNotes -> App\Module\Customers\Application\PickCustomers',
        'DeliveryNotes -> App\Module\Customers\Domain\Model\Customer',
        'DeliveryNotes -> App\Module\Customers\Domain\Repository\CustomerRepository',
        'DeliveryNotes -> App\Module\Customers\Domain\ValueObject\CustomerSnapshot',
        'DeliveryNotes -> App\Module\Products\Application\PickProducts',
        'DeliveryNotes -> App\Module\Products\Domain\Model\Product',
        'DeliveryNotes -> App\Module\Products\Domain\Repository\ProductRepository',
        'DeliveryNotes -> App\Module\Products\Domain\Service\LotCode',
        'Expenses -> App\Module\Vendors\Application\PickVendors',
        'Expenses -> App\Module\Vendors\Domain\Model\Vendor',
        'Expenses -> App\Module\Vendors\Domain\Repository\VendorRepository',
        'Inventory -> App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository',
        'Inventory -> App\Module\Products\Application\PickProducts',
        'Inventory -> App\Module\Products\Domain\Model\Product',
        'Inventory -> App\Module\Products\Domain\Repository\ProductCategoryRepository',
        'Inventory -> App\Module\Products\Domain\Repository\ProductPhotoRepository',
        'Inventory -> App\Module\Products\Domain\Repository\ProductRepository',
        'Inventory -> App\Module\Products\Domain\Service\LotCode',
        'Inventory -> App\Module\Products\Domain\ValueObject\Barcode',
        'Inventory -> App\Module\Vendors\Application\PickVendors',
        'Inventory -> App\Module\Vendors\Domain\Model\Vendor',
        'Inventory -> App\Module\Vendors\Domain\Repository\VendorRepository',
        'Invoices -> App\Module\Customers\Application\PickCustomers',
        'Invoices -> App\Module\Customers\Domain\Model\Customer',
        'Invoices -> App\Module\Customers\Domain\Repository\CustomerRepository',
        'Invoices -> App\Module\Customers\Domain\ValueObject\CustomerSnapshot',
        'Invoices -> App\Module\Products\Application\PickProducts',
        'Invoices -> App\Module\Products\Domain\Model\Product',
        'Invoices -> App\Module\Products\Domain\Repository\ProductRepository',
        'Invoices -> App\Module\Products\Domain\Service\LotCode',
        'Invoices -> App\Module\Quotes\Domain\Model\Quote',
        'Invoices -> App\Module\Quotes\Domain\Model\QuoteLine',
        'Invoices -> App\Module\Quotes\Domain\Model\QuoteLineTax',
        'PriceLists -> App\Module\Customers\Domain\Repository\CustomerGroupRepository',
        'PriceLists -> App\Module\Customers\Domain\Repository\CustomerRepository',
        'PriceLists -> App\Module\Products\Domain\Model\Product',
        'PriceLists -> App\Module\Products\Domain\Repository\ProductRepository',
        'Products -> App\Module\Vendors\Domain\Model\Vendor',
        'Products -> App\Module\Vendors\Domain\Repository\VendorRepository',
        'Quotes -> App\Module\Customers\Domain\Model\Customer',
        'Quotes -> App\Module\Customers\Domain\Repository\CustomerRepository',
        'Quotes -> App\Module\Customers\Domain\ValueObject\CustomerSnapshot',
        'Quotes -> App\Module\Products\Domain\Model\Product',
        'Quotes -> App\Module\Products\Domain\Repository\ProductRepository',
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
                if (\in_array("$module -> $name", self::AWAITING_A_RULING, true)) {
                    continue;
                }
                $violations[] = "$relative runs $name";
            }
        }
        self::assertGreaterThan(50, $seen, 'the modules name each other\'s Application and Infrastructure classes: a scan finding few has stopped reading them');
        self::assertSame([], $violations, "A module reaches another through a port it owns, answered in the other's Infrastructure:\n".implode("\n", $violations));
    }

    public function testEveryCrossingAwaitingARulingIsStillThere(): void
    {
        $named = [];
        foreach ($this->files() as $relative => $path) {
            $module = (string) strstr($relative, '/', true);
            foreach (array_keys($this->foreignNames((string) file_get_contents($path), $module)) as $name) {
                $named["$module -> $name"] = true;
            }
        }
        foreach (self::AWAITING_A_RULING as $crossing) {
            self::assertArrayHasKey($crossing, $named, "No file names $crossing any more: take it off the list, which only shrinks");
        }
    }

    /**
     * The crossings above are kept for reading. A pair on the list lets every file of the module name the class, so
     * without this a write or a row lock through it would pass unseen: changing or holding another module's rows is that
     * module's to do, through a port the caller owns. What a regex cannot type, a mutator called on another module's
     * entity, stays a matter for review.
     */
    public function testACrossingKeptForReadingNeverWritesOrLocks(): void
    {
        $violations = [];
        $repositories = 0;
        foreach ($this->files() as $relative => $path) {
            $module = (string) strstr($relative, '/', true);
            $content = (string) file_get_contents($path);
            foreach ($this->foreignNames($content, $module) as $name => $short) {
                if (str_contains($name, '\\Domain\\Repository\\')) {
                    preg_match_all('/'.preg_quote($short, '/').'\s+\$(\w+)/', $content, $properties);
                    foreach (array_unique($properties[1]) as $property) {
                        ++$repositories;
                        if (1 === preg_match('/\$(?:this->)?'.$property.'\??->(save|remove|lock\w*)\(/', $content, $call)) {
                            $violations[] = "$relative calls $short::$call[1]()";
                        }
                    }
                }
                if (str_contains($name, '\\Domain\\Model\\') && 1 === preg_match('/from\(\s*'.preg_quote($short, '/').'::class[^;]*->setLockMode\(/', $content)) {
                    $violations[] = "$relative locks $short in a query of its own";
                }
            }
        }
        self::assertGreaterThan(30, $repositories, 'the modules hold each other\'s repositories in some forty places: a scan finding few has stopped reading them');
        self::assertSame([], $violations, "A crossing kept for reading reads; a write or a lock goes through a port the caller owns:\n".implode("\n", $violations));
    }

    public function testAnotherModulesRepositoryIsNeverAContractThoughItIsAnInterface(): void
    {
        $contract = new \ReflectionMethod($this, 'isContract');

        self::assertTrue(interface_exists('App\\Module\\Invoices\\Domain\\Repository\\InvoiceRepository'));
        self::assertFalse($contract->invoke($this, 'App\\Module\\Invoices\\Domain\\Repository\\InvoiceRepository'));
        self::assertTrue($contract->invoke($this, 'App\\Module\\DeliveryNotes\\Application\\InvoicedLines'), 'a port is one');
        self::assertFalse($contract->invoke($this, 'App\\Module\\Invoices\\Domain\\Model\\Invoice'), 'an entity runs');
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
        preg_match_all('/^use (App\\\\Module\\\\(\w+)\\\\(?:Application|Domain|Infrastructure)\\\\[A-Za-z0-9_\\\\]+)(?: as (\w+))?;/m', $content, $uses, \PREG_SET_ORDER);
        foreach ($uses as $use) {
            if ($use[2] !== $module) {
                $names[$use[1]] = '' !== ($use[3] ?? '') ? $use[3] : substr((string) strrchr($use[1], '\\'), 1);
            }
        }
        preg_match_all('/\\\\(App\\\\Module\\\\(\w+)\\\\(?:Application|Domain|Infrastructure)\\\\[A-Za-z0-9_\\\\]+)/', $content, $inline, \PREG_SET_ORDER);
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
        // Another module's repository is its storage, read or written past the module that keeps it: never a contract.
        if (str_contains($name, '\\Domain\\Repository\\')) {
            return false;
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

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * docs/SPEC.md § 3 "Architecture style": every bounded context under src/ has Domain/, Application/ and
 * Infrastructure/, and dependencies point inwards only. Domain and Application know nothing of Symfony, API
 * Platform or Doctrine beyond the ruled carve-outs (identifiers, PSR-20 clock, attribute mapping), and never of
 * any Infrastructure. The check reads `use` statements and inline fully-qualified names alike.
 */
final class LayerDependenciesTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    /** What a Domain file may name outside its own context's Domain. */
    private const array DOMAIN_ALLOWED = [
        'Symfony\Component\Uid\\',
        'Doctrine\ORM\Mapping',
        'Doctrine\DBAL\Types\Types',
    ];

    /** What an Application file may name on top of every Domain and every Application. */
    private const array APPLICATION_ALLOWED = [
        'Symfony\Component\Uid\\',
        'Psr\Clock\\',
    ];

    private const array FRAMEWORKS = ['Symfony\\', 'ApiPlatform\\', 'Doctrine\\', 'Psr\\'];

    public function testEveryContextHasTheThreeLayersAndNothingElse(): void
    {
        $contexts = $this->contexts();
        self::assertNotEmpty($contexts, 'src/ holds at least one bounded context (a directory with a Domain/ or Application/ layer)');

        foreach ($contexts as $context) {
            $layers = array_map('basename', glob(self::SRC."/$context/*", \GLOB_ONLYDIR) ?: []);
            sort($layers);
            self::assertSame([], array_diff($layers, ['Application', 'Domain', 'Infrastructure']), "$context/ has only Domain, Application and Infrastructure");
            $stray = array_filter(glob(self::SRC."/$context/*.php") ?: []);
            self::assertSame([], $stray, "$context/ keeps no file outside a layer");
        }
        $atRoot = array_map('basename', glob(self::SRC.'/*.php') ?: []);
        self::assertSame(['Kernel.php'], $atRoot, 'the only class at the root of src/ is the kernel');
    }

    public function testDomainImportsNoFrameworkBeyondTheRuledCarveOuts(): void
    {
        $violations = [];
        foreach ($this->files('Domain') as $file) {
            foreach ($this->namesUsedIn($file) as $name) {
                if ($this->isFramework($name) && !$this->startsWithAny($name, self::DOMAIN_ALLOWED)) {
                    $violations[] = "$file names $name";
                }
                if (str_contains($name, '\Infrastructure\\') || str_contains($name, '\Application\\')) {
                    $violations[] = "$file names $name";
                }
            }
        }
        self::assertSame([], $violations, "Domain depends on nothing outward:\n".implode("\n", $violations));
    }

    public function testApplicationImportsNoFrameworkAndNoInfrastructure(): void
    {
        $violations = [];
        foreach ($this->files('Application') as $file) {
            foreach ($this->namesUsedIn($file) as $name) {
                if ($this->isFramework($name) && !$this->startsWithAny($name, self::APPLICATION_ALLOWED)) {
                    $violations[] = "$file names $name";
                }
                if (str_contains($name, '\Infrastructure\\')) {
                    $violations[] = "$file names $name";
                }
            }
        }
        self::assertSame([], $violations, "Application depends on Domain, Application and the ruled ports only:\n".implode("\n", $violations));
    }

    public function testEveryClassLivesInTheNamespaceItsPathSays(): void
    {
        $mismatches = [];
        foreach ($this->allFiles() as $file) {
            $expected = 'App'.str_replace('/', '\\', \dirname(substr($file, \strlen(self::SRC))));
            $expected = rtrim($expected, '\\');
            $content = (string) file_get_contents($file);
            if (1 !== preg_match('/^namespace ([^;]+);/m', $content, $m) || $m[1] !== $expected) {
                $mismatches[] = "$file declares ".($m[1] ?? 'no namespace').", expected $expected";
            }
        }
        self::assertSame([], $mismatches, implode("\n", $mismatches));
    }

    /** @return list<string> */
    private function contexts(): array
    {
        $contexts = [];
        // A module (docs/SPEC.md § 3 Modules) is a context one level down, under src/Module/<Name>/.
        foreach ([...glob(self::SRC.'/*', \GLOB_ONLYDIR) ?: [], ...glob(self::SRC.'/Module/*', \GLOB_ONLYDIR) ?: []] as $dir) {
            if (is_dir("$dir/Domain") || is_dir("$dir/Application") || is_dir("$dir/Infrastructure")) {
                $contexts[] = substr($dir, \strlen(self::SRC) + 1);
            }
        }

        return $contexts;
    }

    /** @return list<string> */
    private function files(string $layer): array
    {
        $files = [];
        foreach ($this->contexts() as $context) {
            $files = [...$files, ...$this->phpFilesUnder(self::SRC."/$context/$layer")];
        }

        return $files;
    }

    /** @return list<string> */
    private function allFiles(): array
    {
        return $this->phpFilesUnder(self::SRC);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && 'php' === $entry->getExtension()) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every namespaced name a file refers to: its `use` imports and any inline fully-qualified name.
     *
     * @return list<string>
     */
    private function namesUsedIn(string $file): array
    {
        $content = (string) file_get_contents($file);
        $names = [];
        preg_match_all('/^use ([A-Za-z0-9_\\\\]+)(?: as \w+)?;/m', $content, $uses);
        foreach ($uses[1] as $name) {
            $names[] = $name;
        }
        // Inline references keep their leading backslash in code: \Symfony\…, \Doctrine\…; PHP's own classes are one segment.
        preg_match_all('/\\\\((?:[A-Z][A-Za-z0-9_]*\\\\)+[A-Za-z0-9_]+)/', $content, $inline);
        foreach ($inline[1] as $name) {
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    private function isFramework(string $name): bool
    {
        return $this->startsWithAny($name, self::FRAMEWORKS);
    }

    /** @param list<string> $prefixes */
    private function startsWithAny(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

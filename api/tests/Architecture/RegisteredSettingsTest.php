<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Settings\Application\SettingCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/SPEC.md § 3 Settings: code reads a business value through ReadSetting with a key some module declares, so
 * no read names a key the catalogue does not know. A key built at run time is refused by ReadSetting itself.
 */
final class RegisteredSettingsTest extends KernelTestCase
{
    private const string SRC = __DIR__.'/../../src';

    public function testEveryKeyReadInCodeIsDeclared(): void
    {
        self::bootKernel();
        $catalog = static::getContainer()->get(SettingCatalog::class);

        $unknown = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            foreach (self::keysReadIn((string) file_get_contents($file->getPathname())) as $key) {
                if (null === $catalog->definitionOf($key)) {
                    $unknown[] = $file->getPathname()." reads $key";
                }
            }
        }

        self::assertSame([], $unknown, "Every setting read in code is declared:\n".implode("\n", $unknown));
    }

    public function testTheScanFindsAKeyReadThroughReadSetting(): void
    {
        $source = <<<'PHP'
            use App\Settings\Application\ReadSetting;

            final class Terms
            {
                public function __construct(private ReadSetting $settings) {}

                public function days(SettingContext $context): mixed
                {
                    return $this->settings->value($context, 'document.payment_terms_days');
                }
            }
            PHP;

        self::assertSame(['document.payment_terms_days'], self::keysReadIn($source));
        self::assertSame([], self::keysReadIn(str_replace('use App\Settings\Application\ReadSetting;', '', $source)));
    }

    /** @return list<string> the key literals a file passes to ReadSetting::value() */
    private static function keysReadIn(string $source): array
    {
        if (!str_contains($source, 'App\Settings\Application\ReadSetting')) {
            return [];
        }
        preg_match_all('/->value\(\s*\$\w+\s*,\s*\'([^\']+)\'\s*\)/', $source, $matches);

        return $matches[1];
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Intl\CatalogueLanguages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CatalogueLanguagesTest extends TestCase
{
    private const string SHIPPED = __DIR__.'/../../../../config/languages.json';

    public function testTheShippedCatalogueSpeaksAndPrintsFrenchEnglishAndArabic(): void
    {
        $languages = new CatalogueLanguages(self::SHIPPED);

        self::assertSame('fr', $languages->default());
        self::assertSame(['fr', 'en', 'ar'], $languages->interface());
        self::assertSame(['fr', 'en', 'ar'], $languages->documents());
        self::assertSame(['fr', 'en', 'ar'], $languages->legal());
    }

    public function testALanguageTheInterfaceDoesNotSpeakIsAnsweredInTheDefault(): void
    {
        $languages = new CatalogueLanguages(self::SHIPPED);

        self::assertSame('en', $languages->interfaceOr('en'));
        self::assertSame('ar', $languages->interfaceOr('ar'));
        self::assertSame('fr', $languages->interfaceOr('de'));
        self::assertSame('fr', $languages->interfaceOr('EN'));
        self::assertSame('fr', $languages->interfaceOr(null));
    }

    public function testEachLanguageReadsInTheDirectionItsEntryGivesAndAnUnknownOneInTheDefaults(): void
    {
        $languages = new CatalogueLanguages(self::SHIPPED);

        self::assertSame('ltr', $languages->direction('fr'));
        self::assertSame('ltr', $languages->direction('en'));
        self::assertSame('rtl', $languages->direction('ar'));
        self::assertSame('ltr', $languages->direction('xx'));
    }

    public function testTheWebCarriesTheSameCatalogue(): void
    {
        self::assertJsonFileEqualsJsonFile(self::SHIPPED, __DIR__.'/../../../../../web/src/app/shared/i18n/languages.json');
    }

    /**
     * Symfony's translator takes its locales from configuration, which cannot read the catalogue: it is held to it here.
     * It mails in the interface's languages and prints in the documents', so it is enabled for both.
     */
    public function testTheTranslatorIsEnabledForExactlyTheLanguagesItMailsAndPrintsIn(): void
    {
        $languages = new CatalogueLanguages(self::SHIPPED);
        /** @var array{framework: array{default_locale: string, enabled_locales: list<string>}} $config */
        $config = Yaml::parseFile(__DIR__.'/../../../../config/packages/translation.yaml');

        self::assertSame($languages->default(), $config['framework']['default_locale']);
        self::assertSame(array_values(array_unique([...$languages->interface(), ...$languages->documents()])), $config['framework']['enabled_locales']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenCatalogues(): iterable
    {
        $fr = ['tag' => 'fr', 'name' => 'Français', 'direction' => 'ltr', 'script' => 'Latn', 'plurals' => ['one', 'many', 'other'], 'interface' => true, 'documents' => true, 'legal' => true];
        $catalogue = static fn (array $languages, string $default = 'fr'): string => json_encode(['default' => $default, 'languages' => $languages], \JSON_THROW_ON_ERROR);

        yield 'not JSON' => ['{', 'not JSON'];
        yield 'no language' => [$catalogue([]), 'no language'];
        yield 'a tag twice' => [$catalogue([$fr, $fr]), '"fr" twice'];
        yield 'a tag not canonical' => [$catalogue([['tag' => 'FR'] + $fr], 'FR'), '"FR", which is not a canonical BCP 47 tag'];
        yield 'a direction neither way' => [$catalogue([['direction' => 'up'] + $fr]), 'direction'];
        yield 'a flag not a boolean' => [$catalogue([['interface' => 'yes'] + $fr]), 'interface'];
        yield 'a default the interface does not speak' => [$catalogue([$fr, ['tag' => 'ar', 'interface' => false] + $fr], 'ar'), 'default "ar"'];
        yield 'no plural form other' => [$catalogue([['plurals' => ['one']] + $fr]), 'other'];
    }

    #[DataProvider('brokenCatalogues')]
    public function testABrokenCatalogueStopsTheApplicationRatherThanSpeakingNothing(string $json, string $named): void
    {
        $file = tempnam(sys_get_temp_dir(), 'languages');
        self::assertIsString($file);
        try {
            file_put_contents($file, $json);
            $this->expectException(\UnexpectedValueException::class);
            $this->expectExceptionMessage($named);
            (new CatalogueLanguages($file))->default();
        } finally {
            unlink($file);
        }
    }
}

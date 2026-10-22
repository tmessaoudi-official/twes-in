<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\Application\SettingCatalog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The web manifest a browser reads before it offers to install the application: written by the API from the product's
 * name and the accent setting's default, the one source of each, so the installed application is named and coloured
 * as the screens are, and the operator's branding (docs/SPEC.md § 8 row 36) changes it in one place.
 */
final class WebManifestTest extends WebTestCase
{
    private const string WEB = __DIR__.'/../../../web';

    public function testAnyoneReadsTheManifestWrittenFromTheProductAndItsAccent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/web-manifest');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/manifest+json');
        $manifest = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $name = self::getContainer()->getParameter('app.product_name');
        self::assertSame($name, $manifest['name'] ?? null);
        self::assertSame($name, $manifest['short_name'] ?? null);
        self::assertSame(['/', '/', '/', 'standalone'], [$manifest['id'] ?? null, $manifest['start_url'] ?? null, $manifest['scope'] ?? null, $manifest['display'] ?? null]);
        self::assertSame(self::accent(), $manifest['theme_color'] ?? null);
    }

    /**
     * Chrome offers to install only with a 192 and a 512 pixel icon, and a phone that cuts its own shape keeps the circle
     * of 80 %: the maskable icon is a file of its own whose mark stays inside it, never the full-size one cropped.
     */
    public function testEveryIconItNamesIsServedAndTheMaskableOneIsItsOwn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/web-manifest');

        $manifest = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $icons = $manifest['icons'] ?? null;
        self::assertIsArray($icons);
        $sizes = [];
        $maskable = [];
        foreach ($icons as $icon) {
            self::assertIsArray($icon);
            $src = $icon['src'] ?? null;
            self::assertIsString($src);
            self::assertStringStartsWith('/', $src, 'an icon is named from the site root, wherever the manifest is read');
            self::assertFileExists(self::WEB.'/public'.$src);
            $sizes[] = $icon['sizes'] ?? null;
            if ('maskable' === ($icon['purpose'] ?? 'any')) {
                $maskable[] = $src;
            }
        }
        self::assertContains('192x192', $sizes);
        self::assertContains('512x512', $sizes);
        self::assertSame(['/icon-maskable.svg', '/icon-maskable-512.png'], $maskable);
    }

    /** Read by a browser with no session, the manifest neither reads nor starts one, and is the same for everyone. */
    public function testItOpensNoSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/web-manifest');

        self::assertResponseHeaderSame('cache-control', 'no-cache, public');
        self::assertNull($client->getResponse()->headers->getCookies()[0] ?? null);
    }

    /**
     * The page names the manifest the API writes, and what the web says before it reads anything (the browser's bar and
     * the page's title while it loads, the accent before the settings arrive, the brand's name) is the API's: copies the
     * build cannot read from the API, held to it here until the operator's branding sets them (docs/SPEC.md § 8 row 36).
     */
    public function testThePageNamesItAndTheWebsCopiesOfTheAccentAreTheDefault(): void
    {
        $index = (string) file_get_contents(self::WEB.'/src/index.html');
        self::assertStringContainsString('<link rel="manifest" href="/api/web-manifest" />', $index);
        self::assertFileDoesNotExist(self::WEB.'/public/manifest.webmanifest', 'one manifest, the one the API writes');
        self::assertSame(self::accent(), self::captured('/<meta name="theme-color" content="(#[0-9a-f]{6})" \/>/', $index));

        $registry = (string) file_get_contents(self::WEB.'/src/app/shared/settings/settings-registry.ts');
        self::assertSame(self::accent(), self::captured("/export const DEFAULT_ACCENT = '(#[0-9a-f]{6})';/", $registry));

        // The name the signed-out pages and the toasts say, the manifest's own.
        $brand = (string) file_get_contents(self::WEB.'/src/app/shared/brand/brand.ts');
        $webName = self::captured("/export const DEFAULT_BRAND_KIT: BrandKit = \{\n  name: '([^']+)',/", $brand);
        self::assertSame(self::getContainer()->getParameter('app.product_name'), $webName);
        self::assertSame($webName, self::captured('/<title>([^<]+)<\/title>/', $index), 'the title the page has before it names itself');
    }

    /** The one group the pattern captures in the file, which must hold it exactly once. */
    private static function captured(string $pattern, string $subject): string
    {
        self::assertSame(1, preg_match_all($pattern, $subject, $matches), $pattern);

        return $matches[1][0];
    }

    private static function accent(): string
    {
        $accent = self::getContainer()->get(SettingCatalog::class)->definitionOf('presentation.accent')?->default;
        self::assertIsString($accent);

        return $accent;
    }
}

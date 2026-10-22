<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\Http;

use App\Settings\Application\SettingCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The web manifest a browser reads before it offers to install the application on a computer or a phone, which
 * `web/src/index.html` names. Written from the product's name and the accent setting's default, each read from its one
 * source, so the operator's branding (docs/SPEC.md § 8 row 36) changes it where it changes the screens. Public by the
 * firewall: a browser reads it with no session, and it is the same for everyone.
 */
final readonly class WebManifestController
{
    private const string ACCENT = 'presentation.accent';

    public function __construct(
        private SettingCatalog $catalog,
        #[Autowire(param: 'app.product_name')]
        private string $productName,
    ) {
    }

    #[Route('/api/web-manifest', name: 'api_web_manifest', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $accent = $this->catalog->definitionOf(self::ACCENT)?->default;
        if (!\is_string($accent)) {
            throw new \LogicException(\sprintf('The setting %s has no colour for a default.', self::ACCENT));
        }
        // Icons are named from the site root, as the manifest is read from /api/. The maskable one is a file of its own,
        // its mark drawn inside the circle of 80 % a phone keeps when it cuts the icon to its own shape.
        $manifest = [
            'id' => '/',
            'name' => $this->productName,
            'short_name' => $this->productName,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // The splash a phone shows while the page loads, before any scheme is known: the light one's surface.
            'background_color' => '#ffffff',
            'theme_color' => $accent,
            'icons' => [
                ['src' => '/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'],
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icon-maskable.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        $response = new JsonResponse($manifest, JsonResponse::HTTP_OK, ['Content-Type' => 'application/manifest+json']);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        // The same for everyone, and asked again each time so a change of branding is seen at the next start.
        $response->setPublic();
        $response->headers->addCacheControlDirective('no-cache');

        return $response;
    }
}

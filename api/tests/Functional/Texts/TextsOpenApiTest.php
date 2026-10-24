<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional\Texts;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The texts' endpoints are plain controllers, so API Platform does not know them; the SPA's types still come from the
 * OpenAPI document, which carries them with every always-present property required.
 */
final class TextsOpenApiTest extends KernelTestCase
{
    public function testTheTextsReadsAndWritesAreInTheContract(): void
    {
        self::bootKernel();
        $openApi = static::getContainer()->get(OpenApiFactoryInterface::class)();
        $paths = $openApi->getPaths();
        $schemas = $openApi->getComponents()->getSchemas();
        self::assertNotNull($schemas);

        self::assertSame('installationScreenTexts', $paths->getPath('/api/texts/{language}')?->getGet()?->getOperationId());
        self::assertSame('companyScreenTexts', $paths->getPath('/api/companies/{companyId}/texts/{language}')?->getGet()?->getOperationId());
        self::assertSame('installationTextCatalogue', $paths->getPath('/api/platform/texts/{language}/catalogue')?->getGet()?->getOperationId());
        self::assertSame('companyTextCatalogue', $paths->getPath('/api/companies/{companyId}/texts/{language}/catalogue')?->getGet()?->getOperationId());
        $platform = $paths->getPath('/api/platform/texts/{language}/{domain}/{key}');
        self::assertNotNull($platform);
        self::assertSame('overrideInstallationText', $platform->getPut()?->getOperationId());
        self::assertSame('resetInstallationText', $platform->getDelete()?->getOperationId());
        $company = $paths->getPath('/api/companies/{companyId}/texts/{language}/{domain}/{key}');
        self::assertNotNull($company);
        self::assertSame('overrideCompanyText', $company->getPut()?->getOperationId());
        self::assertSame('resetCompanyText', $company->getDelete()?->getOperationId());

        self::assertSame(['value', 'version'], self::plain($schemas['TextOverrideChange'])['required'] ?? null);
        self::assertSame(['value', 'original', 'version'], self::plain($schemas['TextOverride'])['required'] ?? null);
        self::assertSame(['error', 'detail'], self::plain($schemas['TextOverrideRefusal'])['required'] ?? null);
        self::assertSame(['domain', 'key', 'original', 'installation', 'company', 'version', 'originalChanged'], self::plain($schemas['TextCatalogueEntry'])['required'] ?? null);
        self::assertSame(['items', 'total', 'page', 'itemsPerPage'], self::plain($schemas['TextCataloguePage'])['required'] ?? null);
    }

    /** @return array<array-key, mixed> */
    private static function plain(mixed $schema): array
    {
        $plain = json_decode(json_encode($schema, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($plain);

        return $plain;
    }
}

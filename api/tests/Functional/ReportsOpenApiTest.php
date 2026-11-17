<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\Module\Reports\Application\ReportCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A report's options are named in its address, so the contract declares each one some report offers, with its choices,
 * on the report and on a row's documents, as it declares the period and the comparison.
 */
final class ReportsOpenApiTest extends KernelTestCase
{
    public function testEveryOptionAReportOffersIsAParameterOfTheReportAndOfItsDocuments(): void
    {
        self::bootKernel();
        $openApi = static::getContainer()->get(OpenApiFactoryInterface::class)();
        $offered = static::getContainer()->get(ReportCatalogue::class)->options();
        self::assertSame(['netBeforeDiscount' => ['hidden', 'shown'], 'mergedProducts' => ['kept', 'apart']], $offered);

        foreach (['/api/companies/{companyId}/reports/{key}', '/api/companies/{companyId}/reports/{key}/documents'] as $path) {
            $read = $openApi->getPaths()->getPath($path)?->getGet();
            self::assertNotNull($read, $path);
            $declared = [];
            foreach ($read->getParameters() ?? [] as $parameter) {
                if ('query' === $parameter->getIn()) {
                    $declared[$parameter->getName()] = $parameter->getSchema()['enum'] ?? null;
                }
            }
            foreach ($offered as $name => $choices) {
                self::assertSame($choices, $declared[$name] ?? null, "$path declares $name with its choices");
            }
            self::assertArrayHasKey('from', $declared, "$path keeps the parameters its resource declares");
        }
    }
}

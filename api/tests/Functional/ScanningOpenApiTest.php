<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The phone pairing's plain controllers are unknown to API Platform, so the SPA's types come from ScanningOpenApi.
 * Read from the router, so a route added without its document reds here rather than as an `any` in the SPA.
 */
final class ScanningOpenApiTest extends KernelTestCase
{
    public function testEveryPairingRouteIsInTheContract(): void
    {
        self::bootKernel();
        $openApi = static::getContainer()->get(OpenApiFactoryInterface::class)();
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $checked = 0;
        foreach ($routes as $name => $route) {
            if (!str_starts_with($name, 'api_scan_pairing_')) {
                continue;
            }
            $item = $openApi->getPaths()->getPath($route->getPath());
            self::assertNotNull($item, "$name ({$route->getPath()}) is documented");
            foreach ($route->getMethods() as $method) {
                $operation = match ($method) {
                    'GET' => $item->getGet(),
                    'POST' => $item->getPost(),
                    'DELETE' => $item->getDelete(),
                    default => null,
                };
                self::assertNotNull($operation, "$name answers $method in the document");
                self::assertNotEmpty($operation->getOperationId(), "$name has an operationId");
            }
            ++$checked;
        }

        self::assertSame(8, $checked, 'the eight pairing routes are read from the router');
    }
}

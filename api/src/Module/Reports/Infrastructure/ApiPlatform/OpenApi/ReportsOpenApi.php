<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\ApiPlatform\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\OpenApi;
use App\Module\Reports\Application\ReportCatalogue;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Declares on a report and on a row's documents each option some report offers, from the declarations themselves: the
 * modules declare their options, so the resource cannot name them.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class ReportsOpenApi implements OpenApiFactoryInterface
{
    private const array PATHS = ['/api/companies/{companyId}/reports/{key}', '/api/companies/{companyId}/reports/{key}/documents'];

    public function __construct(private OpenApiFactoryInterface $decorated, private ReportCatalogue $catalogue)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();
        $options = [];
        foreach ($this->catalogue->options() as $name => $choices) {
            $options[] = new Parameter($name, 'query', 'An option of the reports offering it (the catalogue\'s `options`), its first choice the default; refused (422) by a report that offers none of that name, or for a choice it does not give.', false, schema: ['type' => 'string', 'enum' => $choices]);
        }
        foreach (self::PATHS as $path) {
            $item = $paths->getPath($path);
            $read = $item?->getGet();
            if (null === $item || null === $read) {
                throw new \LogicException(\sprintf('The report path %s is no longer in the contract.', $path));
            }
            $paths->addPath($path, $item->withGet($read->withParameters([...$read->getParameters() ?? [], ...$options])));
        }

        return $openApi;
    }
}

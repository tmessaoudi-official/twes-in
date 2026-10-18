<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Reports\Application\InvalidReportRequest;
use App\Module\Reports\Application\ReportCatalogue;
use App\Module\Reports\Application\RunReport;
use App\Module\Reports\Infrastructure\ApiPlatform\Payload\ReportContexts;
use App\Module\Reports\Infrastructure\ApiPlatform\Payload\ReportRequest;
use App\Module\Reports\Infrastructure\ApiPlatform\Resource\ReportResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProviderInterface<ReportResource> */
final readonly class ReportProvider implements ProviderInterface
{
    public function __construct(private ReportContexts $contexts, private ReportCatalogue $catalogue, private RunReport $run)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportResource
    {
        $asked = ReportRequest::of($operation);
        $reading = $this->contexts->of($uriVariables, $asked->establishment);
        $key = $uriVariables['key'] ?? null;
        $report = \is_string($key) ? $this->catalogue->declarationOf($key, $reading) : null;
        if (null === $report) {
            throw new NotFoundHttpException('There is no report by that name.');
        }

        try {
            return ReportResource::of($this->run->run($report, $reading, $asked->periodFor($report, $reading), $asked->comparison));
        } catch (InvalidReportRequest $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }
    }
}

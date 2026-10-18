<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Reports\Application\ReportCatalogue;
use App\Module\Reports\Infrastructure\ApiPlatform\Payload\ReportContexts;
use App\Module\Reports\Infrastructure\ApiPlatform\Payload\ReportRequest;
use App\Module\Reports\Infrastructure\ApiPlatform\Resource\ReportDocumentsResource;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProviderInterface<ReportDocumentsResource> */
final readonly class ReportDocumentsProvider implements ProviderInterface
{
    public function __construct(private ReportContexts $contexts, private ReportCatalogue $catalogue)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportDocumentsResource
    {
        $asked = ReportRequest::of($operation);
        $reading = $this->contexts->of($uriVariables, $asked->establishment);
        $key = $uriVariables['key'] ?? null;
        $report = \is_string($key) ? $this->catalogue->declarationOf($key, $reading) : null;
        if (null === $report) {
            throw new NotFoundHttpException('There is no report by that name.');
        }
        if (null !== $asked->establishment && !$report->byEstablishment()) {
            throw new UnprocessableEntityHttpException(\sprintf('establishment: the report %s cannot be narrowed to an establishment.', $report->key()));
        }
        $row = Paging::text($operation, 'row');
        if (null === $row) {
            throw new UnprocessableEntityHttpException('row: the key of a row of the report.');
        }

        return ReportDocumentsResource::of($report->documents($reading, $asked->periodFor($report, $reading), $row));
    }
}

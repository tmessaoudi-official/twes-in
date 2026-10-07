<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Infrastructure\ApiPlatform\DocumentPreview;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\QuoteNotDraft;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * What a new quote, or a draft as it is being edited, would come to, kept nowhere: asked and refused as saving it is.
 *
 * @implements ProcessorInterface<QuoteResource, DocumentPreview>
 */
final readonly class PreviewQuoteProcessor implements ProcessorInterface
{
    public function __construct(private ManageQuotes $manage, private CompanyGuard $guard, private CurrencyScales $scales)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DocumentPreview
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::WRITE);
        $id = isset($uriVariables['quoteId']) ? CompanyPath::identifier($uriVariables, 'quoteId') : null;

        try {
            $totals = $this->manage->preview($company, $data->input(), $id);
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        } catch (QuoteNotDraft $fixed) {
            throw new ConflictHttpException($fixed->getMessage(), $fixed);
        } catch (InvalidQuote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return DocumentPreview::of($totals, $this->scales->of($company->getCurrency()));
    }
}

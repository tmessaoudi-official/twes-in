<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\QuoteNotDraft;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<QuoteResource, QuoteResource> */
final readonly class ReviseQuoteProcessor implements ProcessorInterface
{
    public function __construct(private ManageQuotes $manage, private QuoteView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): QuoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::WRITE);

        try {
            $quote = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'quoteId'), $data->input(), $this->guard->account()->getId());
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        } catch (QuoteNotDraft $fixed) {
            throw new ConflictHttpException($fixed->getMessage(), $fixed);
        } catch (InvalidQuote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return $this->view->of($quote);
    }
}

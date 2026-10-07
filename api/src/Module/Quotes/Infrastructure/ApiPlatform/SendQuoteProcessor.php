<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Application\QuoteNumberTaken;
use App\Module\Quotes\Application\QuoteWorkflow;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\QuoteNotDraft;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * « Marquer envoyé »: numbers a draft and fixes what it says. What the company's numbering cannot give is a 409, like a
 * quote that is no longer a draft; what the quote lacks is a 422.
 *
 * @implements ProcessorInterface<mixed, QuoteResource>
 */
final readonly class SendQuoteProcessor implements ProcessorInterface
{
    public function __construct(private QuoteWorkflow $workflow, private QuoteView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): QuoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::WRITE);

        try {
            $quote = $this->workflow->send($company, CompanyPath::identifier($uriVariables, 'quoteId'), $this->guard->account()->getId());
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        } catch (QuoteNotDraft|NoNumberingSeries|InvalidNumbering|QuoteNumberTaken $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidQuote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return $this->view->of($quote);
    }
}

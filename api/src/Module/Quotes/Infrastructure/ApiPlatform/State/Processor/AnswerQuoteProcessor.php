<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Module\Quotes\Application\QuoteWorkflow;
use App\Module\Quotes\Domain\Exception\InvalidQuote;
use App\Module\Quotes\Domain\Exception\QuoteTransitionRefused;
use App\Module\Quotes\Infrastructure\ApiPlatform\Output\QuoteView;
use App\Module\Quotes\Infrastructure\ApiPlatform\Resource\QuoteResource;
use App\Module\Quotes\Infrastructure\ApiPlatform\Security\QuotePermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Marquer accepté » and « Marquer refusé »: the customer's answer to a sent quote, on a day from its issue day to the
 * company's today, and why it was refused when the company was told.
 *
 * @implements ProcessorInterface<QuoteResource, QuoteResource>
 */
final readonly class AnswerQuoteProcessor implements ProcessorInterface
{
    public function __construct(private QuoteWorkflow $workflow, private QuoteView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): QuoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::WRITE);
        $id = CompanyPath::identifier($uriVariables, 'quoteId');
        $on = null === $data->answeredOn ? null : new \DateTimeImmutable($data->answeredOn, new \DateTimeZone('UTC'));
        $actor = $this->guard->account()->getId();

        try {
            $quote = $operation instanceof HttpOperation && str_ends_with($operation->getUriTemplate() ?? '', '/refuse')
                ? $this->workflow->refuse($company, $id, $on, $data->refusalReason, $actor)
                : $this->workflow->accept($company, $id, $on, $actor);
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        } catch (QuoteTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidQuote $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return $this->view->of($quote);
    }
}

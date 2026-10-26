<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\State\Processor;

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
 * Who answered an accepted or refused quote for its customer, put right by whoever writes quotes.
 *
 * @implements ProcessorInterface<QuoteResource, QuoteResource>
 */
final readonly class ReceiveQuoteProcessor implements ProcessorInterface
{
    public function __construct(private QuoteWorkflow $workflow, private QuoteView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): QuoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::WRITE);

        try {
            $quote = $this->workflow->correctReceiver($company, CompanyPath::identifier($uriVariables, 'quoteId'), $data->receiverChoice(), $this->guard->account()->getId());
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

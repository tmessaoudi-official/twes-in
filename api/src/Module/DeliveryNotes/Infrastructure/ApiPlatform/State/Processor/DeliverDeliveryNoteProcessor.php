<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Output\DeliveryNoteView;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<DeliveryNoteResource, DeliveryNoteResource> */
final readonly class DeliverDeliveryNoteProcessor implements ProcessorInterface
{
    public function __construct(private DeliveryNoteWorkflow $workflow, private DeliveryNoteView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::WRITE);
        $delivered = null === $data->delivered ? null : \DateTimeImmutable::createFromFormat('!'.DeliveryNoteResource::LOCAL_MOMENT, $data->delivered, new \DateTimeZone($company->getTimezone()));
        if (false === $delivered) {
            throw new UnprocessableEntityHttpException('delivered: not a day and a time.');
        }

        try {
            $note = $this->workflow->deliver(
                $company,
                CompanyPath::identifier($uriVariables, 'deliveryNoteId'),
                $delivered,
                $this->guard->account()->getId(),
            );
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        } catch (DeliveryNoteTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidDeliveryNote $refused) {
            // The moment refused is the one this request sent, under its own name.
            $field = 'deliveredAt' === $refused->field ? 'delivered' : $refused->field;
            throw DomainRule::refused($field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return $this->view->of($note);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<DeliveryNoteResource, DeliveryNoteResource> */
final readonly class DeliverDeliveryNoteProcessor implements ProcessorInterface
{
    public function __construct(private DeliveryNoteWorkflow $workflow, private DeliveryNoteTotals $totals, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::WRITE);
        $deliveredOn = $data->deliveredOn;

        try {
            $note = $this->workflow->deliver(
                $company,
                CompanyPath::identifier($uriVariables, 'deliveryNoteId'),
                null === $deliveredOn ? null : new \DateTimeImmutable($deliveredOn, new \DateTimeZone('UTC')),
                $this->guard->account()->getId(),
            );
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        } catch (DeliveryNoteTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidDeliveryNote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return DeliveryNoteResource::of($note, $this->totals->of($note));
    }
}

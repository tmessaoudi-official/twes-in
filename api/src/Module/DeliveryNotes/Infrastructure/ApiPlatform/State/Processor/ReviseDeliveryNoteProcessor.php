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
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Output\DeliveryNoteView;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<DeliveryNoteResource, DeliveryNoteResource> */
final readonly class ReviseDeliveryNoteProcessor implements ProcessorInterface
{
    public function __construct(private ManageDeliveryNotes $manage, private DeliveryNoteView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::WRITE);

        try {
            $note = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'deliveryNoteId'), $data->input(), $this->guard->account()->getId(), $data->version);
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        } catch (DeliveryNoteNotDraft $fixed) {
            throw new ConflictHttpException($fixed->getMessage(), $fixed);
        } catch (InvalidDeliveryNote $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return $this->view->of($note);
    }
}

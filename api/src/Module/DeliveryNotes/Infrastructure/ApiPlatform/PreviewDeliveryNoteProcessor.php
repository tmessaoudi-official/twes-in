<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Infrastructure\ApiPlatform\DocumentPreview;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * What a new delivery note, or a draft as it is being edited, would come to, kept nowhere: asked and refused as saving it is.
 *
 * @implements ProcessorInterface<DeliveryNoteResource, DocumentPreview>
 */
final readonly class PreviewDeliveryNoteProcessor implements ProcessorInterface
{
    public function __construct(private ManageDeliveryNotes $manage, private CompanyGuard $guard, private CurrencyScales $scales)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DocumentPreview
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::WRITE);
        $id = isset($uriVariables['deliveryNoteId']) ? CompanyPath::identifier($uriVariables, 'deliveryNoteId') : null;

        try {
            $totals = $this->manage->preview($company, $data->input(), $id);
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        } catch (DeliveryNoteNotDraft $fixed) {
            throw new ConflictHttpException($fixed->getMessage(), $fixed);
        } catch (InvalidDeliveryNote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return DocumentPreview::of($totals, $this->scales->of($company->getCurrency()));
    }
}

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
use App\Module\DeliveryNotes\Application\DeliveryNoteNumberTaken;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Domain\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Numbers a draft. What the company's numbering cannot give (no series, a day before its last month, a number another
 * establishment already gave) is a 409, like a note that is no longer a draft; what the note lacks is a 422.
 *
 * @implements ProcessorInterface<mixed, DeliveryNoteResource>
 */
final readonly class ValidateDeliveryNoteProcessor implements ProcessorInterface
{
    public function __construct(private DeliveryNoteWorkflow $workflow, private DeliveryNoteTotals $totals, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::VALIDATE);

        try {
            $note = $this->workflow->validate($company, CompanyPath::identifier($uriVariables, 'deliveryNoteId'), $this->guard->account()->getId());
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        } catch (DeliveryNoteNotDraft|NoNumberingSeries|InvalidNumbering|DeliveryNoteNumberTaken $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidDeliveryNote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return DeliveryNoteResource::of($note, $this->totals->of($note));
    }
}

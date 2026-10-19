<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\ImportSwitch;
use App\ImportExport\Application\UnknownImportSubject;
use App\ImportExport\Domain\Repository\ImportRunRepository;
use App\ImportExport\Infrastructure\ApiPlatform\Output\ImportGuideColumn;
use App\ImportExport\Infrastructure\ApiPlatform\Output\ImportGuideSwitch;
use App\ImportExport\Infrastructure\ApiPlatform\Resource\ImportGuideResource;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The import guide for one subject and one company. A heading the screen's catalogue holds is left for the screen to
 * translate; a preset's label is translated here, in the person's language, since the API owns the fiscal catalogue.
 *
 * @implements ProviderInterface<ImportGuideResource>
 */
final readonly class ImportGuideProvider implements ProviderInterface
{
    public function __construct(
        private ImportCatalogue $catalogue,
        private CompanyGuard $guard,
        private TranslatorInterface $translator,
        private ImportRunRepository $runs,
        #[Autowire(param: 'app.import.max_rows')]
        private int $maxRows,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ImportGuideResource
    {
        $key = \is_string($uriVariables['subject'] ?? null) ? $uriVariables['subject'] : '';
        try {
            // Importing writes, so the guide is for whoever may import: the same check, in the same order, as the import.
            $declaration = $this->catalogue->declaration($key);
            $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), $declaration->permission());
            $subject = $this->catalogue->subject($key, $company);
        } catch (UnknownImportSubject $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }

        $guide = new ImportGuideResource();
        $guide->subject = $subject->key;
        $guide->identity = $declaration->identityColumns();
        $guide->maxRows = $this->maxRows;
        $guide->columns = array_map(fn (ImportColumn $column): ImportGuideColumn => new ImportGuideColumn(
            $column->key,
            $column->required,
            ImportHeading::ScreenText === $column->headingIs ? $column->heading : null,
            match ($column->headingIs) {
                ImportHeading::ScreenText => null,
                ImportHeading::FiscalLabel => $this->translator->trans($column->heading, [], 'fiscal'),
                ImportHeading::Label => $column->heading,
            },
            $column->example,
            $column->note,
        ), $subject->columns);
        $guide->switches = array_map(static fn (ImportSwitch $switch): ImportGuideSwitch => new ImportGuideSwitch($switch->key, $switch->label, $switch->note), $subject->switches);
        $guide->lastPairing = [];
        foreach ($this->runs->lastPairingOf($company->getId(), $declaration->key()) as $heading => $column) {
            $guide->lastPairing[] = ['heading' => (string) $heading, 'column' => $column];
        }

        return $guide;
    }
}

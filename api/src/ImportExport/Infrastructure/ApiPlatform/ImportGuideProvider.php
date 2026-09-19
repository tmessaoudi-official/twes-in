<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Identity\Domain\UserRepository;
use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\UnknownImportSubject;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
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
        private UserRepository $users,
        private TranslatorInterface $translator,
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
        $locale = $this->users->ofId($this->guard->account()->getId())?->getLocale() ?? $company->getLocale();

        $guide = new ImportGuideResource();
        $guide->subject = $subject->key;
        $guide->identity = $declaration->identityColumn();
        $guide->maxRows = $this->maxRows;
        $guide->columns = array_map(fn (ImportColumn $column): ImportGuideColumn => new ImportGuideColumn(
            $column->key,
            $column->required,
            ImportHeading::ScreenText === $column->headingIs ? $column->heading : null,
            match ($column->headingIs) {
                ImportHeading::ScreenText => null,
                ImportHeading::FiscalLabel => $this->translator->trans($column->heading, [], 'fiscal', $locale),
                ImportHeading::Label => $column->heading,
            },
            $column->example,
            $column->note,
        ), $subject->columns);

        return $guide;
    }
}

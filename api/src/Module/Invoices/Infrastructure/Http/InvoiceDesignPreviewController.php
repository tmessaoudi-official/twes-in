<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Http;

use App\Module\Invoices\Application\NothingToPreview;
use App\Module\Invoices\Application\PrintInvoice;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Infrastructure\ApiPlatform\SettingAccess;
use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Domain\DocumentDesign;
use App\Shared\Domain\DocumentLayout;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The company's latest invoice in a design, its first page as a PNG picture (docs/SPEC.md § 7, 2026-10-06 10:19), for
 * the screen where the design is chosen: `layout`, `accent`, `logoWidth` and `logoHeight` (millimetres) and
 * `logoProportions` (keep or free) in the query try a design before it is saved, and the company's own design stands for
 * what is left out. It is the settings page's, so it asks company.settings, and it
 * shows an invoice, so it asks invoice.read as well. Documented in InvoicesOpenApi.
 */
#[AsController]
final readonly class InvoiceDesignPreviewController
{
    /** What a 404 says when the company has no invoice to show a design on: the screen names it, it is no stranger's 404. */
    public const string NOTHING_TO_PREVIEW = 'nothing_to_preview';

    public function __construct(private PrintInvoice $print, private CompanyGuard $guard, private ReadSetting $settings)
    {
    }

    #[Route('/api/companies/{companyId}/invoice-design-preview', name: 'api_invoice_design_preview', methods: ['GET'])]
    public function __invoke(string $companyId, Request $request): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), SettingAccess::SHARE);
        if (!$this->guard->may($company, InvoicePermission::READ)) {
            throw new AccessDeniedHttpException('Showing a design shows an invoice: invoice.read is needed as well.');
        }
        $saved = DocumentFormats::design($this->settings, $company);
        $layout = $request->query->get('layout');
        $accent = $request->query->get('accent');
        $design = new DocumentDesign(
            null === $layout ? $saved->layout : (DocumentLayout::tryFrom($layout) ?? throw new UnprocessableEntityHttpException('layout: One of '.implode(', ', array_map(static fn (DocumentLayout $each): string => $each->value, DocumentLayout::cases())).'.')),
            null === $accent ? $saved->accent : (1 === preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : throw new UnprocessableEntityHttpException('accent: A colour written #rrggbb.')),
            self::millimetres($request, 'logoWidth', $saved->logoWidthMm, DocumentDesign::LOGO_WIDTH_MM_MIN, DocumentDesign::LOGO_WIDTH_MM_MAX),
            self::millimetres($request, 'logoHeight', $saved->logoHeightMm, DocumentDesign::LOGO_HEIGHT_MM_MIN, DocumentDesign::LOGO_HEIGHT_MM_MAX),
            match ($request->query->get('logoProportions')) {
                null => $saved->logoKeepsProportions,
                'keep' => true,
                'free' => false,
                default => throw new UnprocessableEntityHttpException('logoProportions: keep or free.'),
            },
        );

        try {
            $picture = $this->print->designPreview($company, $design);
        } catch (NothingToPreview $none) {
            throw new NotFoundHttpException(self::NOTHING_TO_PREVIEW, $none);
        } catch (PdfRenderingFailed $failure) {
            throw new ServiceUnavailableHttpException(30, 'The preview could not be rendered; try again shortly.', $failure);
        }

        return new Response($picture, Response::HTTP_OK, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    /** A whole number of millimetres within what a page holds, or the company's own when the query leaves it out. */
    private static function millimetres(Request $request, string $name, int $saved, int $min, int $max): int
    {
        $given = $request->query->get($name);
        if (null === $given) {
            return $saved;
        }
        if (1 !== preg_match('/^\d{1,3}$/', $given) || (int) $given < $min || (int) $given > $max) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: A whole number of millimetres from %d to %d.', $name, $min, $max));
        }

        return (int) $given;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Http;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Invoices\Application\Bulk\PrintInvoicesInBulk;
use App\Module\Invoices\Application\Print\NothingToPrint;
use App\Module\Invoices\Application\Print\PrintedInvoice;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Infrastructure\Http\BulkFile;
use App\Shared\Infrastructure\Http\LongRequest;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The invoices a person selected, as one PDF or as a zip of one PDF each, by their `id[]` in the order chosen, a list
 * page at most; `drafts=1` and `cancelled=1` take those too, under their watermark. Up to a hundred invoices leave the
 * company in one file, so, as for a list's export, it waits for a recent step-up and is recorded as `export.downloaded`.
 * A plain controller rather than a resource: the answer is bytes, not JSON. Documented in InvoicesOpenApi.
 */
#[AsController]
final readonly class InvoiceSelectionPdfController implements BulkFile, DeclaresAuditActions
{
    public function __construct(
        private PrintInvoicesInBulk $print,
        private CompanyGuard $guard,
        private Security $security,
        private ConfirmStepUp $stepUp,
        private AuditTrail $audit,
        private LongRequest $time,
    ) {
    }

    #[Route('/api/companies/{companyId}/exports/invoices.{format}', name: 'api_invoice_selection_pdf', requirements: ['format' => 'pdf|zip'], methods: ['GET'])]
    public function __invoke(Request $request, string $companyId, string $format): Response
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), InvoicePermission::READ);
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // companyForActing has already refused anyone not signed in, so this is a contradiction, not a user error.
            throw new \LogicException('A printed selection is asked for behind the firewall.');
        }
        // Asked after the company and the permission, so a stranger still meets the same 404 as for any company of others.
        if (!$this->stepUp->isRecent($account->getId())) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }

        $ids = [];
        foreach ($request->query->all('id') as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new UnprocessableEntityHttpException('Each id[] is an invoice identifier.');
            }
            $ids[] = Uuid::fromString($id);
        }
        $drafts = $request->query->getBoolean('drafts');
        $cancelled = $request->query->getBoolean('cancelled');
        // Each draft is rendered in turn: 100 of them took 37 s on a developer's machine.
        $this->time->lift();

        try {
            $response = 'pdf' === $format
                ? new Response($this->print->merged($company, $ids, $drafts, $cancelled), Response::HTTP_OK, ['Content-Type' => 'application/pdf'])
                : $this->zip($this->print->each($company, $ids, $drafts, $cancelled));
        } catch (\InvalidArgumentException|NothingToPrint $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        } catch (PdfRenderingFailed $failure) {
            throw new ServiceUnavailableHttpException(30, 'The PDF could not be rendered; try again shortly.', $failure);
        }

        $this->audit->record(new AuditEntry('export', $company->getId(), 'export.downloaded', $account->getId(), ['subject' => 'invoices', 'format' => $format, 'filters' => array_keys($request->query->all())], $company->getId()));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'invoices.'.$format));

        return $response;
    }

    /**
     * One PDF a file, each named as its own download is. Written to disk, since a zip has to be seekable, and removed
     * once sent.
     *
     * @param non-empty-list<PrintedInvoice> $printed
     */
    private function zip(array $printed): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'twes-invoices-');
        if (false === $path) {
            throw new ServiceUnavailableHttpException(30, 'The file could not be prepared; try again shortly.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
            @unlink($path);
            throw new ServiceUnavailableHttpException(30, 'The file could not be prepared; try again shortly.');
        }
        $names = [];
        foreach ($printed as $invoice) {
            // Two series may share a number, as an invoice and a credit note of different establishments can.
            $name = $invoice->fileName;
            for ($copy = 2; isset($names[$name]); ++$copy) {
                $name = preg_replace('/\.pdf$/', '', $invoice->fileName).'-'.$copy.'.pdf';
            }
            $names[$name] = true;
            $zip->addFromString($name, $invoice->contents);
        }
        $zip->close();

        $response = new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => 'application/zip']);
        $response->deleteFileAfterSend();

        return $response;
    }

    public function auditActions(): array
    {
        return [
            'export.downloaded' => AuditScope::Company,
        ];
    }
}

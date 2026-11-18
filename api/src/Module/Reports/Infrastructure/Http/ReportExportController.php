<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\Http;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Reports\Application\InvalidReportRequest;
use App\Module\Reports\Application\ReportCatalogue;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportExportSettings;
use App\Module\Reports\Application\ReportResult;
use App\Module\Reports\Application\RunReport;
use App\Module\Reports\Infrastructure\ApiPlatform\Payload\ReportContexts;
use App\Module\Reports\Infrastructure\ApiPlatform\Payload\ReportRequest;
use App\Settings\Application\ReadSetting;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetWriter;
use App\Shared\Application\Spreadsheet\UnwritableSpreadsheet;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\Http\BulkFile;
use App\Shared\Infrastructure\Storage\ScratchFiles;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Application\ScreenWords;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A report as a file, read under the period, comparison and establishment its screen shows. A plain controller, as the
 * lists' files are: the answer is bytes, not JSON.
 *
 * The file is the report's own figures, as a spreadsheet reads them: a header of the columns' keys, each with the values
 * that make it say what it counts (`late_1(from=1,to=30)`), the figures of the period compared beside the period's
 * under `<key>.previous` when there is one, and the totals last. A company may ask for the words its screen shows
 * instead (`reports.export_headers`), in the language the screen speaks: each column's word, filled in with its values,
 * the period compared said after it in brackets, and the totals line named. Like a list's file it leaves the company, so it waits
 * for the person to have proved who they are in the last few minutes, and every file handed out is on record.
 */
#[AsController]
final readonly class ReportExportController implements BulkFile, DeclaresAuditActions
{
    /** The report screens' own texts, which word a file's headings when the company asks for words. */
    private const string TEXTS = 'screen:reports';

    public function __construct(
        private ReportContexts $contexts,
        private ReportCatalogue $catalogue,
        private RunReport $run,
        private SpreadsheetWriter $writer,
        private Security $security,
        private ConfirmStepUp $stepUp,
        private AuditTrail $audit,
        private ScratchFiles $scratch,
        private ReadSetting $settings,
        private ScreenWords $words,
    ) {
    }

    #[Route('/api/companies/{companyId}/reports/{key}/export.{format}', name: 'api_report_export', requirements: ['key' => '[a-z][a-z-]{1,39}', 'format' => 'csv|xlsx'], methods: ['GET'], defaults: ['_api_respond' => true])]
    public function __invoke(Request $request, string $companyId, string $key, string $format): Response
    {
        $asked = ReportRequest::ofQuery($request->query->all());
        $reading = $this->contexts->of(['companyId' => $companyId], $asked->establishment);
        $report = $this->catalogue->declarationOf($key, $reading) ?? throw new NotFoundHttpException('There is no report by that name.');
        // The file is the report its screen shows, under the same options, refused as the screen's address would be.
        $reading = $asked->withOptions($report, $reading, $this->catalogue->optionNames());

        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // The company guard has already refused anyone not signed in, so this is a contradiction, not a user error.
            throw new \LogicException('A report file is served behind the firewall.');
        }
        // Asked after the company and the report, so a stranger still meets the same 404 as for any company of others.
        if (!$this->stepUp->isRecent($account->getId())) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $this->run->run($report, $reading, $asked->periodFor($report, $reading), $asked->comparison);
        } catch (InvalidReportRequest $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        $spreadsheet = SpreadsheetFormat::from($format);
        // The writer's contract is a path, because a .xlsx is a zip archive and a zip has to be seekable.
        $path = $this->scratch->path('twes-report-');
        try {
            $this->writer->write($path, $spreadsheet, self::lines($result, $this->headings($reading->company, $request->getLocale())), self::textColumns($result));
        } catch (UnwritableSpreadsheet $failure) {
            @unlink($path);
            throw $this->scratch->unprepared($failure);
        }

        $company = $reading->company;
        $this->audit->record(new AuditEntry('export', $company->getId(), 'export.downloaded', $account->getId(), ['subject' => 'report:'.$key, 'format' => $format, 'filters' => array_keys($request->query->all())], $company->getId()));

        $response = new BinaryFileResponse($path, Response::HTTP_OK, [
            'Content-Type' => $spreadsheet->mediaType(),
            'Cache-Control' => 'private, no-store',
        ]);
        $days = $result->period->from == $result->period->to
            ? $result->period->from->format('Y-m-d')
            : $result->period->from->format('Y-m-d').'_'.$result->period->to->format('Y-m-d');
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('%s_%s.%s', $key, $days, $spreadsheet->extension()));
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * How the file names its columns and its totals line: by keys, or by the screen's words.
     *
     * @return array{\Closure(ReportColumn): string, \Closure(ReportColumn): string, string} a column, the same column of the
     *                                                                                       period compared, the totals line
     */
    private function headings(Company $company, string $language): array
    {
        if (!ReportExportSettings::inWords($this->settings, $company)) {
            return [self::heading(...), static fn (ReportColumn $column): string => self::heading($column).'.previous', 'total'];
        }
        $say = $this->words->of($company, $language, self::TEXTS);
        // The screen's own rule: the brackets of a series (late_1, late_2) share one word, their days telling them apart.
        $word = static fn (ReportColumn $column): string => $say('reports.columns.'.preg_replace('/_\d+$/', '', $column->key), $column->params) ?? self::heading($column);
        $previous = $say('reports.previous') ?? 'previous';

        return [$word, static fn (ReportColumn $column): string => $word($column).' ('.$previous.')', $say('reports.total') ?? 'total'];
    }

    /**
     * @param array{\Closure(ReportColumn): string, \Closure(ReportColumn): string, string} $named
     *
     * @return \Generator<int, list<string>> the header, every row, then the totals
     */
    private static function lines(ReportResult $result, array $named): \Generator
    {
        [$heading, $previousHeading, $totalLine] = $named;
        $compared = null !== $result->comparison;
        $counted = array_values(array_filter($result->columns, static fn (ReportColumn $column): bool => $column->kind->counts()));

        $header = array_map($heading, $result->columns);
        if ($compared) {
            array_push($header, ...array_map($previousHeading, $counted));
        }
        yield $header;

        foreach ($result->rows as ['row' => $row, 'previous' => $previous]) {
            $line = array_map(static fn (ReportColumn $column): string => self::cell($row->cells[$column->key] ?? null), $result->columns);
            if ($compared) {
                array_push($line, ...array_map(static fn (ReportColumn $column): string => self::cell($previous[$column->key] ?? null), $counted));
            }
            yield $line;
        }

        $totals = array_map(static fn (ReportColumn $column): string => self::cell($result->totals[$column->key] ?? null), $result->columns);
        // The first column names the line: it is a label or a day, which no total adds up.
        $totals[0] = $totalLine;
        if ($compared) {
            array_push($totals, ...array_map(static fn (ReportColumn $column): string => self::cell($result->previousTotals[$column->key] ?? null), $counted));
        }
        yield $totals;
    }

    /** @return list<int> the columns a spreadsheet keeps as written: names and references, never read as numbers */
    private static function textColumns(ReportResult $result): array
    {
        return array_keys(array_filter($result->columns, static fn (ReportColumn $column): bool => \in_array($column->kind, [ReportColumnKind::Label, ReportColumnKind::Choice], true)));
    }

    private static function heading(ReportColumn $column): string
    {
        if ([] === $column->params) {
            return $column->key;
        }

        return $column->key.'('.implode(',', array_map(static fn (string $name, int $value): string => $name.'='.$value, array_keys($column->params), $column->params)).')';
    }

    private static function cell(string|int|null $value): string
    {
        return null === $value ? '' : (string) $value;
    }

    public function auditActions(): array
    {
        return [
            'export.downloaded' => new Recorded('export', AuditScope::Company),
        ];
    }
}

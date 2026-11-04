<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Legal\Domain\Model\LegalText;
use App\Legal\Domain\Repository\LegalTextRepository;
use App\Legal\Domain\ValueObject\LegalLanguage;
use App\Legal\Domain\ValueObject\LegalPage;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\TypedText;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The platform operator's work on the legal pages (docs/SPEC.md § 8 row 148): a new version of a page in a language,
 * which is published at once and marked a draft; validating the latest version once someone checked it; and reading
 * what exists. A version is never changed or removed: a correction is a new version.
 */
final readonly class ManageLegalTexts implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'legal_text';
    public const string WRITTEN = 'legal_text.written';
    public const string VALIDATED = 'legal_text.validated';
    /** Far above any real legal page (the longest drafts are a few thousand words), low enough to refuse a mistake. */
    public const int MAX_LENGTH = 200_000;

    public function __construct(
        private LegalTextRepository $texts,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private LegalLanguages $languages,
        private LiveChanges $liveChanges,
    ) {
    }

    public function write(LegalPage $page, LegalLanguage $language, string $body, ?Uuid $actorUserId, string $actorEmail): LegalText
    {
        if ('' === TypedText::trimmed($body)) {
            throw new EmptyLegalText();
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new LegalTextTooLong(self::MAX_LENGTH);
        }

        return $this->transactions->run(function () use ($page, $language, $body, $actorUserId, $actorEmail): LegalText {
            $text = LegalText::draft($page, $language, $body, $actorEmail, $this->clock->now());
            $this->texts->add($text);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $text->getId(), self::WRITTEN, $actorUserId, self::about($page, $language)));
            $this->liveChanges->stage(self::moved(self::WRITTEN));

            return $text;
        });
    }

    /** Validates the latest version; one already validated stays as it was, with who validated it first. */
    public function validate(LegalPage $page, LegalLanguage $language, ?Uuid $actorUserId, string $actorEmail): LegalText
    {
        return $this->transactions->run(function () use ($page, $language, $actorUserId, $actorEmail): LegalText {
            $text = $this->texts->latest($page, $language) ?? throw new LegalTextNotWritten($page, $language);
            if ($text->isValidated()) {
                return $text;
            }
            $text->validate($actorEmail, $this->clock->now());
            $this->texts->save($text);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $text->getId(), self::VALIDATED, $actorUserId, self::about($page, $language)));
            $this->liveChanges->stage(self::moved(self::VALIDATED));

            return $text;
        });
    }

    /** @return list<LegalText> the page's versions in that language, the latest first */
    public function versions(LegalPage $page, LegalLanguage $language): array
    {
        return array_values(array_filter(
            $this->texts->all(),
            static fn (LegalText $text) => $text->getPage() === $page && $text->getLanguage()->equals($language),
        ));
    }

    /** @return list<LegalTextStatus> every page in every language, in declaration order, with its latest version or none */
    public function overview(): array
    {
        $latest = [];
        foreach ($this->texts->all() as $text) {
            $latest[$text->getPage()->value.'.'.$text->getLanguage()->value] ??= $text;
        }
        $overview = [];
        foreach (LegalPage::cases() as $page) {
            foreach ($this->languages->all() as $language) {
                $overview[] = new LegalTextStatus($page, $language, $latest[$page->value.'.'.$language->value] ?? null);
            }
        }

        return $overview;
    }

    /**
     * The legal pages are public, and every operator's « Pages légales » shows their versions: a change reaches every
     * signed-in screen by kind and action alone, as the platform's settings do, and a screen reads them again.
     */
    private static function moved(string $action): LiveChange
    {
        return LiveChange::everyone(self::ENTITY_TYPE, $action);
    }

    /** @return array{page: string, language: string} */
    private static function about(LegalPage $page, LegalLanguage $language): array
    {
        return ['page' => $page->value, 'language' => $language->value];
    }

    public function auditActions(): array
    {
        return [
            self::VALIDATED => new Recorded(self::ENTITY_TYPE, AuditScope::Outside),
            self::WRITTEN => new Recorded(self::ENTITY_TYPE, AuditScope::Outside),
        ];
    }
}

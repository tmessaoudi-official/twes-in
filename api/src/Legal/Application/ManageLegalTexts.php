<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;
use App\Legal\Domain\LegalTextRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The platform operator's work on the legal pages (docs/SPEC.md § 8 row 148): a new version of a page in a language,
 * which is published at once and marked a draft; validating the latest version once someone checked it; and reading
 * what exists. A version is never changed or removed: a correction is a new version.
 */
final readonly class ManageLegalTexts
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
    ) {
    }

    public function write(LegalPage $page, LegalLanguage $language, string $body, ?Uuid $actorUserId, string $actorEmail): LegalText
    {
        if ('' === trim($body)) {
            throw new EmptyLegalText();
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new LegalTextTooLong(self::MAX_LENGTH);
        }

        return $this->transactions->run(function () use ($page, $language, $body, $actorUserId, $actorEmail): LegalText {
            $text = LegalText::draft($page, $language, $body, $actorEmail, $this->clock->now());
            $this->texts->add($text);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $text->getId(), self::WRITTEN, $actorUserId, self::about($page, $language)));

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

            return $text;
        });
    }

    /** @return list<LegalText> the page's versions in that language, the latest first */
    public function versions(LegalPage $page, LegalLanguage $language): array
    {
        return array_values(array_filter(
            $this->texts->all(),
            static fn (LegalText $text) => $text->getPage() === $page && $text->getLanguage() === $language,
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
            foreach (LegalLanguage::cases() as $language) {
                $overview[] = new LegalTextStatus($page, $language, $latest[$page->value.'.'.$language->value] ?? null);
            }
        }

        return $overview;
    }

    /** @return array{page: string, language: string} */
    private static function about(LegalPage $page, LegalLanguage $language): array
    {
        return ['page' => $page->value, 'language' => $language->value];
    }
}

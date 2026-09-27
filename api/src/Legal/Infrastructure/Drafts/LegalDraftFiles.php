<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\Drafts;

use App\Legal\Application\LegalDraft;
use App\Legal\Application\LegalDrafts;
use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The drafts the platform ships, one Markdown file per page and language: `resources/legal/<page>.<language>.md`. A
 * file whose name names no page or language is refused rather than skipped, so a typo cannot drop a page quietly.
 */
final readonly class LegalDraftFiles implements LegalDrafts
{
    public function __construct(#[Autowire('%kernel.project_dir%/resources/legal')] private string $directory)
    {
    }

    public function all(): array
    {
        $drafts = [];
        foreach (glob($this->directory.'/*.md') ?: [] as $path) {
            $name = basename($path, '.md');
            [$slug, $code] = array_pad(explode('.', $name, 2), 2, '');
            $page = LegalPage::tryFrom($slug);
            $language = LegalLanguage::tryFrom($code);
            if (null === $page || null === $language) {
                throw new \UnexpectedValueException(\sprintf('%s names no legal page and language (<page>.<language>.md)', $path));
            }
            $drafts[] = new LegalDraft($page, $language, (string) file_get_contents($path));
        }

        return $drafts;
    }
}

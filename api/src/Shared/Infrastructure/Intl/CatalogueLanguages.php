<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Intl;

use App\Shared\Application\Languages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The language catalogue read from its JSON file once, on first use. A file that does not hold a usable catalogue
 * stops the application: speaking no language, or one nobody chose, is worse than not starting. It keeps only what
 * the file says, never anything a request brings, so one instance serves every request of a worker.
 */
final class CatalogueLanguages implements Languages
{
    /** A canonical tag: language in lower case, then a script in title case, then a region in capitals or digits. */
    private const string TAG = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$/';
    private const array PLURALS = ['zero', 'one', 'two', 'few', 'many', 'other'];
    private const array FLAGS = ['interface', 'documents', 'legal'];

    /** @var array{default: string, interface: list<string>, documents: list<string>, legal: list<string>}|null */
    private ?array $read = null;

    public function __construct(#[Autowire('%kernel.project_dir%/config/languages.json')] private readonly string $file)
    {
    }

    public function default(): string
    {
        return $this->catalogue()['default'];
    }

    public function interface(): array
    {
        return $this->catalogue()['interface'];
    }

    public function documents(): array
    {
        return $this->catalogue()['documents'];
    }

    public function legal(): array
    {
        return $this->catalogue()['legal'];
    }

    public function interfaceOr(?string $asked): string
    {
        return \in_array($asked, $this->interface(), true) ? $asked : $this->default();
    }

    /** @return array{default: string, interface: list<string>, documents: list<string>, legal: list<string>} */
    private function catalogue(): array
    {
        return $this->read ??= $this->parse();
    }

    /** @return array{default: string, interface: list<string>, documents: list<string>, legal: list<string>} */
    private function parse(): array
    {
        $contents = file_get_contents($this->file);
        try {
            $decoded = json_decode(false === $contents ? '' : $contents, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->refuse('is not JSON');
        }
        if (!\is_array($decoded) || !\is_array($decoded['languages'] ?? null) || [] === $decoded['languages']) {
            $this->refuse('names no language');
        }

        $by = ['interface' => [], 'documents' => [], 'legal' => []];
        $tags = [];
        foreach ($decoded['languages'] as $entry) {
            $tag = \is_array($entry) ? ($entry['tag'] ?? null) : null;
            if (!\is_string($tag) || 1 !== preg_match(self::TAG, $tag)) {
                $this->refuse(\sprintf('holds "%s", which is not a canonical BCP 47 tag', \is_string($tag) ? $tag : '?'));
            }
            if (\in_array($tag, $tags, true)) {
                $this->refuse("names \"$tag\" twice");
            }
            $tags[] = $tag;
            if (!\is_string($entry['name'] ?? null) || '' === $entry['name'] || !\is_string($entry['script'] ?? null)) {
                $this->refuse("gives \"$tag\" no name or script");
            }
            if (!\in_array($entry['direction'] ?? null, ['ltr', 'rtl'], true)) {
                $this->refuse("gives \"$tag\" a direction that is neither ltr nor rtl");
            }
            $plurals = $entry['plurals'] ?? null;
            if (!\is_array($plurals) || !\in_array('other', $plurals, true) || [] !== array_filter($plurals, static fn (mixed $form): bool => !\in_array($form, self::PLURALS, true))) {
                $this->refuse("gives \"$tag\" plural forms that are not CLDR's, or lack other");
            }
            foreach (self::FLAGS as $flag) {
                if (!\is_bool($entry[$flag] ?? null)) {
                    $this->refuse("gives \"$tag\" no true or false for $flag");
                }
                if (true === $entry[$flag]) {
                    $by[$flag][] = $tag;
                }
            }
        }

        $default = $decoded['default'] ?? null;
        if (!\is_string($default) || !\in_array($default, $by['interface'], true)) {
            $this->refuse(\sprintf('names the default "%s", which the interface does not speak', \is_string($default) ? $default : '?'));
        }
        $interface = [$default, ...array_values(array_diff($by['interface'], [$default]))];

        return ['default' => $default, 'interface' => $interface, 'documents' => $by['documents'], 'legal' => $by['legal']];
    }

    private function refuse(string $why): never
    {
        throw new \UnexpectedValueException("The language catalogue {$this->file} $why.");
    }
}

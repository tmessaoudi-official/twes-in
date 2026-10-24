<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Catalogue;

use App\Shared\Application\Languages;
use App\Texts\Application\ShippedTexts;
use App\Texts\Domain\ValueObject\TextAddress;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The shipped texts read from their files: the screens' copy the image carries (app.texts.screen_directory, the web's
 * `public/i18n` as built) and the API's `translations/<domain>.<language>.yaml`. The files are the release's, never
 * written while it runs, so each is read once per process and kept, whoever asks next.
 */
final class ShippedTextFiles implements ShippedTexts
{
    /** @var array<string, array<string, string>> each file's texts, by `<domain>|<language>` */
    private array $read = [];

    /** @var list<string>|null */
    private ?array $domains = null;

    public function __construct(
        private readonly Languages $languages,
        #[Autowire(param: 'app.texts.screen_directory')] private readonly string $screens,
        #[Autowire('%kernel.project_dir%/translations')] private readonly string $translations,
    ) {
    }

    public function original(TextAddress $address): ?string
    {
        return $this->texts($address->language, $address->domain)[$address->key] ?? null;
    }

    public function domains(): array
    {
        return $this->domains ??= $this->listed();
    }

    /** @return list<string> */
    private function listed(): array
    {
        // Missing, every override would be refused as naming no text: an image built without its copy says so instead.
        is_file($this->screens.'/'.$this->languages->default().'.json')
            || throw new \RuntimeException(\sprintf('No screen texts in %s: the image copies web/public/i18n there.', $this->screens));
        $parts = array_map(static fn (string $dir): string => TextAddress::SCREEN.':'.basename($dir), glob($this->screens.'/*', \GLOB_ONLYDIR) ?: []);
        $domains = array_map(static fn (string $file): string => explode('.', basename($file))[0], glob($this->translations.'/*.yaml') ?: []);
        sort($parts);
        $domains = array_values(array_unique($domains));
        sort($domains);

        return [TextAddress::SCREEN, ...$parts, ...$domains];
    }

    public function texts(string $language, string $domain): array
    {
        if (!\in_array($language, $this->languages->interface(), true) || !\in_array($domain, $this->domains(), true)) {
            return [];
        }

        return $this->read[$domain.'|'.$language] ??= self::flat($this->load($language, $domain));
    }

    private function load(string $language, string $domain): mixed
    {
        if (TextAddress::SCREEN === $domain || str_starts_with($domain, TextAddress::SCREEN.':')) {
            $part = substr($domain, \strlen(TextAddress::SCREEN) + 1);
            $file = $this->screens.('' === $part ? '' : '/'.$part).'/'.$language.'.json';

            return is_file($file) ? json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR) : [];
        }
        $file = $this->translations.'/'.$domain.'.'.$language.'.yaml';

        return is_file($file) ? Yaml::parseFile($file) : [];
    }

    /** @return array<string, string> */
    private static function flat(mixed $node, string $prefix = ''): array
    {
        if (\is_string($node)) {
            return '' === $prefix ? [] : [$prefix => $node];
        }
        if (!\is_array($node)) {
            return [];
        }
        $flat = [];
        foreach ($node as $key => $child) {
            $flat += self::flat($child, '' === $prefix ? (string) $key : $prefix.'.'.$key);
        }

        return $flat;
    }
}

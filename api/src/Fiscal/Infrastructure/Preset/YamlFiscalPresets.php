<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Preset;

use App\Fiscal\Application\Preset\FiscalPreset;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\UnknownFiscalPreset;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** The presets under config/fiscal, one `<CC>.yaml` per country, each validated the first time it is read. */
final class YamlFiscalPresets implements FiscalPresets
{
    private const string KEY = '/^[A-Z]{2}$/';

    /** @var array<string, FiscalPreset> */
    private array $read = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function keys(): array
    {
        $keys = [];
        foreach (glob($this->directory.'/*.yaml') ?: [] as $file) {
            $key = basename($file, '.yaml');
            if (1 === preg_match(self::KEY, $key)) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    public function has(string $key): bool
    {
        return 1 === preg_match(self::KEY, $key) && is_file($this->fileOf($key));
    }

    public function get(string $key): FiscalPreset
    {
        if (!$this->has($key)) {
            throw new UnknownFiscalPreset(\sprintf('No fiscal preset for "%s".', $key));
        }

        return $this->read[$key] ??= $this->load($key);
    }

    private function load(string $key): FiscalPreset
    {
        $file = $this->fileOf($key);
        try {
            $config = (new Processor())->processConfiguration(new FiscalPresetConfiguration(), [Yaml::parseFile($file)]);
        } catch (ParseException|InvalidConfigurationException $invalid) {
            throw new InvalidFiscalPreset(basename($file).': '.$invalid->getMessage(), 0, $invalid);
        }

        return (new PresetReader(basename($file), $key))->read($config);
    }

    private function fileOf(string $key): string
    {
        return $this->directory.'/'.$key.'.yaml';
    }
}

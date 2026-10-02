// SPDX-License-Identifier: AGPL-3.0-or-later

/** What a browser's User-Agent says of where a session runs, in the names a person uses; null where it says nothing. */
export interface DeviceLabel {
  readonly browser: string | null;
  readonly system: string | null;
}

// Order matters: Edge and Opera also say Chrome, and Chrome also says Safari.
const BROWSERS: readonly (readonly [RegExp, string])[] = [
  [/Edg(e|A|iOS)?\//, 'Edge'],
  [/OPR\/|Opera/, 'Opera'],
  [/Firefox\/|FxiOS\//, 'Firefox'],
  [/Chrome\/|CriOS\//, 'Chrome'],
  [/Safari\//, 'Safari'],
];

// Order matters: an iPhone says Mac OS X, and Android says Linux.
const SYSTEMS: readonly (readonly [RegExp, string])[] = [
  [/iPhone|iPad|iPod/, 'iOS'],
  [/Android/, 'Android'],
  [/Windows/, 'Windows'],
  [/Mac OS X|Macintosh/, 'macOS'],
  [/CrOS/, 'ChromeOS'],
  [/Linux|X11/, 'Linux'],
];

export function deviceLabel(userAgent: string): DeviceLabel {
  const find = (rules: readonly (readonly [RegExp, string])[]) =>
    rules.find(([pattern]) => pattern.test(userAgent))?.[1] ?? null;
  return { browser: find(BROWSERS), system: find(SYSTEMS) };
}

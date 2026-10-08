// SPDX-License-Identifier: AGPL-3.0-or-later
import { type Route } from '@angular/router';
import en from '../../public/i18n/en.json';
import fr from '../../public/i18n/fr.json';
import { routes } from './app.routes';

/** Every route that draws a page, with its full path: a layout route (the shell, the settings area) only frames them. */
function pages(list: readonly Route[], prefix = ''): { path: string; route: Route }[] {
  return list.flatMap((route) => {
    const path = [prefix, route.path ?? ''].filter((part) => part !== '').join('/');
    if (route.children !== undefined) return pages(route.children, path);
    return route.loadComponent !== undefined || route.component !== undefined
      ? [{ path: `/${path}`, route }]
      : [];
  });
}

function text(file: unknown, key: string): unknown {
  return key
    .split('.')
    .reduce<unknown>(
      (node, part) =>
        typeof node === 'object' && node !== null
          ? (node as Record<string, unknown>)[part]
          : undefined,
      file,
    );
}

describe('page titles', () => {
  const all = pages(routes);

  it('finds the pages', () => {
    expect(all.length).toBeGreaterThan(60);
  });

  it('gives every page a title, so no two tabs read the same', () => {
    expect(
      all.filter(({ route }) => typeof route.title !== 'string').map(({ path }) => path),
    ).toEqual([]);
  });

  it('writes every title out in French and English', () => {
    const untranslated = all
      .map(({ path, route }) => ({ path, key: route.title as string }))
      .filter(({ key }) => typeof text(fr, key) !== 'string' || typeof text(en, key) !== 'string');

    expect(untranslated).toEqual([]);
  });
});

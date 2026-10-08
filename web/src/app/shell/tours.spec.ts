// SPDX-License-Identifier: AGPL-3.0-or-later
import { type Route } from '@angular/router';
import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import { routes } from '../app.routes';
import { TOUR_ANCHORS } from '../shared/tour/tour-anchors';
import { TOURS } from './tours';

function paths(list: readonly Route[], prefix = ''): string[] {
  return list.flatMap((route) => {
    const path = [prefix, route.path ?? ''].filter((part) => part !== '').join('/');
    return route.children !== undefined ? paths(route.children, path) : [`/${path}`];
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

describe('the guided tours', () => {
  const keys = TOURS.flatMap((tour) => [
    tour.titleKey,
    tour.commandKey,
    ...tour.steps.flatMap((step) => [step.titleKey, step.bodyKey]),
  ]);

  it('declares at least the first invoice', () => {
    expect(TOURS.map((tour) => tour.key)).toContain('first-invoice');
  });

  it('points only at declared anchors, which the anchors gate keeps on screen', () => {
    const anchors: readonly string[] = TOUR_ANCHORS;
    const stray = TOURS.flatMap((tour) => tour.steps.map((step) => step.anchor)).filter(
      (anchor) => !anchors.includes(anchor),
    );
    expect(stray).toEqual([]);
  });

  it('opens only pages the app has', () => {
    const known = paths(routes);
    const stray = TOURS.flatMap((tour) => tour.steps.flatMap((step) => step.route ?? [])).filter(
      (route) => !known.includes(route),
    );
    expect(stray).toEqual([]);
  });

  it('writes every text out in French and English', () => {
    expect(
      keys.filter((key) => typeof text(fr, key) !== 'string' || typeof text(en, key) !== 'string'),
    ).toEqual([]);
  });

  it('gives every tour a unique key, which its palette line is named after', () => {
    expect(new Set(TOURS.map((tour) => tour.key)).size).toBe(TOURS.length);
  });
});

// SPDX-License-Identifier: AGPL-3.0-or-later

import { groupByDay, relativeTime } from './notification-days';

// Intl writes a narrow no-break space in some locales; compare words, not spacing.
const plain = (text: string) => text.replace(/\s/g, ' ');

describe('groupByDay', () => {
  const now = new Date('2026-09-14T09:00:00Z');
  const at = (id: string, createdAt: string) => ({ id, createdAt });

  it('groups newest-first entries under today, yesterday and each earlier day of the time zone', () => {
    const entries = [
      at('a', '2026-09-14T08:00:00+00:00'),
      at('b', '2026-09-13T23:30:00+00:00'),
      at('c', '2026-09-13T10:00:00+00:00'),
      at('d', '2026-09-12T10:00:00+00:00'),
      at('e', '2026-09-12T08:00:00+00:00'),
    ];

    const groups = groupByDay(entries, 'Africa/Tunis', now).map((group) => ({
      key: group.key,
      when: group.when,
      ids: group.entries.map((entry) => entry.id),
    }));

    expect(groups).toEqual([
      { key: '2026-09-14', when: 'today', ids: ['a', 'b'] },
      { key: '2026-09-13', when: 'yesterday', ids: ['c'] },
      { key: '2026-09-12', when: 'earlier', ids: ['d', 'e'] },
    ]);
  });

  it('reads today from the time zone too, not from the viewer', () => {
    const late = new Date('2026-09-13T23:30:00Z');

    expect(groupByDay([at('a', '2026-09-13T12:00:00+00:00')], 'Africa/Tunis', late)[0].when).toBe(
      'yesterday',
    );
    expect(groupByDay([at('a', '2026-09-13T12:00:00+00:00')], 'UTC', late)[0].when).toBe('today');
  });

  it('crosses a month end for yesterday', () => {
    const first = new Date('2026-10-01T09:00:00Z');

    expect(groupByDay([at('a', '2026-09-30T09:00:00+00:00')], 'UTC', first)[0].when).toBe(
      'yesterday',
    );
  });
});

describe('relativeTime', () => {
  const now = new Date('2026-09-14T09:00:00Z');
  const ago = (seconds: number) => new Date(now.getTime() - seconds * 1000).toISOString();

  it('says how long ago in the largest whole unit, in the locale', () => {
    expect(plain(relativeTime(ago(30), now, 'fr-TN'))).toBe('maintenant');
    expect(plain(relativeTime(ago(5 * 60), now, 'fr-TN'))).toBe('il y a 5 minutes');
    expect(plain(relativeTime(ago(3 * 3600 + 59), now, 'fr-TN'))).toBe('il y a 3 heures');
    expect(plain(relativeTime(ago(26 * 3600), now, 'fr-TN'))).toBe('hier');
    expect(plain(relativeTime(ago(10 * 86400), now, 'fr-TN'))).toBe('il y a 1 semaine');
    expect(plain(relativeTime(ago(65 * 86400), now, 'fr-TN'))).toBe('il y a 2 mois');
    expect(plain(relativeTime(ago(400 * 86400), now, 'fr-TN'))).toBe('il y a 1 an');
    expect(plain(relativeTime(ago(5 * 60), now, 'en'))).toBe('5 minutes ago');
  });

  it('shows a moment slightly ahead of this clock as now, and what is not a moment as it came', () => {
    expect(plain(relativeTime(new Date(now.getTime() + 20_000).toISOString(), now, 'fr-TN'))).toBe(
      'maintenant',
    );
    expect(relativeTime('yesterday-ish', now, 'fr-TN')).toBe('yesterday-ish');
  });
});

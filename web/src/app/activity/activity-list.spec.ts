// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import {
  ACTIVITY_ACTIONS,
  ACTIVITY_KINDS,
  actionKey,
  activitySearch,
  recordLink,
} from './activity-list';

const at = (tree: unknown, key: string): unknown =>
  key
    .split('.')
    .reduce<unknown>(
      (node, part) =>
        node !== null && typeof node === 'object' ? (node as Record<string, unknown>)[part] : null,
      tree,
    );

describe('the activity journal list', () => {
  it('says every action it names, in French and in English', () => {
    for (const action of ACTIVITY_ACTIONS) {
      for (const [language, tree] of [
        ['fr', fr],
        ['en', en],
      ] as const) {
        expect(typeof at(tree, `activity.actions.${action}`), `${language} ${action}`).toBe(
          'string',
        );
      }
    }
    for (const kind of ACTIVITY_KINDS) {
      expect(typeof at(fr, `activity.kinds.${kind}`), kind).toBe('string');
      expect(typeof at(en, `activity.kinds.${kind}`), kind).toBe('string');
    }
  });

  it('leaves an action it cannot say to be shown as recorded', () => {
    expect(actionKey('invoice.issued')).toBe('activity.actions.invoice.issued');
    expect(actionKey('invoice.something_new')).toBeNull();
  });

  it('opens the records that have a page, and no other', () => {
    expect(recordLink({ entityType: 'customer', entityId: 'c1' })).toEqual(['/customers', 'c1']);
    expect(recordLink({ entityType: 'delivery_note', entityId: 'd1' })).toEqual([
      '/delivery-notes',
      'd1',
    ]);
    expect(recordLink({ entityType: 'role', entityId: 'r1' })).toBeNull();
    expect(recordLink({ entityType: 'invoice', entityId: null })).toBeNull();
  });

  it('asks the API for the people, the kinds, the days and the order the list holds', () => {
    expect(
      activitySearch({
        query: 'facture',
        filters: {
          actor: '0192f0a0-0000-7000-8000-000000000001',
          kind: 'invoice,quote',
          'at.from': '2026-03-01',
        },
        sort: { column: 'at', direction: 'asc' },
        pageIndex: 1,
        pageSize: 100,
      }),
    ).toEqual({
      page: 2,
      itemsPerPage: 100,
      q: 'facture',
      actorIds: ['0192f0a0-0000-7000-8000-000000000001'],
      entityTypes: ['invoice', 'quote'],
      entityId: null,
      intervals: { 'at.from': '2026-03-01' },
      direction: 'asc',
    });
  });
});

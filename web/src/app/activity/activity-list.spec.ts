// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import {
  ACTIVITY_ACTIONS,
  ACTIVITY_KINDS,
  actionKey,
  activitySearch,
  fieldKey,
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

  /** The sweep read « number », « paymentId », « deliveryDate » on a French screen: the names as the API records them. */
  it('names what changed in the words of the record’s own form, then of the journal, else as recorded', () => {
    const has = (key: string) => typeof at(fr, key) === 'string';
    expect(fieldKey('customer', 'isActive', has)).toBe('customers.fields.isActive');
    expect(fieldKey('delivery_note', 'deliveryDate', has)).toBe(
      'delivery_notes.fields.deliveryDate',
    );
    expect(fieldKey('invoice', 'paymentId', has)).toBe('activity.field_names.paymentId');
    expect(fieldKey('quote', 'answeredOn', has)).toBe('quotes.fields.answeredOn');
    expect(fieldKey('teleport', 'flux', has)).toBe('flux');
  });

  it('says every field name of its own in both languages', () => {
    const names = Object.keys((at(fr, 'activity.field_names') ?? {}) as object);
    expect(names.length).toBeGreaterThan(10);
    expect(Object.keys((at(en, 'activity.field_names') ?? {}) as object)).toEqual(names);
  });
});

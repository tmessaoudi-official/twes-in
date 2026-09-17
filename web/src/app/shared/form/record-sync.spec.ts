// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormControl, FormGroup } from '@angular/forms';
import type { DescriptorFormGroup } from './form-builder';
import type { FieldValue } from './form-types';
import { RecordSync } from './record-sync';

function group(values: Record<string, FieldValue>): DescriptorFormGroup {
  return new FormGroup(
    Object.fromEntries(Object.entries(values).map(([id, value]) => [id, new FormControl(value)])),
  );
}

const nadia = { id: 'u2', name: 'Nadia' };

describe('RecordSync', () => {
  const saved = { name: 'Durand', phone: '0612', city: 'Tunis' };

  it('on a quiet form, takes the new version, highlights what changed, and says nothing needs deciding', () => {
    const form = group(saved);
    const sync = new RecordSync();
    sync.track(saved);

    const outcome = sync.receive(form, { ...saved, city: 'Sfax' }, nadia);

    expect(outcome).toBe('updated');
    expect(form.controls['city'].value).toBe('Sfax');
    expect([...sync.updated()]).toEqual(['city']);
    expect(sync.conflicts()).toEqual([]);
    expect(sync.changedBy()).toBeNull();
  });

  it('while typing, keeps the typed fields, names who changed the record, and lists the conflicts', () => {
    const form = group(saved);
    const sync = new RecordSync();
    sync.track(saved);
    form.controls['phone'].setValue('0613');

    const outcome = sync.receive(form, { ...saved, phone: '0698', city: 'Sfax' }, nadia);

    expect(outcome).toBe('editing');
    expect(sync.changedBy()).toEqual(nadia);
    expect(sync.conflicts()).toEqual([{ field: 'phone', mine: '0613', theirs: '0698' }]);
    expect(form.controls['city'].value).toBe('Sfax');
  });

  it('merges a second change against the version merged last, not the one the form opened with', () => {
    const form = group(saved);
    const sync = new RecordSync();
    sync.track(saved);
    sync.receive(form, { ...saved, city: 'Sfax' }, nadia);

    sync.receive(form, { ...saved, city: 'Sousse' }, nadia);

    expect(form.controls['city'].value).toBe('Sousse');
  });

  it('takes their value for one conflict, or keeps mine, and forgets that conflict either way', () => {
    const form = group(saved);
    const sync = new RecordSync();
    sync.track(saved);
    form.controls['phone'].setValue('0613');
    form.controls['name'].setValue('Durand & fils');
    sync.receive(form, { ...saved, phone: '0698', name: 'Durand SARL' }, nadia);

    sync.takeTheirs(form, 'phone');
    sync.keepMine('name');

    expect(form.controls['phone'].value).toBe('0698');
    expect(form.controls['name'].value).toBe('Durand & fils');
    expect(sync.conflicts()).toEqual([]);
  });

  it('reloads the whole saved version on request, dropping what was typed', () => {
    const form = group(saved);
    const sync = new RecordSync();
    sync.track(saved);
    form.controls['phone'].setValue('0613');
    form.markAsDirty();
    sync.receive(form, { ...saved, phone: '0698' }, nadia);

    sync.reloadSaved(form);

    expect(form.getRawValue()).toEqual({ ...saved, phone: '0698' });
    expect(form.dirty).toBe(false);
    expect(sync.changedBy()).toBeNull();
    expect(sync.conflicts()).toEqual([]);
  });

  it('starts clean from what this tab saved itself', () => {
    const form = group(saved);
    const sync = new RecordSync();
    sync.track(saved);
    form.controls['phone'].setValue('0613');
    sync.receive(form, { ...saved, phone: '0698' }, nadia);

    sync.track({ ...saved, phone: '0613' });

    expect(sync.changedBy()).toBeNull();
    expect(sync.conflicts()).toEqual([]);
    expect(sync.updated().size).toBe(0);
  });

  it('says nothing changed when the saved version shows the same, typing or not', () => {
    const form = group(saved);
    const sync = new RecordSync();
    sync.track(saved);
    form.controls['phone'].setValue('0613');

    const outcome = sync.receive(form, { ...saved }, nadia);

    expect(outcome).toBe('unchanged');
    expect(sync.changedBy()).toBeNull();
    expect(form.controls['phone'].value).toBe('0613');
  });
});

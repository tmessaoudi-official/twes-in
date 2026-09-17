// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormControl, FormGroup } from '@angular/forms';
import type { DescriptorFormGroup } from './form-builder';
import { mergeSavedVersion } from './form-merge';
import type { FieldValue } from './form-types';

function group(values: Record<string, FieldValue>): DescriptorFormGroup {
  return new FormGroup(
    Object.fromEntries(Object.entries(values).map(([id, value]) => [id, new FormControl(value)])),
  );
}

describe('mergeSavedVersion', () => {
  const base = { name: 'Durand', phone: '0612', email: 'a@durand.test', city: 'Tunis' };

  it('takes the new saved value of every field nobody here touched, and names them', () => {
    const form = group(base);

    const merge = mergeSavedVersion(form, base, { ...base, name: 'Durand SARL', city: 'Sfax' });

    expect(form.getRawValue()).toEqual({ ...base, name: 'Durand SARL', city: 'Sfax' });
    expect(merge.updated).toEqual(['name', 'city']);
    expect(merge.conflicts).toEqual([]);
  });

  it('keeps what is being typed, and says so only where the other person changed that same field', () => {
    const form = group(base);
    form.controls['phone'].setValue('0613');
    form.controls['email'].setValue('b@durand.test');

    const merge = mergeSavedVersion(form, base, { ...base, phone: '0698', city: 'Sfax' });

    expect(form.getRawValue()).toEqual({
      ...base,
      phone: '0613',
      email: 'b@durand.test',
      city: 'Sfax',
    });
    expect(merge.updated).toEqual(['city']);
    expect(merge.conflicts).toEqual([{ field: 'phone', mine: '0613', theirs: '0698' }]);
  });

  it('is no conflict when both typed the same value', () => {
    const form = group(base);
    form.controls['phone'].setValue('0698');

    const merge = mergeSavedVersion(form, base, { ...base, phone: '0698' });

    expect(merge.conflicts).toEqual([]);
    expect(merge.updated).toEqual([]);
  });

  it('counts a field typed back to its saved value as untouched', () => {
    const form = group(base);
    form.controls['name'].setValue('Durand X');
    form.controls['name'].setValue('Durand');

    const merge = mergeSavedVersion(form, base, { ...base, name: 'Durand SARL' });

    expect(form.controls['name'].value).toBe('Durand SARL');
    expect(merge.updated).toEqual(['name']);
  });

  it('keeps a field the new version no longer has, and ignores one the form does not show', () => {
    const form = group(base);

    const merge = mergeSavedVersion(form, base, { name: 'Durand', phone: '0612', extra: 'x' });

    expect(form.getRawValue()).toEqual(base);
    expect(merge).toEqual({ updated: [], conflicts: [] });
  });

  it('updates quietly: the form stays pristine where it was, and nothing it merged counts as typed', () => {
    const form = group(base);
    form.controls['phone'].setValue('0613');
    form.controls['phone'].markAsDirty();

    mergeSavedVersion(form, base, { ...base, name: 'Durand SARL' });

    expect(form.controls['name'].dirty).toBe(false);
    expect(form.controls['phone'].dirty).toBe(true);
  });
});

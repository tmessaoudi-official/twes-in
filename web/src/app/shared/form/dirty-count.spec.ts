// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormArray, FormControl, FormGroup } from '@angular/forms';
import { dirtyCount, revertToSaved } from './dirty-count';

const SAVED = {
  name: 'Carthage SARL',
  email: null,
  terms: 30,
  lines: [{ label: 'Vis' }, { label: 'Écrou' }],
};

function customer(): FormGroup {
  return new FormGroup({
    name: new FormControl('Carthage SARL', { nonNullable: true }),
    email: new FormControl<string | null>(null),
    terms: new FormControl(30),
    lines: new FormArray([
      new FormGroup({ label: new FormControl('Vis', { nonNullable: true }) }),
      new FormGroup({ label: new FormControl('Écrou', { nonNullable: true }) }),
    ]),
  });
}

describe('dirtyCount', () => {
  it('counts nothing on a form nobody has changed', () => {
    expect(dirtyCount(customer().getRawValue(), SAVED)).toBe(0);
  });

  it('counts each field holding something other than what was saved', () => {
    const form = customer();
    form.controls['name']!.setValue('Carthage SA');
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(1);

    form.controls['terms']!.setValue(45);
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(2);
  });

  it('counts nothing again once a field is typed back to what it was', () => {
    // Angular's own `dirty` says a control was TOUCHED, which is a different question: typing a character and
    // deleting it again changes nothing, and the count is what the bar shows beside the save.
    const form = customer();
    form.controls['name']!.setValue('Carthage SA');
    form.controls['name']!.setValue('Carthage SARL');

    expect(form.dirty).toBe(false);
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(0);
  });

  it('reads an emptied field as the same answer as one that was never filled in', () => {
    const form = customer();
    form.controls['email']!.setValue('');
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(0);

    form.controls['email']!.setValue('contact@carthage.tn');
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(1);
  });

  it('counts a change anywhere inside a list of rows', () => {
    const form = customer();
    const lines = form.controls['lines'] as FormArray;
    (lines.at(1) as FormGroup).controls['label']!.setValue('Boulon');

    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(1);
  });

  it('counts a row added or removed, which an equal-length walk would miss', () => {
    const form = customer();
    const lines = form.controls['lines'] as FormArray;
    lines.push(new FormGroup({ label: new FormControl('Boulon', { nonNullable: true }) }));
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(1);

    lines.removeAt(2);
    lines.removeAt(1);
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(1);
  });

  it('counts against what was last SAVED, which a save moves on', () => {
    const form = customer();
    form.controls['name']!.setValue('Carthage SA');
    // What a save does: the answer the API kept becomes the new baseline.
    const saved = { ...SAVED, name: 'Carthage SA' };

    expect(dirtyCount(form.getRawValue(), saved)).toBe(0);
    form.controls['name']!.setValue('Carthage SARL');
    expect(dirtyCount(form.getRawValue(), saved)).toBe(1);
  });

  it('does not count a field the saved values never mentioned but the form leaves empty', () => {
    // A form can carry more fields than the API answered with; an empty one of those is not a change.
    const form = customer();
    expect(dirtyCount({ ...form.getRawValue(), notes: '' }, SAVED)).toBe(0);
    expect(dirtyCount({ ...form.getRawValue(), notes: 'À rappeler' }, SAVED)).toBe(1);
  });

  it('puts every field back to what was last saved', () => {
    const form = customer();
    form.controls['name']!.setValue('Carthage SA');
    form.controls['terms']!.setValue(45);
    form.markAsTouched();

    revertToSaved(form, SAVED);

    expect(form.controls['name']!.value).toBe('Carthage SARL');
    expect(form.controls['terms']!.value).toBe(30);
    expect(dirtyCount(form.getRawValue(), SAVED)).toBe(0);
    expect(form.touched).toBe(false);
  });
});

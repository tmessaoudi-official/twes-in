// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingValuesInUse } from '../shared/settings/settings-types';
import { NameLanguages } from './name-languages';

function languagesAnswering(answer: SettingValuesInUse | Error) {
  const valuesInUse = vi.fn(async () => {
    if (answer instanceof Error) throw answer;
    return answer;
  });
  TestBed.configureTestingModule({
    providers: [{ provide: SettingsApi, useValue: { valuesInUse } }],
  });
  return { languages: TestBed.runInInjectionContext(() => new NameLanguages()), valuesInUse };
}

describe('NameLanguages, the languages a unit or tax name form shows', () => {
  it('shows the documents language and those the customers print in, the rest withheld', async () => {
    const { languages, valuesInUse } = languagesAnswering({ company: 'fr', parties: ['ar'] });

    await languages.load('c1');
    languages.open({});

    expect(valuesInUse).toHaveBeenCalledWith('c1', 'document.language');
    expect([...languages.withheld()]).toEqual(['name-en']);
    expect([...languages.removable()]).toEqual(['name-ar']);
    expect(languages.notes()).toEqual({ 'name-fr': 'fiscal.names.documents_language' });
    expect(languages.addable().map((option) => option.value)).toEqual(['en']);
  });

  it('shows a language the record already names, removable like any but the documents one', async () => {
    const { languages } = languagesAnswering({ company: 'ar', parties: null });

    await languages.load('c1');
    languages.open({ en: 'Piece', fr: '' });

    expect([...languages.withheld()]).toEqual(['name-fr']);
    expect([...languages.removable()]).toEqual(['name-en']);
  });

  it('adds a language asked for, and withholds one removed until it is asked for again', async () => {
    const { languages } = languagesAnswering({ company: 'fr', parties: ['ar'] });
    await languages.load('c1');
    languages.open({});

    languages.add('en');
    expect([...languages.withheld()]).toEqual([]);
    expect(languages.addable()).toEqual([]);

    languages.remove('name-ar');
    expect([...languages.withheld()]).toEqual(['name-ar']);
    languages.remove('name-fr');
    expect([...languages.withheld()]).toEqual(['name-ar']);

    languages.open({});
    expect([...languages.withheld()]).toEqual(['name-en']);
  });

  it('withholds nothing while it cannot tell which languages are in use', async () => {
    const { languages } = languagesAnswering(new SettingsRefused('network'));

    await languages.load('c1');
    languages.open({});

    expect([...languages.withheld()]).toEqual([]);
    expect([...languages.removable()]).toEqual([]);
    expect(languages.notes()).toEqual({});
    expect(languages.addable()).toEqual([]);
  });

  it('reads only languages documents print in from what the API says', async () => {
    const { languages } = languagesAnswering({ company: 'xx', parties: ['ar', 3, 'de'] });

    await languages.load('c1');
    languages.open({});

    expect([...languages.withheld()]).toEqual([]);
  });
});

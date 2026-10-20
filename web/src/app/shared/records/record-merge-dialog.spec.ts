// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import {
  provideTranslateCompiler,
  provideTranslateService,
  TranslateService,
} from '@ngx-translate/core';
import { PluralCompiler } from '../i18n/plural-compiler';
import { RecordMergeDialog, type RecordMergeDialogData } from './record-merge-dialog';

/** The French texts the dialog says; the parity spec holds the shipped files to these keys. */
const FR = {
  records: {
    merge: {
      title: 'Fusionner {{what}}',
      keep: 'La fiche gardée',
      goes: '« {{name}} » disparaît ; ce qui nomme cette fiche passe dans la fiche gardée : {{moves}}.',
      goes_empty: '« {{name}} » disparaît ; rien ne nomme cette fiche.',
      settings: 'Les réglages par défaut passent aussi.',
      undo_hint: 'La fusion peut être annulée pendant 24 heures.',
      cancel: 'Ne rien fusionner',
      confirm: 'Fusionner',
    },
    tables: {
      product: '{{count}} {count, plural, one {produit} other {produits}}',
      product_category: '{{count}} {count, plural, one {sous-catégorie} other {sous-catégories}}',
    },
    recent: {
      subjects: {
        product_category:
          '{{count}} {count, plural, one {catégorie d’articles} other {catégories d’articles}}',
      },
    },
  },
};

const DATA: RecordMergeDialogData = {
  subject: 'product_category',
  candidates: [
    { id: 'c1', label: 'Visserie', moves: [{ table: 'product', count: 3 }] },
    {
      id: 'c2',
      label: 'Vis',
      moves: [
        { table: 'product', count: 2 },
        { table: 'product_category', count: 1 },
      ],
    },
    { id: 'c3', label: 'Clous', moves: [{ table: 'product', count: 0 }] },
  ],
  settings: true,
};

describe('RecordMergeDialog', () => {
  const close = vi.fn();
  let root: HTMLElement;
  let detect: () => void;

  beforeEach(() => {
    close.mockReset();
    TestBed.configureTestingModule({
      imports: [RecordMergeDialog],
      providers: [
        provideTranslateService({ fallbackLang: 'fr' }),
        provideTranslateCompiler(PluralCompiler),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        { provide: MAT_DIALOG_DATA, useValue: DATA },
      ],
    });
    const translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', FR);
    translate.use('fr');
    const fixture = TestBed.createComponent(RecordMergeDialog);
    fixture.detectChanges();
    root = fixture.nativeElement as HTMLElement;
    detect = () => fixture.detectChanges();
  });

  const preview = (): string[] =>
    [...root.querySelectorAll('[data-testid="record-merge-preview"] li')].map(
      (line) => line.textContent?.trim() ?? '',
    );

  const click = (testId: string): void => {
    root.querySelector<HTMLElement>(`[data-testid="${testId}"]`)?.click();
    detect();
  };

  it('names the records in its title and keeps the first ticked unless told otherwise', () => {
    expect(root.querySelector('[data-testid="record-merge-title"]')?.textContent).toContain(
      'Fusionner 3 catégories d’articles',
    );
    expect(root.querySelector('[data-testid="record-merge-keep-c1"] input')).toHaveProperty(
      'checked',
      true,
    );
    click('record-merge-confirm');
    expect(close).toHaveBeenCalledWith({ id: 'c1', label: 'Visserie' });
  });

  it('says what happens to each record but the one kept, before anything runs', () => {
    expect(preview()).toEqual([
      '« Vis » disparaît ; ce qui nomme cette fiche passe dans la fiche gardée : 2 produits et 1 sous-catégorie.',
      '« Clous » disparaît ; rien ne nomme cette fiche.',
    ]);
    expect(root.textContent).toContain('Les réglages par défaut passent aussi.');
    expect(root.textContent).toContain('La fusion peut être annulée pendant 24 heures.');
  });

  it('follows the record picked to keep, and answers it', () => {
    root.querySelector<HTMLInputElement>('[data-testid="record-merge-keep-c2"] input')?.click();
    detect();

    expect(preview()[0]).toBe(
      '« Visserie » disparaît ; ce qui nomme cette fiche passe dans la fiche gardée : 3 produits.',
    );
    click('record-merge-confirm');
    expect(close).toHaveBeenCalledWith({ id: 'c2', label: 'Vis' });
  });

  it('merges nothing when the person changes their mind', () => {
    click('record-merge-cancel');
    expect(close).toHaveBeenCalledWith(null);
  });
});

describe('RecordMergeDialog, records merged away as aliases', () => {
  const VENDORS: RecordMergeDialogData = {
    subject: 'vendor',
    candidates: [
      { id: 'v1', label: 'F-001 Sotumag', moves: [], stays: [] },
      {
        id: 'v2',
        label: 'F-002 Sotumag Sfax',
        moves: [{ table: 'purchase_order', count: 1 }],
        stays: [
          { table: 'expense', count: 3 },
          { table: 'stock_movement', count: 0 },
        ],
      },
      {
        id: 'v3',
        label: 'F-003 Sotumag Gabès',
        moves: [],
        stays: [],
      },
      {
        // A table the API counts before any screen words it, read as other records rather than as its key.
        id: 'v4',
        label: 'F-004 Sotumag Sousse',
        moves: [
          { table: 'purchase_order', count: 1 },
          { table: 'vendor_unworded', count: 2 },
        ],
        stays: [],
      },
    ],
    settings: false,
    aliased: true,
  };
  const ALIAS_FR = {
    records: {
      ...FR.records,
      merge: {
        ...FR.records.merge,
        alias:
          '« {{name}} » reste, désactivée, et renvoie vers la fiche gardée. Ce qui passe dans la fiche gardée : {{moves}}.',
        alias_empty:
          '« {{name}} » reste, désactivée, et renvoie vers la fiche gardée. Rien ne passe dans la fiche gardée.',
        alias_stays: 'Ce qui reste au nom de « {{name}} » : {{stays}}.',
        undo_hint_alias: 'Annulable 24 heures, tant que rien n’est reçu au nom de la fiche gardée.',
      },
      tables: {
        purchase_order: '{{count}} {count, plural, one {commande} other {commandes}}',
        expense: '{{count}} {count, plural, one {dépense} other {dépenses}}',
        stock_movement: '{{count}} {count, plural, one {mouvement} other {mouvements}}',
        other: '{{count}} {count, plural, one {autre fiche} other {autres fiches}}',
      },
      recent: {
        subjects: { vendor: '{{count}} {count, plural, one {fournisseur} other {fournisseurs}}' },
      },
    },
  };
  let root: HTMLElement;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [RecordMergeDialog],
      providers: [
        provideTranslateService({ fallbackLang: 'fr' }),
        provideTranslateCompiler(PluralCompiler),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close: vi.fn() } },
        { provide: MAT_DIALOG_DATA, useValue: VENDORS },
      ],
    });
    const translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', ALIAS_FR);
    translate.use('fr');
    const fixture = TestBed.createComponent(RecordMergeDialog);
    fixture.detectChanges();
    root = fixture.nativeElement as HTMLElement;
  });

  it('says each record stays, switched off and pointing at the one kept, with what moves and what stays', () => {
    expect(
      [...root.querySelectorAll('[data-testid="record-merge-preview"] li')].map(
        (line) => line.textContent?.trim() ?? '',
      ),
    ).toEqual([
      '« F-002 Sotumag Sfax » reste, désactivée, et renvoie vers la fiche gardée. Ce qui passe dans la fiche gardée : 1 commande. Ce qui reste au nom de « F-002 Sotumag Sfax » : 3 dépenses.',
      '« F-003 Sotumag Gabès » reste, désactivée, et renvoie vers la fiche gardée. Rien ne passe dans la fiche gardée.',
      '« F-004 Sotumag Sousse » reste, désactivée, et renvoie vers la fiche gardée. Ce qui passe dans la fiche gardée : 1 commande et 2 autres fiches.',
    ]);
    expect(root.textContent).toContain(
      'Annulable 24 heures, tant que rien n’est reçu au nom de la fiche gardée.',
    );
    expect(root.textContent).not.toContain('disparaît');
  });
});

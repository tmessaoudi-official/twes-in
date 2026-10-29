// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, input, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { Session } from '../shared/session/session';
import type { RolesError } from './roles-api';
import { RolesFacade } from './roles-facade';
import { RolesPage } from './roles-page';
import { ArticleDefaults } from '../products/article-defaults';
import type { ArticleSubject } from '../shared/settings/settings-types';

/** Stands in for the defaults card, which reads the settings engine: the page only decides whom it is for. */
@Component({
  selector: 'app-article-defaults',
  template: '<p data-testid="role-defaults" [attr.data-subject]="said()"></p>',
})
class DefaultsStub {
  readonly subject = input.required<ArticleSubject>();
  protected readonly said = () => JSON.stringify(this.subject());
}
import type { PermissionGroupRow, RoleHolders, RoleRow } from './roles-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      modules: { inventory: 'Stock' },
      roles: { owner: 'propriétaire', member: 'membre' },
      permissions: {
        groups: { members: 'Équipe' },
        user: { read: 'Voir les membres', write: 'Inviter et retirer des membres' },
        stock: { read: 'Voir le stock' },
      },
      company: {
        roles: {
          title: 'Rôles',
          built_in: 'fourni',
          member_count: '{{count}} membre(s)',
          view: 'Consulter',
          edit: 'Modifier',
          new: 'Créer un rôle',
          delete: 'Supprimer le rôle',
          delete_title: 'Supprimer ce rôle ?',
          delete_message: 'Le rôle « {{name}} » disparaît, avec la liste de ce qu’il permet.',
          keep: 'Garder le rôle',
          deleted: 'Rôle supprimé.',
          changed_since: 'Le rôle « {{name}} » a été modifié entre-temps : rien n’a été supprimé.',
          everything: 'Le propriétaire a tous les droits',
          errors: {
            in_use: 'Des membres ont encore ce rôle.',
            built_in: 'Ce rôle est fourni avec le produit.',
            in_use_holders: 'Le rôle « {{role}} » est encore celui de : {{holders}}.',
            in_use_holders_more:
              'Le rôle « {{role}} » est encore celui de : {{holders}} et d’autres.',
          },
        },
      },
    });
  }
}

const owner: RoleRow = {
  id: 'r-owner',
  name: 'owner',
  builtIn: true,
  wildcard: true,
  permissions: [],
  memberCount: 2,
  version: 1,
};
const member: RoleRow = {
  id: 'r-member',
  name: 'member',
  builtIn: true,
  wildcard: false,
  permissions: ['user.read'],
  memberCount: 1,
  version: 1,
};
const barista: RoleRow = {
  id: 'r-barista',
  name: 'barista',
  builtIn: false,
  wildcard: false,
  permissions: ['stock.read'],
  memberCount: 0,
  version: 1,
};

const groups: readonly PermissionGroupRow[] = [
  {
    key: 'members',
    labelKey: 'permissions.groups.members',
    permissions: ['user.read', 'user.write'],
  },
  { key: 'inventory', labelKey: 'modules.inventory', permissions: ['stock.read'] },
];

describe('RolesPage', () => {
  const roles = signal<readonly RoleRow[]>([owner, member, barista]);
  const error = signal<RolesError | null>(null);
  const held = signal<RoleHolders | null>(null);
  const facade = {
    roles: roles.asReadonly(),
    groups: signal(groups).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    held: held.asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    create: vi.fn(),
    revise: vi.fn(),
    remove: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
    hasModule: vi.fn(),
  };
  let fixture: ComponentFixture<RolesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const tickOf = (permission: string): HTMLInputElement =>
    q(`permission-${permission}`)!.querySelector('input[type="checkbox"]') as HTMLInputElement;

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(RolesPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    roles.set([owner, member, barista]);
    error.set(null);
    held.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.create.mockReset().mockResolvedValue(true);
    facade.revise.mockReset().mockResolvedValue(true);
    facade.remove.mockReset().mockResolvedValue(true);
    facade.clearError.mockReset();
    auth.hasPermission.mockReset().mockReturnValue(true);
    auth.hasModule.mockReset().mockReturnValue(false);
    TestBed.configureTestingModule({
      imports: [RolesPage],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: RolesFacade, useValue: facade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        provideQuietFeedback(),
      ],
    });
  });

  it('lists every role, says which are shipped and how many hold each', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1');
    expect(q('role-owner')?.textContent).toContain('fourni');
    // A shipped role is named in the screen's language, never by its key (audit 2026-10-06, V-18).
    expect(q('role-name-owner')?.textContent?.trim()).toBe('Propriétaire');
    expect(q('role-name-member')?.textContent?.trim()).toBe('Membre');
    expect(q('role-name-barista')?.textContent?.trim()).toBe('barista');
    expect(q('role-owner')?.textContent).toContain('2 membre(s)');
    expect(q('role-barista')?.textContent).not.toContain('fourni');
    expect(q('role-barista')?.textContent).toContain('0 membre(s)');
  });

  // What a role decides at a place is a setting, not a permission: a shipped role has its own values too.
  it('offers the open role its own defaults, to whoever sets the company’s, where the stock is kept', async () => {
    auth.hasModule.mockImplementation((key: string) => key === 'inventory');
    TestBed.overrideComponent(RolesPage, {
      remove: { imports: [ArticleDefaults] },
      add: { imports: [DefaultsStub] },
    });
    await open();
    q('role-new')!.click();
    await settle();
    expect(q('role-defaults')).toBeNull();

    q('role-open-member')!.click();
    await settle();
    expect(q('role-defaults')?.getAttribute('data-subject')).toBe('{"roleId":"r-member"}');
  });

  it.each([
    ['without the company’s settings', 'inventory', false],
    ['without the stock', 'invoices', true],
  ])('offers no role defaults %s', async (_case, module, settings) => {
    auth.hasModule.mockImplementation((key: string) => key === module);
    auth.hasPermission.mockImplementation((permission: string) =>
      permission === 'company.settings' ? settings : true,
    );
    TestBed.overrideComponent(RolesPage, {
      remove: { imports: [ArticleDefaults] },
      add: { imports: [DefaultsStub] },
    });
    await open();
    q('role-open-member')!.click();
    await settle();

    expect(q('role-editor')).not.toBeNull();
    expect(q('role-defaults')).toBeNull();
  });

  it('offers editing and deleting for a custom role only', async () => {
    await open();

    expect(q('role-open-barista')?.textContent).toContain('Modifier');
    expect(q('role-delete-barista')).not.toBeNull();

    // A shipped role is readable, never editable: the release defines it and every company reads the same one.
    expect(q('role-open-member')?.textContent).toContain('Consulter');
    expect(q('role-delete-member')).toBeNull();
    expect(q('role-delete-owner')).toBeNull();
  });

  /** Answers the open question; the last dialog, since one just closed can still be in the page for a frame. */
  async function answer(choice: 'run' | 'keep'): Promise<void> {
    const buttons = document.querySelectorAll(`[data-testid="confirm-${choice}"]`);
    (buttons[buttons.length - 1] as HTMLElement).click();
    await settle();
  }

  it('asks before it deletes a role, naming it, as final, and deletes it on the version it read', async () => {
    await open();

    q('role-delete-barista')!.click();
    await settle();
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Le rôle « barista » disparaît',
    );
    expect(document.querySelector('[data-testid="confirm-kind"]')?.getAttribute('data-kind')).toBe(
      'definitif',
    );
    await answer('keep');
    expect(facade.remove).not.toHaveBeenCalled();

    q('role-delete-barista')!.click();
    await settle();
    await answer('run');
    await vi.waitFor(() => expect(facade.remove).toHaveBeenCalledWith('c1', 'r-barista', 1));
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({ key: 'company.roles.deleted', effect: 'definitif' }),
      ]),
    );
  });

  it('keeps a role revised since the list was read, says so and reads the list again', async () => {
    facade.remove.mockImplementation(async () => {
      error.set('record_changed');
      return false;
    });
    await open();
    facade.load.mockClear();

    q('role-delete-barista')!.click();
    await settle();
    await answer('run');

    await vi.waitFor(() => expect(facade.load).toHaveBeenCalledWith('c1'));
    expect(facade.clearError).toHaveBeenCalled();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      expect.objectContaining({
        kind: 'notice',
        key: 'company.roles.changed_since',
        params: { name: 'barista' },
      }),
    ]);
  });

  it('draws the matrix grouped, with what the role already holds ticked', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();

    expect(q('role-matrix')?.textContent).toContain('Équipe');
    expect(q('role-matrix')?.textContent).toContain('Stock');
    expect(q('role-matrix')?.textContent).toContain('Voir les membres');
    expect(tickOf('stock.read').checked).toBe(true);
    expect(tickOf('user.read').checked).toBe(false);
    expect(tickOf('user.read').disabled).toBe(false);
  });

  it('counts the name and the ticks changed beside the editor title, holds the page while unsaved, and closes without asking', async () => {
    await open();
    const unsaved = TestBed.inject(UnsavedChanges);
    q('role-open-barista')!.click();
    await settle();
    expect(
      q('role-editor-header')?.querySelector('[data-testid="role-bar"]') ?? null,
    ).not.toBeNull();
    expect((q('role-save') as HTMLButtonElement).disabled).toBe(true);
    expect(unsaved.count()).toBe(0);

    tickOf('user.read').click();
    tickOf('stock.read').click();
    const name = q('role-name') as HTMLInputElement;
    name.value = 'serveur';
    name.dispatchEvent(new Event('input'));
    await settle();

    expect(q('role-changes')?.dataset['count']).toBe('2');
    expect(unsaved.count()).toBe(2);
    const leaving = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leaving);
    expect(leaving.defaultPrevented).toBe(true);

    q('role-close')!.click();
    await settle();
    expect(q('role-editor')).toBeNull();
    expect(unsaved.count()).toBe(0);
    expect(facade.revise).not.toHaveBeenCalled();
  });

  // A refusal said only by a disabled button: an emptied name greyed « Enregistrer » out and said nothing.
  it('says an emptied name is needed under its field, puts the cursor there and saves nothing', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();

    const name = q('role-name') as HTMLInputElement;
    name.value = '   ';
    name.dispatchEvent(new Event('input'));
    await settle();
    expect((q('role-save') as HTMLButtonElement).disabled).toBe(false);
    q('role-save')!.click();
    await settle();

    expect(facade.revise).not.toHaveBeenCalled();
    expect(q('role-name-error')?.textContent).toContain('form.errors.required');
    expect(document.activeElement).toBe(name);
  });

  it('saves the whole set of ticks, so unticking is the same request as ticking', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();

    tickOf('user.read').click();
    tickOf('stock.read').click();
    await settle();
    q('role-save')!.click();
    await settle();

    expect(facade.revise).toHaveBeenCalledWith('c1', 'r-barista', 'barista', ['user.read'], 1);
  });

  it('takes into a quiet editor the ticks another person saved on the same role', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();
    facade.load.mockImplementationOnce(async () =>
      roles.set([owner, member, { ...barista, permissions: ['user.read'], version: 2 }]),
    );

    await announceSaved('role', 'r-barista');
    await settle();

    expect(tickOf('user.read').checked).toBe(true);
    expect(tickOf('stock.read').checked).toBe(false);
  });

  it('after a save refused as made over a version saved since, keeps a permission taken away there taken away', async () => {
    // A role decides what everyone holding it may do: renaming it in a form opened before someone took a
    // permission away must not hand that permission back to all of them.
    await open();
    q('role-open-barista')!.click();
    await settle();
    facade.revise.mockImplementationOnce(async () => {
      error.set('record_changed');
      return false;
    });
    facade.load.mockImplementationOnce(async () =>
      roles.set([owner, member, { ...barista, permissions: [], version: 2 }]),
    );

    const name = q('role-name') as HTMLInputElement;
    name.value = 'serveur';
    name.dispatchEvent(new Event('input'));
    await settle();
    q('role-save')!.click();
    await settle();
    await vi.waitFor(() => expect(tickOf('stock.read').checked).toBe(false));

    expect((q('role-name') as HTMLInputElement).value).toBe('serveur');
    expect(facade.clearError).toHaveBeenCalled();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual({
      kind: 'failure',
      key: 'live.saved_since',
      params: undefined,
    });

    q('role-save')!.click();
    await settle();
    expect(facade.revise).toHaveBeenLastCalledWith('c1', 'r-barista', 'serveur', [], 2);
  });

  it('asks which ticks to keep when both sides changed them, and takes the saved ones on request', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();
    tickOf('user.write').click();
    await settle();
    facade.load.mockImplementationOnce(async () =>
      roles.set([owner, member, { ...barista, permissions: ['user.read'], version: 2 }]),
    );

    await announceSaved('role', 'r-barista');
    await settle();

    expect(q('field-conflict-permissions')).not.toBeNull();
    expect(tickOf('user.write').checked).toBe(true);
    q('field-take-theirs-permissions')!.click();
    await settle();
    expect(q('field-conflict-permissions')).toBeNull();
    expect(tickOf('user.write').checked).toBe(false);
    expect(tickOf('user.read').checked).toBe(true);
    expect(tickOf('stock.read').checked).toBe(false);
  });

  it('keeps a name typed here when another person saved the same role under another name, until asked', async () => {
    await open();
    q('role-open-barista')!.click();
    await settle();
    const name = q('role-name') as HTMLInputElement;
    name.value = 'serveur';
    name.dispatchEvent(new Event('input'));
    await settle();
    facade.load.mockImplementationOnce(async () =>
      roles.set([owner, member, { ...barista, name: 'garçon', version: 2 }]),
    );

    await announceSaved('role', 'r-barista');
    await settle();

    expect((q('role-name') as HTMLInputElement).value).toBe('serveur');
    expect(q('field-conflict-name')).not.toBeNull();
    expect(q('record-changed')).not.toBeNull();
  });

  it('creates a role from an empty matrix', async () => {
    await open();
    q('role-new')!.click();
    await settle();

    const name = q('role-name') as HTMLInputElement;
    name.value = 'waiter';
    name.dispatchEvent(new Event('input'));
    tickOf('user.read').click();
    await settle();
    q('role-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith('c1', 'waiter', ['user.read']);
  });

  it('shows the owner as holding everything rather than every box ticked', async () => {
    // Ticking all of them would promise less than the wildcard does: it would not carry what a later release adds.
    await open();
    q('role-open-owner')!.click();
    await settle();

    expect(q('role-everything')?.textContent).toContain('tous les droits');
    expect(q('role-matrix')).toBeNull();
  });

  it('locks a shipped role: its boxes are readable and there is nothing to save', async () => {
    await open();
    q('role-open-member')!.click();
    await settle();

    expect(tickOf('user.read').checked).toBe(true);
    expect(tickOf('user.read').disabled).toBe(true);
    expect((q('role-name') as HTMLInputElement).disabled).toBe(true);
    expect(q('role-save')).toBeNull();
  });

  it('names who holds a role in its own sentence, never in the API’s English', async () => {
    await open();
    error.set('in_use');
    held.set({ role: 'cashier', holders: 'sami@twes.local', more: false });
    await settle();

    expect(q('roles-error')?.textContent).toContain('Des membres ont encore ce rôle.');
    expect(q('roles-error-detail')?.textContent?.trim()).toBe(
      'Le rôle « cashier » est encore celui de : sami@twes.local.',
    );

    held.set({ role: 'cashier', holders: 'sami@twes.local', more: true });
    await settle();
    expect(q('roles-error-detail')?.textContent?.trim()).toBe(
      'Le rôle « cashier » est encore celui de : sami@twes.local et d’autres.',
    );
  });

  it('adds nothing of the API’s words under any other refusal', async () => {
    await open();
    error.set('built_in');
    await settle();

    expect(q('roles-error')?.textContent).toContain('fourni avec le produit');
    expect(q('roles-error-detail')).toBeNull();
  });

  it('offers nothing to change without the permission', async () => {
    auth.hasPermission.mockReturnValue(false);
    await open();

    expect(q('role-new')).toBeNull();
    expect(q('role-delete-barista')).toBeNull();
    expect(q('role-open-barista')?.textContent).toContain('Consulter');
  });
});

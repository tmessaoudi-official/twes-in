// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
  viewChild,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { editorActions } from '../shared/form/editor-actions';
import { RecordBar } from '../shared/form/record-bar';
import { buildFormGroup } from '../shared/form/form-builder';
import { listRecord } from '../shared/form/list-record';
import { RecordChanged } from '../shared/form/record-changed';
import type { FormValues } from '../shared/form/form-types';
import type { ListDescriptor } from '../shared/list/list-types';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { EstablishmentRow } from './company-types';
import {
  ESTABLISHMENT_LIST,
  establishmentForm,
  establishmentFormValues,
  establishmentInput,
} from './establishment-forms';
import { EstablishmentsFacade } from './establishments-facade';
import { Feedback } from '../shared/feedback/feedback';
import { ArticleDefaults } from '../products/article-defaults';
import type { ArticleSubject } from '../shared/settings/settings-types';

/** The places the company issues documents from, one of them its default. */
@Component({
  selector: 'app-establishments-page',
  imports: [
    ArticleDefaults,
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DescriptorForm,
    RecordBar,
    RecordChanged,
  ],
  templateUrl: './establishments-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EstablishmentsPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(EstablishmentsFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);

  /** An establishment is closed through its form rather than deleted, so editing is its one action. */
  protected readonly list = computed<ListDescriptor<EstablishmentRow>>(() => ({
    ...ESTABLISHMENT_LIST,
    actions: [
      {
        id: 'edit',
        label: 'company.establishments.edit',
        icon: 'edit',
        run: (row) => this.edit(row),
        disabled: () => this.busy(),
        shown: () => this.mayManage(),
      },
    ],
  }));
  protected readonly establishments = this.facade.establishments;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly rowTestId = (row: EstablishmentRow): string => `establishment-${row.code}`;

  protected readonly editing = signal<EstablishmentRow | 'new' | null>(null);
  /**
   * The establishment being revised, as its own settings read it: what its customer screen says of stock, set here
   * for it alone (audit 2026-10-06, B-13). A new establishment has none until it exists.
   */
  protected readonly subject = computed<ArticleSubject | null>(
    () => {
      const editing = this.editing();
      return editing === null || editing === 'new' ? null : { establishmentId: editing.id };
    },
    { equal: (a, b) => JSON.stringify(a) === JSON.stringify(b) },
  );
  // Plain values, so a reload of the list that changes neither leaves the open form, and what is typed in it, alone.
  private readonly codePattern = computed(() => this.establishments()[0]?.codePattern ?? '');
  private readonly codeLocked = computed(() => {
    const editing = this.editing();
    return editing !== null && editing !== 'new' && editing.codeLocked;
  });
  protected readonly descriptor = computed(() =>
    establishmentForm(this.codePattern(), this.codeLocked()),
  );
  protected readonly form = computed(() => {
    const editing = this.editing();
    if (editing === null) return null;
    return buildFormGroup(
      this.descriptor(),
      editing === 'new' ? {} : establishmentFormValues(editing),
    );
  });

  /** The saved version the open form stands on, and what another person's save changed in it. */
  protected readonly sync = listRecord({
    kind: 'establishment',
    editing: this.editing,
    rows: this.establishments,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      if (companyId) await this.facade.loadEstablishments(companyId);
    },
    values: establishmentFormValues,
  });
  private readonly editor = viewChild(DescriptorForm);
  /** The open editor's bar: what is unsaved, its close and its save, which goes through the form's own submit. */
  protected readonly editorActions = editorActions({
    changes: this.sync.changes,
    busy: this.busy,
    save: () => this.editor()?.submit(),
    close: () => this.cancel(),
    closeLabel: 'company.establishments.cancel',
    saveLabel: 'company.establishments.save',
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['establishment'],
        () => this.facade.loadEstablishments(companyId),
        this.destroyRef,
      );
      await this.facade.loadEstablishments(companyId);
    }
  }

  protected add(): void {
    this.open('new');
  }

  protected edit(row: EstablishmentRow): void {
    this.open(row);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    const input = establishmentInput(values);
    const accepted =
      editing === 'new'
        ? await this.facade.createEstablishment(companyId, input)
        : await this.facade.reviseEstablishment(companyId, editing.id, input, this.sync.version());
    if (accepted) {
      this.editing.set(null);
      this.feedback.success('company.establishments.saved');
    } else if (this.error() === 'record_changed') {
      this.facade.clearError();
      await this.sync.savedElsewhere();
    }
  }

  private open(target: EstablishmentRow | 'new'): void {
    this.facade.clearError();
    this.editing.set(target);
  }
}

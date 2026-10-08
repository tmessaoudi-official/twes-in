// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Title } from '@angular/platform-browser';
import { type RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { BehaviorSubject, combineLatest, of, switchMap } from 'rxjs';
import { Brand } from '../brand/brand';

/**
 * Every page names itself in the browser tab, in the interface language, before the installation's name: it is the
 * first thing a screen reader says after a navigation, and what tells open tabs apart (RGAA 8.6). A route's `title` is
 * a translation key, so the tab follows a change of language without a navigation.
 */
@Injectable({ providedIn: 'root' })
export class PageTitles extends TitleStrategy {
  private readonly key = new BehaviorSubject<string | undefined>(undefined);

  constructor() {
    super();
    const translate = inject(TranslateService);
    const title = inject(Title);
    combineLatest([
      this.key.pipe(
        switchMap((key) => (key === undefined ? of(undefined) : translate.stream(key))),
      ),
      toObservable(inject(Brand).name),
    ])
      .pipe(takeUntilDestroyed())
      .subscribe(([page, name]: [unknown, string]) =>
        title.setTitle(typeof page === 'string' && page !== '' ? `${page} · ${name}` : name),
      );
  }

  override updateTitle(snapshot: RouterStateSnapshot): void {
    this.key.next(this.buildTitle(snapshot));
  }
}

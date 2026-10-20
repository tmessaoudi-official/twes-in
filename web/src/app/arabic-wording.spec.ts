// SPDX-License-Identifier: AGPL-3.0-or-later

import { TranslateDefaultParser } from '@ngx-translate/core';
import platform from '../../public/i18n/platform/ar.json';
import reports from '../../public/i18n/reports/ar.json';
import stockPlan from '../../public/i18n/stock-plan/ar.json';
import { PluralCompiler } from './shared/i18n/plural-compiler';

// Arabic agrees a counted noun and its predicate with the number, so a sentence is read here as the screen says it,
// count by count: the parity spec only sees that a key exists.
describe('Arabic wording', () => {
  const compiler = new PluralCompiler();
  const parser = new TranslateDefaultParser();
  const say = (text: string, params: Record<string, unknown>): string | undefined =>
    parser.interpolate(compiler.compile(text, 'ar'), params);

  it('names stock coming in as goods, not as money earned', () => {
    expect(reports.reports.columns.received).toBe('الوارد');
  });

  it('says an overdue bracket without a counted noun that would agree with one number only', () => {
    expect(say(reports.reports.columns.late, { from: 30, to: 60 })).toBe(
      'أيام التأخير: من 30 إلى 60',
    );
    expect(say(reports.reports.columns.late_last, { after: 90 })).toBe('أيام التأخير: أكثر من 90');
  });

  it('says the demo pool bounds for any numbers', () => {
    expect(say(platform.platform.demo.bounds, { min: 1, max: 20 })).toBe('العدد: من 1 إلى 20');
  });

  it('agrees the missing lines and the products to find with their count', () => {
    const nowhere = stockPlan.inventory.plan.search.note_nowhere;
    expect(say(nowhere, { count: 1 })).toBe('سطر واحد غير موجود في المخطط');
    expect(say(nowhere, { count: 2 })).toBe('سطران غير موجودين في المخطط');
    expect(say(nowhere, { count: 5 })).toBe('5 أسطر غير موجودة في المخطط');
    expect(say(nowhere, { count: 11 })).toBe('11 سطرًا غير موجود في المخطط');
    const toFind = stockPlan.inventory.plan.search.lines_title;
    expect(say(toFind, { count: 1 })).toBe('منتج واحد للعثور عليه');
    expect(say(toFind, { count: 2 })).toBe('منتجان للعثور عليهما');
    expect(say(toFind, { count: 5 })).toBe('5 منتجات للعثور عليها');
  });

  it('makes a sentence of every count of the shapes someone else saved', () => {
    const dropped = stockPlan.inventory.plan.draft.dropped;
    for (const count of [0, 1, 2, 5, 11, 100]) {
      expect(say(dropped, { count })).toMatch(/حفظ(ه|هما|ها) شخص آخر في الأثناء\.$/);
    }
  });
});

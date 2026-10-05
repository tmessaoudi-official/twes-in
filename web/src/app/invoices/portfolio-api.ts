// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdinstrumentsGetCollectionResponse,
  InstrumentPortfolioRowJsonldInstrumentPortfolioRowRead,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
import { INSTRUMENT_KINDS, INSTRUMENT_STATUSES } from './instruments-types';
import { codeOf, InvoicesRefused } from './invoices-api';
import type { PortfolioRow, PortfolioSearch } from './portfolio-types';

/** The HTTP edge of the portfolio of cheques and traites: the only code here that knows its endpoint and generated types. */
@Injectable({ providedIn: 'root' })
export class PortfolioApi {
  private readonly http = inject(HttpClient);

  /** One page of the company's instruments, narrowed and sorted by the API. */
  async portfolio(companyId: string, search: PortfolioSearch): Promise<ListPage<PortfolioRow>> {
    try {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdinstrumentsGetCollectionResponse>(
          `/api/companies/${encodeURIComponent(companyId)}/instruments`,
          { headers: { Accept: 'application/ld+json' }, params: toSearchParams(search) },
        ),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of the portfolio came without its total.');
      return { rows: page.member.map(toRow), total: page.totalItems };
    } catch (error) {
      throw new InvoicesRefused(codeOf(error));
    }
  }
}

function toSearchParams(search: PortfolioSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.status !== null) params = params.set('status', search.status);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function toRow(raw: InstrumentPortfolioRowJsonldInstrumentPortfolioRowRead): PortfolioRow {
  return {
    id: raw.id ?? '',
    invoiceId: raw.invoiceId ?? '',
    invoiceNumber: raw.invoiceNumber ?? null,
    customerName: raw.customerName ?? '',
    currency: raw.currency ?? '',
    kind: INSTRUMENT_KINDS.find((kind) => kind === raw.kind) ?? 'check',
    amount: raw.amount ?? '0.000',
    dueOn: raw.dueOn ?? '',
    bank: raw.bank ?? null,
    number: raw.number ?? null,
    status: INSTRUMENT_STATUSES.find((status) => status === raw.status) ?? 'held',
    settledOn: raw.settledOn ?? null,
  };
}

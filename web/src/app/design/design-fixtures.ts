// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Fixture data for the design checkpoint screens (development builds only). Every amount is a preformatted
 * string: nothing here computes money, which is test-driven where invoices are built for real (G3 and G7).
 */

/** Names the invoice screen offers in its customer picker. */
export const DESIGN_CUSTOMER_NAMES: readonly string[] = [
  'Atlas Distribution',
  'Béja Agro',
  'Carthage Conseil',
  'Djerba Voyages',
  'Éditions du Lac',
  'Fennec Informatique',
  'Gabès Chimie',
  'Hammamet Hôtels',
];

export interface DesignInvoiceLine {
  description: string;
  quantity: string;
  unitPrice: string;
  tax: string;
  total: string;
}

export interface DesignInvoice {
  number: string;
  status: 'draft';
  customer: string;
  customerAddress: string[];
  issueDate: string;
  dueDate: string;
  lines: DesignInvoiceLine[];
  subtotal: string;
  taxes: { label: string; amount: string }[];
  stamp: string;
  total: string;
  notes: string;
}

/** A Tunisian invoice: three decimals, VAT per line and the flat stamp duty, as the fiscal preset will produce. */
export const DESIGN_INVOICE: DesignInvoice = {
  number: 'F-2026-0042',
  status: 'draft',
  customer: 'Carthage Conseil',
  customerAddress: ['12, rue du Lac Léman', '1053 Les Berges du Lac', 'Tunis, Tunisie'],
  issueDate: '2026-09-13',
  dueDate: '2026-10-13',
  lines: [
    {
      description: 'Audit du système de facturation',
      quantity: '3',
      unitPrice: '850,000',
      tax: 'TVA 19 %',
      total: '2 550,000',
    },
    {
      description: 'Formation des équipes (demi-journée)',
      quantity: '2',
      unitPrice: '420,000',
      tax: 'TVA 19 %',
      total: '840,000',
    },
    {
      description: 'Licence annuelle — module comptable',
      quantity: '1',
      unitPrice: '1 200,000',
      tax: 'TVA 7 %',
      total: '1 200,000',
    },
  ],
  subtotal: '4 590,000 TND',
  taxes: [
    { label: 'TVA 19 %', amount: '644,100 TND' },
    { label: 'TVA 7 %', amount: '84,000 TND' },
  ],
  stamp: '1,000 TND',
  total: '5 319,100 TND',
  notes: 'Paiement par virement à 30 jours. Merci de rappeler le numéro de facture.',
};

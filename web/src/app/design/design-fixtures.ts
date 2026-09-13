// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Fixture data for the design checkpoint screens (development builds only). Every amount is a preformatted
 * string: nothing here computes money, which is test-driven where invoices are built for real (G3 and G7).
 */

export interface DesignCustomer {
  id: string;
  name: string;
  city: string;
  country: 'TN' | 'FR';
  vat: string;
  /** Used to sort only; the screen shows `balanceLabel`. */
  balanceSortKey: number;
  balanceLabel: string;
  status: 'active' | 'archived';
}

const CUSTOMER_NAMES = [
  'Atlas Distribution',
  'Béja Agro',
  'Carthage Conseil',
  'Djerba Voyages',
  'Éditions du Lac',
  'Fennec Informatique',
  'Gabès Chimie',
  'Hammamet Hôtels',
  'Institut Salammbô',
  'Jasmin Cosmétiques',
  'Kairouan Tapis',
  'Lyon Mécanique',
  'Marseille Logistique',
  'Nabeul Céramique',
  'Olivier & Fils',
  'Paris Studio',
  'Quai des Arts',
  'Rades Port Services',
  'Sfax Textiles',
  'Tunis Numérique',
  'Utique Bâtiment',
  'Val de Loire Vins',
  'Wadi Énergie',
  'Zaghouan Eaux',
];

const TN_CITIES = ['Tunis', 'Sfax', 'Sousse', 'Bizerte', 'Nabeul', 'Gabès'];
const FR_CITIES = ['Paris', 'Lyon', 'Marseille', 'Nantes'];

export const DESIGN_CUSTOMERS: readonly DesignCustomer[] = CUSTOMER_NAMES.map((name, index) => {
  const country = index % 4 === 3 ? 'FR' : 'TN';
  const balance = ((index * 7919) % 48000) + (index % 3) * 125;
  const whole = Math.trunc(balance / 10);
  const fraction = balance % 10;
  return {
    id: `c-${String(index + 1).padStart(3, '0')}`,
    name,
    city:
      country === 'TN' ? TN_CITIES[index % TN_CITIES.length] : FR_CITIES[index % FR_CITIES.length],
    country,
    vat:
      country === 'TN'
        ? `${String(1234567 + index * 311).slice(0, 7)}${'ABCDEFGH'[index % 8]}/A/M/000`
        : `FR${String(40 + index)}${String(123456789 + index * 97).slice(0, 9)}`,
    balanceSortKey: balance,
    balanceLabel:
      country === 'TN'
        ? `${whole.toLocaleString('fr-FR')},${String(fraction).padEnd(3, '0')} TND`
        : `${whole.toLocaleString('fr-FR')},${String(fraction).padEnd(2, '0')} €`,
    status: index % 5 === 4 ? 'archived' : 'active',
  };
});

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

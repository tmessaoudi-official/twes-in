// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * A quantity is a decimal string with at most this many decimals, as the API stores it (`NUMERIC(14,3)`), and every sum
 * here is whole thousandths: `0.1 + 0.2` as floats is not `0.3`, and « il reste N à placer » must reach zero.
 */
const SCALE = 3;

/**
 * A quantity in thousandths, or null where the text is not one: no sign, a point and not a comma, and no more decimals
 * than the unit keeps (`decimals`, the product's `unitDecimals`; the API stores three, but it refuses a piece in halves).
 */
export function unitsOf(quantity: string, decimals = SCALE): bigint | null {
  const places = Math.min(Math.max(decimals, 0), SCALE);
  const shape = places === 0 ? /^\d+$/ : new RegExp(`^\\d+(\\.\\d{1,${places}})?$`);
  if (!shape.test(quantity)) return null;
  const [whole, fraction = ''] = quantity.split('.');
  return BigInt(whole + fraction.padEnd(SCALE, '0'));
}

/** Thousandths back to the shortest decimal string: `1250n` is `1.25`, `12000n` is `12`. */
export function quantityOf(units: bigint): string {
  const sign = units < 0n ? '-' : '';
  const digits = (units < 0n ? -units : units).toString().padStart(SCALE + 1, '0');
  const whole = digits.slice(0, -SCALE);
  const fraction = digits.slice(-SCALE).replace(/0+$/, '');
  return sign + whole + (fraction === '' ? '' : '.' + fraction);
}

/** How a delivery stands against the places it is shared over. */
export interface Placement {
  /** `short` while some is unplaced, `done` at zero, `over` past what was received, `invalid` when it cannot be told. */
  readonly state: 'short' | 'done' | 'over' | 'invalid';
  /** What is left to place, or by how much it is over; `0` when it cannot be told. */
  readonly left: string;
  /** What the readable parts add up to. */
  readonly placed: string;
}

/**
 * The delivery's received quantity against the quantities typed for each place. An empty part is nothing yet; a part
 * that is not a quantity, no quantity received, or a received quantity of zero makes it `invalid`, so the form refuses
 * to save and says nothing false about what is left.
 */
export function placement(received: string, parts: readonly string[], decimals = SCALE): Placement {
  let placed = 0n;
  let readable = true;
  for (const part of parts) {
    if (part === '') continue;
    const units = unitsOf(part, decimals);
    if (units === null) readable = false;
    else placed += units;
  }
  const total = unitsOf(received, decimals);
  if (!readable || total === null || total === 0n) {
    return { state: 'invalid', left: '0', placed: quantityOf(placed) };
  }
  const left = total - placed;
  return {
    state: left === 0n ? 'done' : left > 0n ? 'short' : 'over',
    left: quantityOf(left < 0n ? -left : left),
    placed: quantityOf(placed),
  };
}

/** One place of a receipt and what is put there, the quantity as typed (a decimal string, empty while untouched). */
export interface Part {
  readonly locationId: string;
  readonly quantity: string;
}

/** The first of the company's places that no row names yet, to start the next row on. */
export function addPlace(
  parts: readonly Part[],
  places: readonly { readonly id: string }[],
): readonly Part[] {
  const free = places.find((place) => !parts.some((part) => part.locationId === place.id));
  return free === undefined ? parts : [...parts, { locationId: free.id, quantity: '' }];
}

/** A receipt goes somewhere: the last row stays. */
export function removePlace(parts: readonly Part[], index: number): readonly Part[] {
  return parts.length <= 1 ? parts : parts.filter((_, at) => at !== index);
}

export function setPlaceQuantity(
  parts: readonly Part[],
  index: number,
  quantity: string,
): readonly Part[] {
  return parts.map((part, at) => (at === index ? { ...part, quantity } : part));
}

/** A place appears once, as the API insists: pointing a row at one another row has leaves the rows as they are. */
export function setPlaceLocation(
  parts: readonly Part[],
  index: number,
  locationId: string,
): readonly Part[] {
  if (parts.some((part, at) => at !== index && part.locationId === locationId)) return parts;
  return parts.map((part, at) => (at === index ? { ...part, locationId } : part));
}

/**
 * What is still to place goes to the company's default place for the establishment, on its row or on a row added for it
 * (« le reste va à l'emplacement par défaut »). Only a delivery that is short has a rest: done or over stays as it is.
 */
export function restToDefault(
  parts: readonly Part[],
  received: string,
  defaultLocationId: string,
  decimals = SCALE,
): readonly Part[] {
  const stand = placement(
    received,
    parts.map((part) => part.quantity),
    decimals,
  );
  const rest = unitsOf(stand.left);
  if (stand.state !== 'short' || rest === null) return parts;
  const at = parts.findIndex((part) => part.locationId === defaultLocationId);
  if (at === -1) return [...parts, { locationId: defaultLocationId, quantity: stand.left }];
  const had = unitsOf(parts[at].quantity, decimals) ?? 0n;
  return setPlaceQuantity(parts, at, quantityOf(had + rest));
}

/** What the API is sent: the rows given a quantity above zero, each written the way it reads it. */
export function toReceiptParts(
  parts: readonly Part[],
  decimals = SCALE,
): { locationId: string; quantity: string }[] {
  return parts.flatMap((part) => {
    const units = unitsOf(part.quantity, decimals);
    return units === null || units === 0n
      ? []
      : [{ locationId: part.locationId, quantity: quantityOf(units) }];
  });
}

/**
 * What a count over several places sends: every row, each what was found there, nothing found (0) included; null
 * while a row has no count, since a place left blank is not a place where nothing was found.
 */
export function toCountParts(
  parts: readonly Part[],
  decimals = SCALE,
): { locationId: string; quantity: string }[] | null {
  const counted = parts.map((part) => ({
    locationId: part.locationId,
    units: unitsOf(part.quantity, decimals),
  }));
  return counted.some((part) => part.units === null || part.locationId === '')
    ? null
    : counted.map((part) => ({ locationId: part.locationId, quantity: quantityOf(part.units!) }));
}

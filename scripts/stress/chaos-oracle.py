# SPDX-License-Identifier: AGPL-3.0-or-later
"""The chaos harness's judge (scripts/stress/chaos.sh): what the database kept, held to what the sellers asked.

Reads a run's folder: ledger.tsv (chaos-driver.sh: one line per attempt, `seller sale step attempt key code started
ended`, and a `done` line per sale paid naming its invoice and the amount due), faults.tsv (`fault killed back`, in
milliseconds), checks.tsv (`command exit`) and facts.json (the run's invoices, payments and stock, read from the
database). Each finding is a line; any finding exits 1. Nothing here talks to the stack, so its test runs on fixtures.
"""

import json
import sys
from decimal import Decimal
from pathlib import Path

# A fault each request was sent through: the api and the database stop answering mid-request. The worker serves no
# request, so its kill is only required to have happened.
REQUEST_FAULTS = ('api', 'postgres')


def rows(path: Path) -> list[list[str]]:
    return [line.split('\t') for line in path.read_text().splitlines() if line.strip()]


def judge(folder: Path, floor: int) -> list[str]:
    findings: list[str] = []
    ledger = rows(folder / 'ledger.tsv')
    faults = rows(folder / 'faults.tsv')
    checks = rows(folder / 'checks.tsv')
    facts = json.loads((folder / 'facts.json').read_text())

    attempts = [r for r in ledger if r[2] != 'done']
    done = {r[4]: Decimal(r[7]) for r in ledger if r[2] == 'done'}

    for r in attempts:
        if r[5] == '500':
            findings.append(f'seller {r[0]} sale {r[1]} {r[2]} attempt {r[3]} answered 500: a fault said as an unknown failure')
    # A write sent again with its key may find the first still holding it, or made with its answer lost (the driver
    # writes which 409 it read); a first attempt's key is new, so it never may.
    resent = ('409:in-progress', '409:unanswered')
    for r in attempts:
        if r[2] in ('draft', 'pay') and r[5] not in ('201', '000', '502', '503', '504') and not (r[5] in resent and r[3] != '1'):
            findings.append(f'seller {r[0]} sale {r[1]} {r[2]} attempt {r[3]} answered {r[5]}, neither made nor a fault')
    # An issue sent again after a lost answer may find its invoice issued already (409); a first one never should.
    for r in attempts:
        if r[2] == 'issue' and r[5] not in ('200', '000', '502', '503', '504') and not (r[5] == '409' and r[3] != '1'):
            findings.append(f'seller {r[0]} sale {r[1]} issue attempt {r[3]} answered {r[5]}, neither issued nor a fault')

    if len(done) < floor:
        findings.append(f'{len(done)} sales paid, under the floor of {floor}: the faults met too little work to judge')

    issued = {i['id']: i for i in facts['invoices'] if i['status'] != 'draft'}
    for invoice_id in sorted(set(issued) - set(done)):
        findings.append(f'invoice {invoice_id} ({issued[invoice_id]["number"]}) is issued for the run, but no seller saw it paid')
    for invoice_id in sorted(set(done) - set(issued)):
        findings.append(f'invoice {invoice_id} was paid in the ledger, but the database holds it as a draft or not at all')

    numbers = [i['number'] for i in issued.values()]
    for number in sorted({n for n in numbers if numbers.count(n) > 1}):
        findings.append(f'the number {number} was given twice')
    if any(n is None for n in numbers):
        findings.append('an issued invoice has no number')

    paid: dict[str, list[Decimal]] = {}
    for p in facts['payments']:
        paid.setdefault(p['invoice'], []).append(Decimal(p['amount']))
    for invoice_id, due in sorted(done.items()):
        got = paid.get(invoice_id, [])
        if len(got) != 1:
            findings.append(f'invoice {invoice_id} holds {len(got)} payments, wanted exactly one: an attempt sent again was kept twice or lost')
        elif got[0] != due:
            findings.append(f'invoice {invoice_id} holds a payment of {got[0]}, wanted {due}')

    onhand = Decimal(facts['onhand'] or '0')
    if onhand != -len(issued):
        findings.append(f'the run\'s product stands at {onhand}, wanted {-len(issued)}: one unit out per invoice issued')

    killed = {f[0]: (int(f[1]), int(f[2])) for f in faults}
    for fault in ('api', 'worker', 'postgres'):
        if fault not in killed:
            findings.append(f'the {fault} was never killed')
    for fault in REQUEST_FAULTS:
        if fault not in killed:
            continue
        at, back = killed[fault]
        caught = [r for r in attempts if int(r[6]) <= at < int(r[7]) or (r[5] == '000' and at <= int(r[6]) <= back)]
        if not caught:
            findings.append(f'no request was in flight when the {fault} was killed: the fault met nothing')

    # A stack that came back but sells nothing more has not recovered.
    if killed:
        last_back = max(back for _, back in killed.values())
        if not any(int(r[6]) > last_back for r in ledger if r[2] == 'done'):
            findings.append('no sale was paid after the last fault came back: the stack never recovered')

    for name, code in checks:
        if code != '0':
            findings.append(f'{name} exited {code}')
    for name in ('app:data:check', 'app:invoices:verify-chain'):
        if name not in {c[0] for c in checks}:
            findings.append(f'{name} never ran')

    return findings


def main() -> int:
    folder = Path(sys.argv[1])
    floor = int(sys.argv[2]) if len(sys.argv) > 2 else 30
    findings = judge(folder, floor)
    for finding in findings:
        print(f'chaos-oracle: {finding}')
    if not findings:
        print('chaos-oracle: every sale kept once, numbered once, paid once; the stock, the data checks and the chain agree')
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())

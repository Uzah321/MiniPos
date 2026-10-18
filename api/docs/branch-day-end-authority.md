# Branch Day End authority and synchronization

Day End is a **company + branch + trading-date** operation. It is never a
per-till operation.

## FRONT stock reconciliation

- A branch has one `front_stock_daily_closings` record for each trading date
  and stock area.
- `till_id` is audit provenance only. A closing captured by Till 1 satisfies
  Till 2, Android and Back Office when they belong to the same company and
  branch.
- A closing from another branch can never satisfy the current branch.
- `FRONT_STOCK_CLOSING_REQUIRED` means no closed branch record reached the
  database in which Day End preflight ran.
- `FRONT_STOCK_CLOSING_STALE` means a closed record exists, but FRONT stock
  changed after that count. Stop selling/issuing and recount FRONT stock.

On an edge deployment, saving a closing also creates an immutable outbox event
in the same database transaction. The worker can recover the event from the
closing row after a process restart. Central imports it idempotently under the
node's fixed company and branch scope.

## Where Day End executes

- **Cloud-connected edge:** a user may press Day End on a branch web terminal,
  but the edge first uploads all sales, cashups and FRONT closing events. It
  then sends a signed, idempotent command to Central. Central performs the
  actual Day End and publishes the new branch snapshot. The edge downloads the
  result into the database shared by every web till.
- **Android:** Android uses the authenticated cloud API directly; it observes
  the same Central branch calendar.
- **Central Back Office:** Central may run/force Day End directly and publishes
  the same snapshot to every paired edge and Android terminal.
- **Standalone local installation:** with no cloud pairing, the local database
  remains its own authority and executes Day End locally.

The edge never closes its local calendar independently from Central. If
connectivity, outbox acknowledgement, operator scope or license validation
fails, no local Day End starts. Retrying the same `run_key` is safe.

## Cashup synchronization safety

A completed shift has one semantic cashup even if an edge and Central created
different UUIDs while disconnected. Central acknowledges the edge event only
when the company, branch, shift, till, cashier, trading date, immutable totals,
summaries, closure timestamps and full denomination set match. The existing
Central UUID remains canonical and an approved cashup is never downgraded.

An older release permanently rejected this safe duplicate with `This shift
already has a cashup under another UUID.` The edge recovery scan requeues only
that obsolete dead-letter reason once. A real mismatch is retained as
`SYNC_CASHUP_CONFLICT` and blocks Day End for review; it is never overwritten
or repeatedly retried.

## Operator checklist

1. Cash up every open shift for the branch, across all tills.
2. Confirm Android terminals have uploaded pending work.
3. Save one FRONT stock closing for the authoritative trading date.
4. Wait for both synchronization indicators to be green.
5. Run Day End once from any authorised terminal or Central Back Office.
6. Other terminals receive the closed/new trading date automatically; do not
   run a separate Day End per till.

# Distributed deployment and synchronization

## Purpose

ServeNest uses one codebase in distinct deployment profiles:

| Profile | UI | Database | Internet requirement |
| --- | --- | --- | --- |
| `cloud` / `back-office` | Back Office only | Shared multi-company cloud database | Required for cloud users |
| `edge` / `front-office` | Front Office only | Local branch database | Not required for selling |
| `standalone` / `combined` | Front and Back Office | Local company database | Not required |

An edge installation is one **branch POS server** with one local database and one durable synchronization cursor. Several physical browser terminals may connect to that server over the branch LAN. The server is paired once to one company and branch; every browser terminal is then activated separately for one existing till. Transactions from all of those tills enter the same local database and durable branch outbox while retaining their originating `till_id`.

The deployment profiles are separate installations of the same source code, not separate forks. The Back Office browser points at the cloud API. Every physical Front Office browser points at the branch Laravel API—normally by the server's LAN IP address—and never posts a sale directly to the cloud. A customer may instead install a separate edge API/database per terminal, but must not reuse one node identity across several independent databases.

The React source remains one codebase, but every release produces separate Back Office and Front Office builds with no workspace chooser. The Laravel application also remains one codebase. `SERVENEST_DEPLOYMENT_MODE` decides whether it acts as the cloud hub, a synchronizing edge, or a local-only standalone installation.

```text
Till Browser A ─┐
Till Browser B ─┼─ Branch Edge API + DB ── signed push / cursor pull ── Shared Cloud Hub
Till Browser C ─┘        one node/cursor                              multi-company DB
       │                        │                                             │
 unique till activation   company + branch binding                scoped Back Office users
```

## Identity and tenant isolation

Every company has a stable human-readable company code. It replaces the hostname as the normal pre-login tenant selector on the shared cloud.

A company `super_admin` remains tenant-scoped. Global Company Management is granted only to a Super Admin whose immutable `pos_users.id` UUID is explicitly listed in `PLATFORM_ADMIN_POS_USER_IDS` on the cloud/standalone server. The default list is empty, edge deployments ignore it, and the user must sign in again after the list or config cache changes. This prevents one customer's administrator from managing other companies.

Every edge node is provisioned once and is bound on the server to:

- one `company_id`;
- one `branch_id`;
- one branch-wide sync identity and cursor; the legacy node `till_id` is only the initial/default terminal binding;
- one node UUID;
- one encrypted signing secret, replaced only through explicit recovery pairing;
- a deployment status (`active`, `suspended`, or `revoked`).

Each browser terminal is independently bound to an active registered till in that same company and branch. A terminal credential cannot authorize another branch, and the edge validates the sale's payload till against the paired company/branch before upload. A branch server may therefore synchronize many local tills without merging or relabelling their transactions.

The payload is never allowed to choose its company or branch. The cloud derives both values from the authenticated node. If an envelope contains different tenant identifiers, it is rejected and audited.

Company context resolution follows this order:

1. a valid registered till or signed sync-node credential;
2. the authenticated user's company;
3. a company code on the pre-login endpoints;
4. the legacy company domain only when the cloud operator explicitly enables
   `SERVENEST_SYNC_ALLOW_LEGACY_DOMAIN_RESOLUTION=true` during migration.

If two supplied identities disagree, the request is rejected. An arbitrary `company_id` header is never a valid tenant credential.

## Pairing a new branch node

1. A cloud administrator creates the company, branch and tills.
2. The administrator creates a one-time pairing code for the branch node.
3. On the local server, the administrator enters the shared cloud URL, company code and pairing code.
4. The local Laravel API calls the cloud pairing endpoint.
5. The cloud consumes the one-time code and returns the node identity, one-time raw secret, and the server-authoritative company/branch identity plus the initial/default till.
6. The local API stores the node secret encrypted at rest. React never receives or stores it.
7. The local API downloads the initial configuration snapshot.
8. The edge generates a server recovery credential, stores only its hash, and returns the raw value once. This protects manual status/run endpoints without exposing the cloud node secret.
9. For the initial browser, the edge generates a terminal credential, stores only its hash, and returns the raw value once.
10. Front Office login is locked to the paired branch. Selling is ready only after catalogue, cost/stock bootstrap and that browser terminal's activation are complete.

## Activating additional LAN terminals

The branch server is not paired again for every browser. For each additional terminal:

1. The Back Office administrator opens **Deployment & Sync**, selects the active branch server and the intended till, and creates a short-lived one-time terminal activation code.
2. Only the code digest is stored in the cloud. Issuing a replacement revokes any earlier unused code for the same node/till.
3. The operator enters the code in the new Front Office browser, which sends it only to the local edge API.
4. The edge exchanges it with the cloud over the already signed node channel. The cloud atomically consumes the code and returns the authoritative same-company/same-branch till identity.
5. The edge creates the terminal credential locally, stores only its hash, and displays the raw credential only to that browser response. The terminal credential is never sent to or stored by the cloud.
6. Later status, sync and sale requests resolve that browser's own till. The shared node outbox still uploads all branch transactions exactly once.

An activation code is valid only for an active node and an active till at the paired branch, expires after a short configurable interval, and is single-use. Losing a browser profile does not require replacing the node credential; issue a new terminal activation code instead.

The public edge pairing request accepts `company_code`, `pairing_code`, `cloud_url`, and an optional `node_name`. It never accepts a public recovery override. Recovery authority is encoded in a short-lived code created by an authenticated cloud administrator for the existing node and its unchanged branch/till binding.

Re-pairing requires a recovery code issued by an authenticated cloud administrator. Issuing that code does not disable the active credential. A browser can consume it only when no local outbox records are pending; the local console's explicit recovery flag is required after an operator exports or resolves pending events. One-step credential rotation is disabled because changing only the cloud secret would strand an offline edge.

An already paired installation may recover only its existing node. Re-pairing cannot change its company, branch or till identity. Provision a fresh local database when intentionally repurposing a physical POS; this prevents an old cursor or unsent local data from crossing into another tenant.

## Request signing

Node-to-cloud requests are signed with HMAC-SHA256 over a canonical string containing:

- HTTP method;
- exact request path and query;
- node UUID;
- Unix timestamp;
- random nonce;
- SHA-256 hash of the exact body.

The cloud rejects an invalid signature, an inactive node, a timestamp outside the allowed window, or a reused nonce. It stores the verification secret using Laravel's encrypted cast plus a SHA-256 fingerprint for audit. HMAC uses the encrypted raw secret directly; the plaintext fingerprint is never a signing key. A raw secret is returned only once and a node can be revoked or explicitly recovery-paired.

Production edges must pin `SERVENEST_SYNC_CLOUD_URL`; arbitrary runtime cloud origins are disabled by default. HTTPS and certificate verification are mandatory outside explicitly enabled local development.

User bearer tokens and node credentials have different purposes. A user token controls what a person may do. A node credential controls which company and branch a machine may synchronize. One never replaces the other.

## Durable sync protocol

Every synchronized change uses an envelope with:

```json
{
  "id": "uuid",
  "schema_version": 1,
  "aggregate_type": "sale",
  "aggregate_id": "uuid",
  "event_type": "sale.completed",
  "occurred_at": "2026-10-05T12:00:00Z",
  "payload_hash": "sha256",
  "payload": {}
}
```

The first shipped aggregate is `sale.completed`. A request-driven recovery scan discovers every completed local sale that has no outbox event, so a process crash between sale commit and outbox discovery cannot make the sale invisible. Once discovered, retries and backoff are durable. The cloud inbox has a unique node/event key, so retrying the same batch is safe. The cloud returns an acknowledgement for every accepted, duplicate, rejected, or retryable event.

Cloud-to-edge changes use a monotonic cloud sequence and a cursor stored per node. Pulling the same page twice is safe. Deletions are tombstone events; timestamp polling alone is not used because it loses deletions and is vulnerable to clock differences.

Synchronization is normally driven by the branch server's `ServeNestEdgeSync`
Windows service, which runs `php artisan sync:edge-worker`. The Front Office UI
heartbeat, the **Sync now** action and `php artisan sync:node run` are fallbacks;
all actual runs invoke the same locked `SyncOrchestrator`. When the service has
a fresh healthy heartbeat, browser `/sync/run` requests are coalesced locally
instead of repeating its cloud roundtrip. If the heartbeat becomes stale or
degraded, a browser request falls through as an automatic recovery path.
Laravel middleware only authenticates and scopes requests—it does not start
synchronization. The worker is an operating-system service rather than a cron
entry, queue job or Laravel scheduler, so cloud Day End reaches the branch
database even when no till browser is open.

The orchestrator uses one distributed lock per node. If two LAN terminals request a run together, one performs the push/pull and the other receives a benign `202 already_running` response rather than starting a competing cursor or being rate-limited as an error. The upload phase scans completed sales into the durable outbox, drains acknowledged batches, then the download phase advances the configuration cursor only after applying a committed snapshot.

## Direction and conflict policy

### Edge to cloud: operational ledger

The edge is authoritative for branch operations created while selling:

- completed sales and their receipt lines, taxes and tenders;
- the sale's applied discount rows for line/cart audit and reporting;
- the referenced trading-day and shift-session headers needed to group the sale;
- the sale's safely identifiable immutable stock-movement evidence;
- cash-drawer movements linked to that sale.

On cloud import the same transaction updates product stock, batch/location stock, cost valuation and, for a credit sale, the customer ledger/balance. Hospitality recipe and modifier-recipe ingredient movements are part of the sale bundle. Shift close/cashup changes, void/refund, customer-master edits and purchase aggregates are future protocol versions; they are not silently inferred by schema v1.

Financial documents are append-only after posting. A correction is a new void, refund or adjustment event; it is not a last-write-wins edit.

### Cloud to edge: configuration

The cloud is authoritative for centrally managed configuration:

- companies and branches relevant to the node;
- products, categories, subcategories and units;
- prices, taxes, currencies and payment types;
- role price-level eligibility, discounts and their product/category/role targets;
- product break rules, hospitality recipes/ingredients, prep messages and modifiers;
- company and branch settings;
- users, roles, permissions and branch assignments;
- shift configuration and user shift assignments;
- the branch trading-day calendar after a centrally completed or forced Day End;
- credit-customer accounts needed for offline credit sales;
- initial product cost, branch stock, batch and location-balance projections;
- device/license status that the edge is allowed to know.

Known secret/password/token/private-key setting keys are excluded. Configuration records retain their UUIDs on every node. Whole snapshots are content-hashed and delivered on a monotonic cursor; a stale cursor cannot move an edge backwards. The service retains the latest two complete snapshots per company/branch, so a stale node can jump directly to the newest cursor without unbounded snapshot storage. Licence entitlements also carry a monotonic revision and reject rollback or same-revision/different-payload replay. Deletions are soft-delete tombstones.

Offline login requires synchronized password and login-code hashes for the branch's users. They are not plaintext credentials, but they are still sensitive authentication material. Encrypt POS disks, restrict the Windows/browser account, and protect edge database backups accordingly.

Schema v1 sends complete snapshots rather than row deltas, so very large catalogues use more bandwidth. Incremental configuration events are a future optimization, not a correctness dependency.

### Production scaling decision

Do not replace the branch worker with cron or Laravel's scheduler. A branch POS
must upload promptly, pull Day End/configuration while no browser is open, retry
with backoff after an outage, and avoid overlapping cursors. One continuously
supervised outbound worker per branch server is the correct transport owner.
Cron/the scheduler is appropriate only for cloud housekeeping such as pruning
expired replay nonces, compacting acknowledged audit rows, reconciliation and
alerting.

For a large shared-cloud deployment, run stateless API instances behind a load
balancer and use Redis for shared cache, locks and queued cloud work. Back Office
commits should mark the affected company/branch configuration revision and
enqueue snapshot/delta publication after commit. Edge workers should retain
bounded cursor batches, exponential backoff and add randomized/adaptive idle
polling so many branches do not wake together. Schema v1's full snapshot build
on pull is a correctness-first foundation; revision-driven publication and
incremental per-dataset changes are required before treating it as the final
architecture for thousands of branch nodes.

Discount usage counters are not treated as cloud-authored configuration. Several disconnected tills can therefore exceed a company-wide coupon or usage limit before they reconnect; strict global promotion reservations require an online authority and remain a later protocol feature.

When an installation is `standalone`, transport is disabled and local Back Office remains authoritative. When an installation is `edge`, cloud-managed configuration should be read-only locally unless a feature has an explicit bidirectional conflict policy.

### Stock

Stock is derived from an append-only movement ledger. A sale event applies its immutable movements and materialized stock/cost projections atomically on the cloud. The edge always pushes before it pulls. While any sale is undelivered—or a completed sale has not yet been discovered into the outbox—the snapshot applier preserves local stock, batch, location, cost and credit-balance fields instead of overwriting them with an older cloud value. After acknowledgement, the updated cloud projection becomes authoritative.

Several LAN tills using one edge database share the same local stock transaction boundary, so their local changes are immediately visible to one another. Separate edge databases at the same branch remain only eventually consistent and create an oversell risk. Operators using separate databases must partition stock, disallow negative stock and reconnect frequently; hard cross-node reservations are a future protocol. Cloud reporting becomes authoritative only after all branch nodes acknowledge their outboxes.

## Ordering

Events are applied in dependency order:

1. company and branch identity;
2. roles, permissions, users and shifts;
3. currencies, taxes, payment types and units;
4. categories, products, prices, break rules, recipes, prep messages, modifiers and discounts;
5. operational headers;
6. operational lines, payments and taxes;
7. stock/cash ledger effects;
8. cashups, day-end and reporting projections.

An event whose dependency has not arrived remains retryable. It is not discarded.

## Offline behavior

The compiled Front Office SPA and Laravel API are installed locally. The browser always sends sales to the local API, never directly to the cloud. If the internet fails:

- login may continue from locally synchronized users subject to the offline licence;
- products, prices, taxes and payment types come from the local database;
- sales, payments, receipts and stock movements commit locally;
- outbox rows remain pending;
- the UI shows **Local — waiting to sync** rather than claiming the system is online;
- synchronization resumes idempotently when the cloud becomes reachable.

A cloud-hosted SPA by itself is not an offline POS. Browser cache or IndexedDB is not treated as the financial system of record.

## Licence signing

Cloud and edge installations normally have different `APP_KEY` values. Offline licence signatures therefore must not depend on `APP_KEY`.

The preferred arrangement is asymmetric signing:

- the cloud holds the private signing key;
- edge installs hold only the public verification key;
- a centrally licensed standalone install should also be `verify_only` and import a cloud-signed entitlement;
- only a deliberately self-authorizing standalone installation is an `issuer` and holds a private key;
- existing HMAC licences remain verifiable during migration;
- the private key is never synchronized to an edge node.

An expired licence blocks normal users locally and in the cloud. Tenant Super Admins retain the explicitly defined licence-recovery bypass, but that tenant role never grants shared-cloud platform access by itself. Global company management requires the POS user's UUID in the cloud/standalone server's `PLATFORM_ADMIN_POS_USER_IDS` allowlist; the list defaults empty and is ignored by edge deployments. Licence validation is middleware-driven and does not require a scheduler.

The daily renewal modal reads its contact number from the company's `license_support_phone`; platform Company Management can edit it. `LICENSE_SUPPORT_PHONE` is only the fallback. The warning window defaults to 90 days and is configurable with `LICENSE_WARNING_DAYS`.

The compact `XXXX-XXXX-XXXX-XXXX` value is an activation key, not the complete cryptographic entitlement. It works offline after that signed licence row has reached the POS. A completely new entitlement on a permanently disconnected machine needs either one initial synchronization, a signed v2 `license_token` imported through `POST /api/pos/licenses/renew-offline`, or a Central-issued `.salexa-company` standalone provisioning package imported with `php artisan salexa:standalone-import`. The standalone package also bootstraps the exact Central company and branch UUIDs; general-purpose UI and QR import screens are not included.

## Deployment examples

### Shared cloud Back Office

Laravel API environment:

```dotenv
SERVENEST_DEPLOYMENT_MODE=cloud
SERVENEST_SYNC_ALLOW_LEGACY_DOMAIN_RESOLUTION=false
LICENSE_SIGNING_MODE=issuer
PLATFORM_ADMIN_POS_USER_IDS=00000000-0000-0000-0000-000000000000
PLATFORM_ADMIN_POS_USER_IDS=019f...uuid-of-trusted-super-admin
```

React installation/build environment:

```dotenv
REACT_APP_DEPLOYMENT_MODE=back-office
```

All companies use the same application URL. Users identify their organization with the company code before login.

`PLATFORM_ADMIN_POS_USER_IDS` is a comma-separated operator-controlled list of
`pos_users.id` UUIDs. Use the UUID, not a username, role name or company ID.
Each listed user must also retain a tenant Super Admin identity. After changing
the list, clear the Laravel configuration cache and sign in again so a new
Back Office token is issued. Removing an ID takes effect at the server-side
platform check even if an older token still contains the platform ability.
Successful login and `/api/pos-auth/me` responses expose the resulting boolean
as `is_platform_admin`, both at the response root and on the returned user.
Clients must use that flag for global Company Management visibility instead of
inferring platform access from a tenant role name.

### Branch POS with cloud management

Local Laravel API environment:

```dotenv
SERVENEST_DEPLOYMENT_MODE=edge
SERVENEST_SYNC_CLOUD_URL=https://management.example.com
SERVENEST_SYNC_VERIFY_TLS=true
SERVENEST_SYNC_ALLOW_LEGACY_DOMAIN_RESOLUTION=false
LICENSE_SIGNING_MODE=verify_only
```

Local React runtime (`public/runtime-config.js`) or build environment:

```text
deployment_mode: "front-office"
api_base_url: "http://192.168.1.10/salexaapi" # only when SPA is served elsewhere
# or REACT_APP_DEPLOYMENT_MODE=front-office at build time
```

`SERVENEST_SYNC_CLOUD_URL` must be the shared cloud origin, without a path, query or credentials. The branch server is paired after installation. A LAN terminal opens the ServeNest POS origin directly; if its SPA is hosted elsewhere, set `api_base_url` to the branch API rather than `localhost`. The raw node signing secret is encrypted in Laravel's local database and is never exposed to React. Server recovery values and each terminal's activation credential are stored only by the trusted local browser profile; treat that Windows account and browser profile as part of the POS security boundary.

### Fully local customer

Laravel API environment:

```dotenv
SERVENEST_DEPLOYMENT_MODE=standalone
LICENSE_SIGNING_MODE=verify_only
```

React installation/build environment:

```dotenv
REACT_APP_DEPLOYMENT_MODE=combined
```

Synchronization transport is disabled. Both workspaces use the local database.

The example assumes the licence is issued centrally. Set `LICENSE_SIGNING_MODE=issuer` only when the installation is intentionally allowed to issue its own licences and its private key is protected locally.

## Operations and monitoring

The implemented HTTP surface is:

- public bootstrap: throttled `POST /api/pos/sync/pair` and the minimal unpaired status response;
- paired local control: `GET /api/pos/sync/status` and `POST /api/pos/sync/run`, requiring the server recovery credential or any active registered terminal credential from the paired company/branch;
- terminal activation: cloud `POST /api/pos/sync/nodes/{node}/terminal-activation-codes`, local edge `POST /api/pos/sync/terminal-activations/redeem`, and signed cloud consume under `/api/pos/sync/terminal-activations/consume`;
- signed node protocol: `POST /api/pos/sync/push`, `GET /api/pos/sync/pull`, `GET /api/pos/sync/node-status`;
- authenticated company management: `GET /api/pos/sync/nodes`, `POST /api/pos/sync/pairing-codes`, and node suspend/resume/revoke actions; the rotate endpoint returns a recovery-required conflict instead of performing an unsafe one-sided rotation;
- platform administration equivalents below `/api/companies/{company}/sync/...`.

Operators can use `php artisan sync:node status|pair|run|push|pull|reissue-local-tokens`
and `php artisan sync:edge-worker --once`. Pairing and the legacy reissue command
print one-time server/terminal recovery values for the same trusted browser.
Normal additional terminals use the Back Office one-time activation flow
instead. Reissue requires `--recovery-authorized` at the local server console
and repairs lost browser credentials without exposing or replacing the cloud
credential. Production edge servers keep `sync:edge-worker` running under the
automatic `ServeNestEdgeSync` operating-system service; Laravel's scheduler and
queue are not used for this polling loop.

The sync status view reports:

- deployment profile and paired identity;
- local pairing/readiness and the masked configured cloud origin;
- separate **POS → Cloud** and **Cloud → POS** equality states;
- pending, undiscovered-sale and dead-letter outbox counts;
- a constant-size acknowledged upload event-set proof and an acknowledged download cursor/content proof;
- last push time and last pulled cursor;
- last successful synchronization time;
- last error without exposing credentials;
- the locally known node state. A direction is green only when its fresh cloud acknowledgement exactly matches local proof and no run or pending/error state exists; missing or stale proof fails closed to red. Cloud-side suspension/revocation is learned only when the node reconnects and its signed request is rejected.

Suspending or revoking a node disables future synchronization credentials; it is not an offline remote-kill switch. A disconnected POS may continue selling until its locally signed licence expires or another locally enforceable control blocks it. Use licence expiry/revocation for the commercial access boundary and reconcile any pending outbox before intentionally decommissioning a node.

Backups remain mandatory on both cloud and edge. Preserve each installation's matching `APP_KEY` with its protected recovery material: node credentials are encrypted with that local key and cannot be decrypted after restoring the database with a different one. Outbox/inbox records are audit evidence and should be retained according to the financial retention policy. Secrets, payment credentials and unnecessary personal data must not be included in diagnostic logs.

## Rollout order

1. Back up the cloud database and `APP_KEY`, then rehearse all pending migrations on a staging copy. The licensing hardening migration (`2026_10_05_120000`) must precede the asymmetric entitlement migration (`2026_10_05_140000`); this is what creates `entitlement_payload` before it is written.
2. Configure the cloud signing key, set the cloud profile, and run `php artisan migrate --force`. No migration is run automatically by this feature.
3. Re-sign legacy licences with `php artisan licenses:resign-entitlements`. Bootstrap the intended global administrator, copy that Super Admin's immutable `pos_users.id` UUID into `PLATFORM_ADMIN_POS_USER_IDS`, clear the configuration cache, and sign in again. Then verify company codes, branches, tills, support phone numbers and licence limits in Company Management. Do not use a role name alone as global trust.
4. Deploy the Back Office React profile against the cloud API. All companies use the same URL and select the tenant by company code.
5. For each branch server, install one fresh local Laravel database and Front Office React profile, configure the pinned cloud origin and public licence-verification key, then run its migrations. Point each LAN browser terminal at that server's API address. Enable NTP/automatic time synchronization on the cloud and branch server; signed node requests reject excessive clock skew and licence rollback protection depends on a trustworthy clock.
6. In cloud Back Office, select the correct branch and initial till and generate a one-time pairing code. Pair the branch server from the local setup screen or run `php artisan sync:node pair --cloud=https://management.example.com --company-code=COMPANY-CODE --pairing-code=ONE-TIME-CODE --node-name="Main Branch Server"`.
7. Confirm `php artisan sync:node status` reports the expected company and branch and wait for the initial configuration bootstrap. For each additional browser, select the active node and intended till in **Deployment & Sync**, choose **Activate Terminal**, and enter that single-use code in the browser. Confirm every terminal resolves its own till before signing in.
8. Test a completed sale, payment, discount, hospitality recipe deduction, credit sale, duplicate delivery, internet outage and recovery. Confirm cloud totals match the local branch before adding more tills.
9. Test node suspension, revocation and same-node recovery. Enable legacy domain resolution only for a controlled migration window and turn it off after every active client uses company codes or node credentials.

## Phase-one boundary

Implemented now:

- profile-based Front Office, Back Office and standalone UI/API boundaries;
- company-code login on one shared cloud URL;
- immutable company/branch server pairing, separate per-browser till activation, and signed replay-protected node traffic;
- several independently activated LAN till browsers sharing one edge API/database and one serialized branch sync cursor;
- durable upload of completed sales, lines, taxes, tenders, discounts, linked stock/cash evidence, referenced day/shift headers and credit effects;
- complete cloud-to-edge snapshots for sell-time catalogue, pricing, tax, currency, payment, user/role/shift, stock bootstrap, licence, discount and hospitality configuration;
- authoritative cloud-to-edge branch trading-day snapshots so a centrally completed or forced Day End advances the physical POS on its next pull;
- status, retry/backoff, dead-letter visibility, recovery pairing, suspension and revocation without a scheduler.

Not yet separate schema-v1 aggregates:

- voids, refunds, cancellations and later fiscal-status changes;
- shift closure, cashup changes, edge-originated Day End uploads and cash movements not attached to a sale;
- stock issues, wastage, transfers, purchase/receipt workflows and operational manufacturing batches;
- quotations, customer-master edits, local audit/configuration edits and media-file replication;
- hard online cross-node stock/promotion reservations.

Those operations remain local until their own idempotent event contracts are added. Do not advertise phase one as full bidirectional replication of every table. A controlled UUID/remapping migration is also required before converting an existing standalone database that was independently populated with different master-record IDs.

## Non-negotiable invariants

- No event may change tenant or branch based on payload input.
- No posted financial event is silently overwritten.
- Every retry is idempotent.
- Every deletion that must synchronize has a tombstone.
- Every cursor advances only after a transaction commits.
- A failed event remains visible and retryable.
- The POS can complete a sale without internet when its local service and licence are valid.
- The cloud cannot claim a branch operation was applied until it has been durably committed.

# ServeNest enterprise Hospitality architecture

ServeNest uses one application codebase in three runtime roles: the Central
Cloud Back Office, the database-owning shop Edge server, and browser-only LAN
terminals. Runtime role comes from environment configuration; tenant or brand
logic must never be forked into separate builds.

## Canonical hierarchy

Every operational record resolves through this chain:

`Country -> Company -> Brand -> Complex -> Shop -> Till`

Legacy `company_branches` remain as a compatibility and accounting boundary.
Each canonical shop owns one legacy branch link during migration. A till must
match the same company, shop, and branch; a mismatch is an activation or
configuration error, never a reason to fall back to company-wide data.

## Cloud and Edge contract

- Central Cloud owns hierarchy, catalogues, recipes, modifiers, prep messages,
  policies, roles, assignments, licenses, configuration versions, and the
  global audit trail.
- The main shop Edge owns the local operational database, serves every LAN
  browser terminal, authenticates operators offline, records sales locally,
  and synchronizes with Cloud.
- LAN terminals are browser clients. They do not own databases or run sync.
- Cloud-to-Edge configuration is allowlisted and scoped to the activated
  company, branch/shop, brand, and till. Authentication sessions, audit data,
  private signing keys, activation tokens, and arbitrary settings are not
  synchronized.
- Edge-to-Cloud operational writes remain outbox/idempotency based. A missing
  or invalid till configuration blocks the POS instead of loading an unscoped
  menu.

## Terminal bootstrap

Before operator login, an activated Edge calls
`GET /api/pos/configuration/bootstrap` with its random `X-Till-Token`. The
response contains only the resolved hierarchy, brand identity, configuration
version/ETag, effective POS authentication rules, license status, and the
authoritative visible menu for that shop. The endpoint is not a public product
catalogue and is unavailable on a Cloud-only runtime.

Menu visibility is the intersection of:

1. the active brand catalogue;
2. active channel and trading-schedule rules;
3. shop-level include/exclude overrides; and
4. active product, modifier, recipe, and prep-message dependencies.

`menu_item_channels` is the authoritative Hospitality selling-price source.
The bootstrap exposes the selected row both as channel data and as a
frontend-compatible `prices[]` entry; sale validation resolves the same row
again on the server. A row without an inclusive or exclusive amount is never
saleable. Trading schedules use the Shop timezone first, then the Country and
application timezones.

## Authentication and authorization

- Back Office accepts username plus password only.
- Front Office uses the effective Till -> Shop -> Complex -> Brand -> Company
  -> Country -> Global policy and may allow operator code, username/password,
  or both.
- Operator codes are bcrypt verifiers with a dedicated keyed blind index for
  bounded lookup. `AUTH_LOGIN_CODE_LOOKUP_KEY` must be a separate secret and
  the same key version must be securely provisioned to Cloud and authorized
  Edges. It is never a sync dataset.
- The only global cross-tenant bypass is the immutable platform-administrator
  allowlist. A tenant role merely named Super Admin is not global.
- Other access comes from effective, time-bounded role assignments at country,
  company, brand, complex, shop, or till scope.

## Hospitality catalogue import

The importer reads GAAP-style `.xlsx` workbooks without executing formulas or
macros, validates archive size/path safety, and writes a transactionally
consistent brand catalogue. It imports products, UOMs, prices/channels,
recipes and nested recipe lines, modifier groups/options/exclusions, and prep
messages. Stable external keys plus the workbook SHA make reruns idempotent.
The source channel rows remain intact. For each priced item the importer also
projects one canonical `POS` row using `INSTORE`, `TAKEAWAY`, `DINE_IN`, then
`DELIVERY` priority and records the source channel in metadata. That makes one
default terminal channel work across the Pizza Inn and Chicken Inn formats
without losing their original channel prices.

Validate first:

```powershell
php artisan hospitality:catalog-import "C:\path\catalog.xlsx" --company=<uuid> --brand=<uuid> --dry-run --json
```

Import into Central Cloud after reviewing warnings:

```powershell
php artisan hospitality:catalog-import "C:\path\catalog.xlsx" --company=<uuid> --brand=<uuid> --created-by=<user-uuid>
```

Use `--force` only to deliberately reprocess a workbook with the same hash.
Imports are blocked on Edge because catalogue authority belongs to Cloud.

## Deployment profiles

Start with `.env.example`, then apply `.env.cloud.example` or
`.env.edge.example`. Secrets and actual database credentials must be injected
outside source control. Run migrations and the importer on Cloud first, pair
the Edge till, complete a configuration sync, and verify the hierarchy/logo,
authentication policy, menu visibility, and recipe/modifier/prep data before
accepting a sale.

For the non-Nginx-changing XAMPP test layout, follow
`docs/XAMPP_CLOUD_EDGE_TEST_DEPLOYMENT.md`.

ServeNest is Hospitality-only. Legacy Retail tables and field names may remain
temporarily for migration compatibility, but Retail navigation and route
groups are not registered in the active runtime.

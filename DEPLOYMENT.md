# ServeNest Windows test deployment

## Packaged installers

For a server or shop, use the installers instead of the manual placement
below. `ServeNestApi/deployment/windows-bundle` builds
`ServeNest-BOH-Setup.exe` (Central Back Office) and `ServeNest-FOH-Setup.exe`
(shop POS server, or additional till) from this front-end's `npm run build`
output. See `ServeNestApi/deployment/windows-bundle/docs/WINDOWS_BUNDLE_README.md`.
The build must keep `"homepage": "/"`: the installers serve the SPA from the
origin root, and the bundle builder rejects any other base path.

## Project placement

Keep the cloud Back Office directly on `C:\` and the local POS under XAMPP:

- Cloud Back Office frontend: `C:\ServeNestCloudFront`
- Cloud Back Office API: `C:\ServeNestCloudApi`
- Local POS frontend: `C:\xampp\htdocs\ServeNestPOSFront`
- Local POS API: `C:\xampp\htdocs\ServeNestApi`

The two deployments share maintained source capabilities, but each is built with its own environment profile and runtime storage.

## Runtime profiles

Build two separate front-end artifacts from the same maintained source. Copy the relevant example to each build environment or set equivalent values in its deployed `runtime-config.js`:

| Installation | `deployment_mode` | `backend_mode` | API base |
| --- | --- | --- | --- |
| Central cloud simulation | `back-office` | `cloud` | same origin; API routes start with `/api` |
| Main shop POS server | `front-office` | `edge` | same origin; API routes start with `/api` |
| LAN browser terminal | `front-office` | `edge` | `http://<shop-pos-ip>:8083` |

`combined` is not a supported deployment. A Back Office build opens directly to Back Office authentication; a Front Office build opens directly to POS authentication/setup. There is no workspace selection screen.

The POS server is the only shop machine that owns the edge database and cloud synchronization worker. Other shop terminals use the POS server through the LAN and must not be configured with their own databases.

## Existing Nginx constraint

The Nginx configuration was inspected read-only and was not changed. ServeNest builds now use the origin root (`/`) and Laravel `/api/` routes. The cloud and edge server mappings must each point their root SPA fallback to their corresponding ServeNest build directory.

Therefore, while the existing Nginx configuration remains unchanged:

1. Build in the relevant cloud or XAMPP project folder.
2. Publish only the generated `build` contents to the already configured static root, or have an administrator create a directory link from that static root to the XAMPP-hosted build.
3. Do not copy source code, `.env` files, database files or secrets into a public static root.

Changing Nginx to point directly at the new project folders requires separate authorization and is intentionally outside this implementation.

## Build and smoke test

Run in each front-end project folder:

```powershell
npm ci
npm test -- --watchAll=false
npm run build
```

For the cloud instance, verify that only Back Office is offered and that a platform administrator can open the separate Country, Company, Brand, Complex, Shop, and Till management screens. For the edge instance, pair/activate the till and verify the downloaded country → company → brand plus company-owned complex → shop → till identity, tenant brand logo, configured POS login policy, and shop-scoped menu before taking a sale.

If configuration bootstrap fails, the POS must remain unavailable or show the sync/setup state; it must not silently load an unscoped company-wide menu.

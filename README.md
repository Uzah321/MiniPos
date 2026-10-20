# ServeNest Enterprise Hospitality POS

ServeNest is a React front end shared by two deployment profiles:

- **Central Back Office** (`back-office` / `cloud`) manages companies, countries, brands, complexes, shops, tills, roles, hospitality menus and recipes.
- **Shop POS** (`front-office` / `edge`) runs against the shop's local API/database, accepts LAN browser terminals, and synchronizes centrally provisioned configuration and transactions with the cloud.

Retail mode is not part of the supported product. Runtime behavior is hospitality-only. The application is served from `/`. New installations use `window.__SERVENEST_RUNTIME_CONFIG__`; legacy `salexa_*` browser storage keys and the former runtime global are read only as migration fallbacks and are never displayed to users.

## Development

```powershell
npm install
npm start
```

The API URL is resolved in this order: `public/runtime-config.js`, `REACT_APP_API_BASE_URL`, then the current browser origin. Requests already include their `/api/...` path, matching the existing Nginx hosts.

## Build and verification

```powershell
npm test -- --watchAll=false
npm run build
```

Use `.env.back-office.example` for a central build and `.env.front-office.example` for an edge build. A compiled bundle can also be reused between environments by changing `public/runtime-config.js` at installation time. Never place passwords, till credentials, pairing codes or synchronization secrets in that browser-visible file.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the current Windows/XAMPP/Nginx placement and verification checklist.

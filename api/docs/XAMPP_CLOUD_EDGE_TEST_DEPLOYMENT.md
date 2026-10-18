# ServeNest Cloud and XAMPP POS test layout

Use the same API release in two physically separate project folders. The cloud
Back Office instance lives directly on `C:\`; only the shop POS instance lives
under XAMPP:

```text
C:\ServeNestCloudApi\api
C:\ServeNestCloudFront
C:\xampp\htdocs\ServeNestApi\api
C:\xampp\htdocs\ServeNestPOSFront
```

Do not point both API copies at the same database or reuse their runtime
storage directories. Copy `.env.example` into each API copy, apply the matching
`.env.cloud.example` or `.env.edge.example` values, and generate an independent
`APP_KEY`. The authentication lookup key id/value is the deliberate exception:
provision the same dedicated value to Cloud and authorized Edges through the
deployment secret store. The Edge receives only the license public key.

## Local start and verification

Until an operator maps these folders through the existing reverse proxy, run
the APIs directly from their `api` directories in separate terminals:

```powershell
# Cloud API
php artisan migrate --force
php artisan serve --host=127.0.0.1 --port=8002

# Shop Edge API
php artisan migrate --force
php artisan serve --host=127.0.0.1 --port=8003
```

Run the Cloud queue/scheduler and the Edge sync worker in their own terminals
for an end-to-end test:

```powershell
php artisan queue:work
php artisan schedule:work
php artisan sync:edge-worker
```

Build each frontend in its own folder with its API base URL set to the
corresponding API port. The cloud build is Back Office only and the XAMPP build
is Front Office POS only; neither deployment presents a workspace chooser.
Pair a Till, complete the initial snapshot, and
verify `GET /api/pos/configuration/bootstrap` with its `X-Till-Token` before a
cashier signs in.

## Existing Nginx constraint

The machine's current Nginx hosts point at legacy `salexa`/`salexaapi` paths,
not the ServeNest folders above. This implementation does not edit those
configs. The direct ports are therefore the supported local test route until
an operator authorizes a reverse-proxy mapping or a reviewed filesystem
junction. Production `APP_URL` and the canonical ServeNest cloud URL setting
must then be
replaced with the final TLS hostnames; do not permit unpinned or insecure Cloud
URLs in production.

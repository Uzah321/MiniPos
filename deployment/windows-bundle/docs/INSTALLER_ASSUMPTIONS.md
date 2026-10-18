# Installer design contracts

Rules the scripts rely on. `tests/Unit/WindowsBundleAssetLayoutTest.php`
in the API checks the important ones.

- **Two editions, one script set.** `ServeNest-FOH.iss` and `ServeNest-BOH.iss`
  share `include\*` and `scripts\*`. Profiles: `edge` and `till` (FOH),
  `cloud` (BOH). `ServeNest.Common.ps1` (`Get-ServeNestLayout`) is the only
  place that maps an edition to service names, runtime folders and default ports.
- **No cross-edition sharing.** FOH and BOH never share a service name, port,
  runtime folder or database, so both can run on one test PC.
- **Root SPA.** The web build is compiled for `/`. nginx serves it from the
  origin root, with `/api/` and `/up` sent to Laravel. `/assets/` is the
  upload alias (`root\assets`); the SPA's own images use `/servenest-static/`.
  The builder and health check reject a build for another base path.
- **HTTPS-only sync.** The FOH `.env` always has
  `SERVENEST_SYNC_ALLOW_INSECURE_HTTP=false` and `SERVENEST_SYNC_VERIFY_TLS=true`.
  The Back Office URL must be `https://`. A private or self-signed Back Office
  certificate reaches shops only through `SERVENEST_SYNC_CA_BUNDLE`
  (`config\back-office-ca.pem`), never by turning verification off.
- **Shop connection file** (`.servenest-foh`, schema 1): Back Office URL,
  optional trust PEM, licence algorithm, key ID and public key, and the
  login-code lookup key ID and key. It is the only way a shop receives the
  lookup key, which must match the Back Office for offline PIN sign-in. It
  never contains the licence private key.
- **Issuer key stays on BOH.** `LICENSE_SIGNATURE_PRIVATE_KEY` is written only
  for the `cloud` profile. `Configure-ServeNestEnvironment.ps1` refuses it for
  `edge`, and the installer refuses PEM key text inside its JSON configuration.
- **First platform administrator.** BOH generates the user UUID, writes it to
  `PLATFORM_ADMIN_POS_USER_IDS`, then runs `servenest:bootstrap-platform-admin`
  with an Administrators/SYSTEM-only JSON file, which is wiped after use. The
  password is never on a command line or in a log. Until an installation has
  completed (recorded `config\installation.json`), the command runs with
  `--ensure` and reuses the UUID already in `.env`, so a retried first
  install creates the administrator or sets the same one again. After that,
  a repair with a new password uses `--rotate-existing`.
- **Repairs preserve identity.** `APP_KEY`, database credentials, licence keys,
  the lookup key and the platform administrator survive a repair. A profile
  or edition change in place is refused. Salexa 4.x folders, source checkouts,
  `apps\api` layouts and non-empty unknown folders are refused.
- **Ownership before change.** A service is stopped, updated or removed only
  when it is registered through this edition's NSSM, runs this edition's
  executable and is bound to this folder (`AppDirectory`, or nginx's `-c`
  path). The database service must run this folder's `mysqld.exe` with its
  `config\database.ini`.
- **NSSM output is UTF-16.** Always read `nssm get` through `Invoke-NssmGet`
  (raw bytes, decoded), never `& nssm get` captured as text. Text capture
  embeds NUL characters and breaks path handling.
- **Locked folders.** Setup locks the install folder and runtime folders to
  Administrators/SYSTEM before extracting. `Install-ServeNest.ps1` then resets
  every descendant to that ACL.
- **PHP isolation.** Every PHP process uses the edition's own `php.ini`
  (`PHPRC`, empty `PHP_INI_SCAN_DIR`), never a WinGet or XAMPP PHP. `memory_limit=1G`
  covers catalogue snapshots, and `max_execution_time=300` covers a shop Day End
  (nginx waits 310 s on the Back Office).
- **PHP worker pool.** php-cgi serves one request at a time on Windows, so
  FOH runs 2 workers and BOH runs 4 behind an nginx `least_conn` upstream.
- **Scheduler on BOH only.** `schedule:work` runs the inventory variance scan
  queue. Shops get their configuration from the Back Office and are not seeded.
- **Firewall.** FOH opens its port to the local subnet on the Domain and
  Private profiles. BOH opens its port on Domain and Private for any address,
  because shops connect from their own networks. phpMyAdmin and the database
  stay on loopback.
- **Data is never deleted** by Setup, repair or uninstall.
- **Out of scope:** printer drivers, QZ Tray trust, ZIMRA certificates,
  Android FOH provisioning, local Back Office hubs, and migrating data from Salexa 4.x.

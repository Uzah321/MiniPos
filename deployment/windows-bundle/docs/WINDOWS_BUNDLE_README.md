# ServeNest Windows installers

Two installers are built from this folder:

| Installer | Installs | Services | Default folder |
| --- | --- | --- | --- |
| `ServeNest-BOH-Setup-<version>.exe` | **Central Back Office**: companies, brands, shops, tills, menus, licence issuer, and the HTTPS endpoint shop POS servers pair and sync with | `ServeNestBOHDatabase`, `ServeNestBOHPHP1`-`4`, `ServeNestBOHQueue`, `ServeNestBOHScheduler`, `ServeNestBOHNginx` | `C:\ServeNest\BOH` |
| `ServeNest-FOH-Setup-<version>.exe` | **Shop POS server**: the shop database, API, Back Office sync and Front Office for every till in the shop | `ServeNestFOHDatabase`, `ServeNestFOHPHP1`-`2`, `ServeNestFOHQueue`, `ServeNestFOHSync`, `ServeNestFOHNginx` | `C:\ServeNest\FOH` |
| (same FOH installer) | **Additional till**: an Edge app shortcut to the shop POS server; nothing else | none | `C:\ServeNest\Till` |

Each edition has its own service names, ports and runtime folders, so a Back
Office and a shop POS server can share one test PC.

| | FOH (shop POS server) | BOH (Back Office) |
| --- | --- | --- |
| Browser address | `http://<server>:8083/` | `https://<server>:8082/` |
| Database (loopback only) | `127.0.0.1:3307`, `servenest_foh` | `127.0.0.1:3308`, `servenest_boh` |
| PHP FastCGI workers (loopback) | 9301-9302 | 9401-9404 |
| phpMyAdmin (this computer only) | `http://127.0.0.1:8081/` | `http://127.0.0.1:8084/` |
| Runtimes | `C:\nginx\ServeNestFOH`, `C:\nssm\ServeNestFOH`, `C:\php\ServeNestFOH`, `C:\phpMyAdmin\ServeNestFOH` | the same with `ServeNestBOH` |

The web build is served from the origin root (`/`), and `/api/` goes to Laravel.

## Site setup order

1. **Install the Back Office** (`ServeNest-BOH-Setup.exe`) on the central server.
   - Enter the address shops will use (a DNS name or fixed IP) and the HTTPS port.
   - Choose how HTTPS is provided (see below).
   - Enter the operator company and the first **platform administrator**.
     The password needs 12+ characters with upper-case, lower-case, number
     and symbol characters.
   - Leave **Licence signing key** empty for a new Back Office: Setup
     creates one. Select an existing key only when moving a Central Back
     Office, so existing licences stay valid.
   - Setup saves a **shop connection file** (`ServeNest-Shop-Connection.servenest-foh`,
     on your desktop by default). A master copy stays in `C:\ServeNest\BOH\secrets`.
2. **Sign in to the Back Office** as the platform administrator. Create the
   company, brands, complexes, shops and tills, and issue the licence.
3. **Install each shop POS server** (`ServeNest-FOH-Setup.exe` → Shop POS
   server). Select the shop connection file when asked. Use a fixed address
   for the server, because tills connect to it.
4. In the Back Office, issue a **pairing code** for that shop (Deployment &
   Sync). Open **ServeNest POS** on the shop server and enter the code.
5. **Activate each till.** On other till PCs, run the FOH installer, choose
   *Additional till* and enter the server address shown at the end of step 3.
6. Make a test sale and a menu change, and confirm both sync directions
   before you take live orders.

Delete every copy of the shop connection file when the shops are installed.
It contains the shared login-code key, the same secret stored in each shop's `.env`.

## HTTPS for the Back Office

In production, shop POS servers synchronize **only over HTTPS with certificate
verification**. Plain HTTP is refused unless the API runs in a local or testing
environment, so an HTTP-only Back Office would install but no shop could pair.
The BOH installer offers three modes:

| Mode | Use when | What shops trust |
| --- | --- | --- |
| Self-signed (default) | A private network or VPN, or testing | Setup creates a certificate for the address (plus the computer name, `localhost`, `127.0.0.1`). The shop connection file pins it, and shops verify it exactly. Setup can also trust it on the Back Office PC for its own browser; other admin PCs show a browser warning until they trust `config\tls\back-office-cert.pem`. |
| Your certificate | You have a certificate for the address | Your PEM certificate and its unencrypted key. Add the CA certificate only when it is not a public authority; the connection file then carries it. |
| Reverse proxy | A proxy or load balancer terminates TLS | The proxy's public HTTPS address. The Back Office listens on plain HTTP on its port, so only the proxy may reach that port. |

A repair keeps the self-signed certificate while it has more than 30 days left
and the address hasn't changed. Otherwise it creates a new one. After a new
certificate, copy the new shop connection file to each shop and repair FOH
(leave the database settings as they are).

## Building the installers

Build on a Windows PC with Inno Setup 6, Composer and these inputs:

```powershell
Set-Location C:\Users\<you>\Desktop\ServeNest\ServeNestApi\deployment\windows-bundle

# 1. Compile the web build for the origin root
#    (in ServeNestPOSFront: npm ci; npm run build)

# 2. Build both installers
.\Build-WindowsBundle.ps1 `
  -NginxRuntimeSource    C:\packages\nginx `
  -PhpRuntimeSource      C:\packages\php `
  -NssmRuntimeSource     C:\packages\nssm `
  -DatabaseRuntimeSource C:\packages\mariadb `
  -PhpMyAdminSource      C:\packages\phpMyAdmin `
  -CompileInstaller
```

- `-ApiSource` defaults to `..\..\api`, and `-WebBuildSource` defaults to
  `..\..\..\ServeNestPOSFront\build`.
- `-Edition FOH|BOH|Both` chooses which installers to compile. The version
  comes from the `VERSION` file unless you pass `-Version`.
- The PHP runtime must have matching `php.exe`, `php-cgi.exe` and `ext\*.dll`
  (all TS or all NTS) for every extension in `templates\php\php.ini.template`.
  The builder checks this before it stages anything, and again on the staged copy.
- PHP's trusted CA list comes from `-CaBundlePath`. Without it, the builder
  uses PHP's own `extras\ssl\cacert.pem`, then Git for Windows' bundle.
- `composer install --no-dev` runs on the staged API using the **bundled** PHP,
  so platform checks match the customer runtime.
- The builder refuses `.env` files, databases, logs, uploads, generated
  receipts, ZIMRA device certificates, QZ keys, private keys and
  `.servenest-foh` files. phpMyAdmin is staged without `config.inc.php`,
  `setup`, `tmp`, `examples` or `doc`. Source maps are not shipped.
- Output: `build\output\ServeNest-FOH-Setup-<version>.exe` and
  `ServeNest-BOH-Setup-<version>.exe`, plus `build\stage\SHA256SUMS.txt`.

The portable runtimes from a Salexa 4.0.8 PC can be used as build inputs.
They live in `C:\nginx\SalexaPOS`, `C:\nssm\SalexaPOS`, `C:\php\SalexaPOS` and
`C:\phpMyAdmin\SalexaPOS`, and in `runtime\database` under the old install.
Run the build from an **elevated** PowerShell, because those folders are
restricted to Administrators. The builder drops their generated `php.ini` and
`config.inc.php`.

Sign both setup files with an Authenticode certificate (`signtool`) before
giving them to customers. Unsigned builds are for controlled testing only.

## What Setup does

The wizard collects site values and writes them to a protected temporary JSON
file. It then runs `installer\scripts\Install-ServeNest.ps1`, which:

1. validates every input before it touches a running installation;
2. creates or repairs the loopback-only MariaDB service and a
   least-privilege application account;
3. writes `.env` (keeping `APP_KEY` on a repair) and the nginx, PHP, phpMyAdmin
   and `runtime-config.js` files;
4. runs migrations. On the Back Office it also creates the first platform
   administrator (`servenest:bootstrap-platform-admin`) and seeds reference data;
5. installs and starts the services, opens the firewall port and runs the
   health check (`Test-ServeNestInstallation.ps1`), which loads the SPA bundle,
   `runtime-config.js`, `/up`, the database state and phpMyAdmin.

A repair first stops this installation's own services (after checking they
belong to this folder), because running services lock the executables Setup replaces.

## After installation

From an elevated PowerShell:

```powershell
& C:\ServeNest\FOH\installer\scripts\Test-ServeNestInstallation.ps1
& C:\ServeNest\BOH\installer\scripts\Test-ServeNestInstallation.ps1
Get-Service ServeNest* | Select-Object Name, Status, StartType
```

Back up these folders regularly:

- `secrets\`: database root password, licence signing key (BOH), TLS key and
  shop connection file.
- `data\database`: stop the database service first, or use `mariabackup` or `mysqldump`.
- `assets\` (uploaded images) and `api\assets\` (generated receipts and labels).
- `api\.env`.

Use BitLocker on servers. A file overwrite can't guarantee erasure on SSDs.

## When Setup fails

Setup shows the reason and leaves the installed files in place. Read
`logs\installer\last-install-error.log` and the newest `install-*.log`, plus
`migration-error-*.log` if migrations failed. Fix the cause, then **run the
same installer again with the same folder and settings**. Don't delete the
database or the `secrets` folder to clear an error. The protected bootstrap
record keeps the generated database passwords stable across retries, and a
repair resumes from where it stopped.

A forgotten platform administrator password can be reset by running the BOH
installer again. Enter the administrator's name, username and a new password on
the *Platform administrator* page.

## Uninstall

Windows *Installed apps* → *ServeNest Back Office* or *ServeNest Front of House*.
The uninstaller removes this installation's services, firewall rule, locally
trusted certificate and program files. It **keeps** `data`, `secrets`, `.env`,
`assets`, logs and backups. Delete those only after you have verified a backup.

## Moving from Salexa POS 4.0.8

Salexa 4.0.8 and ServeNest use different service names, runtime folders and
database services. ServeNest Setup refuses to install into a Salexa folder,
because it would otherwise register a second database service over the same
data. Install ServeNest into a new folder and migrate data separately.

When the old installation is no longer needed, remove its services from an
elevated PowerShell. Data under `C:\xampp\htdocs\salexaapi` is kept:

```powershell
& C:\xampp\htdocs\salexaapi\installer\scripts\Uninstall-SalexaServices.ps1 `
  -InstallRoot C:\xampp\htdocs\salexaapi -Profile edge
```

Note that the 4.0.8 uninstall script has the same NSSM output bug that broke
its repairs. If it fails with "Illegal characters in path", stop and delete
the services directly:

```powershell
'SalexaPOSNginx','SalexaPOSEdgeSync','SalexaPOSLocalQueue','SalexaPOSEdgePHP' |
  ForEach-Object { Stop-Service $_ -ErrorAction SilentlyContinue; sc.exe delete $_ }
Stop-Service SalexaPOSDatabase; sc.exe delete SalexaPOSDatabase
```

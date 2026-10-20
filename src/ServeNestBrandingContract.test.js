import { existsSync, readFileSync } from "fs";
import path from "path";

const projectRoot = path.resolve(__dirname, "..");
const readProjectFile = (relativePath) => readFileSync(
  path.join(projectRoot, relativePath),
  "utf8",
);

describe("ServeNest deployment identity", () => {
  test("publishes the application at the origin root with only ServeNest icons", () => {
    const packageJson = JSON.parse(readProjectFile("package.json"));
    const manifest = JSON.parse(readProjectFile("public/manifest.json"));
    const indexHtml = readProjectFile("public/index.html");

    expect(packageJson.name).toBe("servenest-pos");
    expect(packageJson.homepage).toBe("/");
    expect(manifest.name).toBe("ServeNest Hospitality");
    expect(manifest.start_url).toBe("/");
    expect(manifest.scope).toBe("/");
    expect(manifest.icons.map((icon) => icon.src)).toEqual([
      "favicon.svg",
      "servenest-static/servenest-logo.svg",
    ]);
    expect(indexHtml).toContain("ServeNest");
    expect(indexHtml).not.toContain("Enterprise");
    expect(indexHtml).toContain("%PUBLIC_URL%/favicon.svg");
    expect(indexHtml).not.toMatch(/salexa|favicon\.ico|logo192|logo512/i);
    expect(existsSync(path.join(projectRoot, "public/servenest-static/servenest-logo.svg"))).toBe(true);
    expect(existsSync(path.join(projectRoot, "src/assets/salexaLogo.png"))).toBe(false);
    expect(existsSync(path.join(projectRoot, "src/logo.svg"))).toBe(false);
  });

  test("routes the root directly to the deployment login without a chooser or legacy basename", () => {
    const appSource = readProjectFile("src/App.js");
    const loginSource = readProjectFile("src/backOffice/login/AdminLogin.js");

    expect(appSource).toContain('<AdminLogin workspace="front-office" />');
    expect(appSource).toContain('<AdminLogin workspace="back-office" />');
    expect(appSource).toContain('"ServeNest Hospitality POS"');
    expect(appSource).toContain('"ServeNest Hospitality Back Office"');
    expect(appSource).toContain('saveCompanyCode("")');
    expect(appSource).not.toMatch(/WorkspaceGateway|basename\s*=|\/salexa/i);
    expect(loginSource).toContain("servenest-login-shell");
    expect(loginSource).not.toMatch(/salexa-v4-login|salexa-workspace-cinematic|workspace chooser|press\s+F[12]/i);
    expect(existsSync(path.join(projectRoot, "src/workspaces/WorkspaceGateway.js"))).toBe(false);
  });

  test("uses the ServeNest runtime object as canonical installation config", () => {
    const runtimeSource = readProjectFile("public/runtime-config.js");

    expect(runtimeSource).toContain("window.__SERVENEST_RUNTIME_CONFIG__ = Object.assign");
    expect(runtimeSource.indexOf("window.__SERVENEST_RUNTIME_CONFIG__ = Object.assign"))
      .toBeLessThan(runtimeSource.lastIndexOf("window.__SALEXA_RUNTIME_CONFIG__"));
  });

  test("does not expose legacy sync or API installation names", () => {
    const pairingSource = readProjectFile("src/components/SyncPairingForm.js");
    const apiConfigSource = readProjectFile("src/utils/apiConfig.js");
    const productImportSource = readProjectFile("src/services/ProductImportService.js");

    expect(pairingSource).toContain("SERVENEST_SYNC_CLOUD_URL");
    expect(pairingSource).not.toContain("SALEXA_SYNC_CLOUD_URL");
    expect(apiConfigSource).not.toMatch(/\/salexaapi/i);
    expect(productImportSource).toContain("servenest_product_import_template.xlsx");
    expect(productImportSource).not.toContain("salexa_product_import_template");
  });

  test("keeps cloud and shop builds on their dedicated local API profiles", () => {
    expect(readProjectFile(".env.front-office.example"))
      .toContain("REACT_APP_API_BASE_URL=http://127.0.0.1:8003");
    expect(readProjectFile(".env.back-office.example"))
      .toContain("REACT_APP_API_BASE_URL=http://127.0.0.1:8002");
    expect(readProjectFile(".env.production.local"))
      .toContain("REACT_APP_API_BASE_URL=http://127.0.0.1:8003");
  });
});

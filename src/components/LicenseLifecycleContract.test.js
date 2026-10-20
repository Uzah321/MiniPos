import fs from "fs";
import path from "path";

const read = (relativePath) =>
  fs.readFileSync(path.resolve(__dirname, relativePath), "utf8");

describe("license lifecycle integration contract", () => {
  test("mounts the enforcement gate and checks on heartbeat/focus", () => {
    const app = read("../App.js");
    const gate = read("./LicenseEnforcementGate.js");

    expect(app).toContain("<LicenseEnforcementGate>");
    expect(gate).toContain("60 * 1000");
    expect(gate).toContain('window.addEventListener("focus"');
    expect(gate).toContain('document.addEventListener("visibilitychange"');
  });

  test("uses the short key activation contract", () => {
    const login = read("../backOffice/login/AdminLogin.js");
    const licenseModal = read("../backOffice/pages/admin/CompanyLicenseModal.js");

    expect(login).toContain("license_key: normalizeLicenseKey(licenseKey)");
    expect(login).toContain("maxLength={LICENSE_KEY_FORMATTED_LENGTH}");
    expect(login).toContain('placeholder="XXXX-XXXX-XXXX-XXXX"');
    expect(login).not.toContain("license_token:");
    expect(licenseModal).toContain("license_key: normalizeLicenseKey(licenseKey)");
    expect(licenseModal).toContain('placeholder="XXXX-XXXX-XXXX-XXXX"');
    expect(licenseModal).not.toContain("license_token:");
  });

  test("recognizes structured expiry and clears non-super sessions", () => {
    const api = read("../utils/apiBack.js");

    expect(api).toContain("if (!LICENSE_FAILURE_CODES.has(code))");
    expect(api).toContain('"LICENSE_EXPIRED"');
    expect(api).toContain("isSuperAdministrator(parseStoredUser(userKey))");
    expect(api).toContain("LICENSE_LOGOUT_STORAGE_KEY");
    expect(api).toContain("licenseFailure && !loginRequest");
  });
});

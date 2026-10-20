import fs from "fs";
import path from "path";
import {
  backOfficeCompanyLocation,
  backOfficeCompanyName,
  extractBackOfficeCompanyChoices,
} from "./backOfficeLoginCompanies";

const loginSource = fs.readFileSync(path.join(__dirname, "AdminLogin.js"), "utf8");

describe("Back Office login platform identity", () => {
  test("stores the authoritative top-level platform decision with the logged-in user", () => {
    expect(loginSource).toContain(
      'import { mergePlatformAdministratorIdentity } from "../../utils/workspaceAccess"',
    );
    expect(loginSource).toContain("...mergePlatformAdministratorIdentity(loggedUser, data)");
  });

  test("keeps Back Office password-only and stores the target workspace context", () => {
    expect(loginSource).toContain('const isCodeLogin = isFrontOffice && loginMode === "code"');
    expect(loginSource).toContain('const workspaceContext = data?.workspace_context || {}');
    expect(loginSource).toContain('selectedCompanyId: authenticatedCompanyId');
    expect(loginSource).toContain('selectedCompany: loginCompany');
    expect(loginSource).toContain('selectedBranchId: authenticatedBranchId');
    expect(loginSource).toContain('aria-label="Back Office credentials"');
    expect(loginSource).not.toContain("Company Code");
  });

  test("only lets POS operators choose a method when the shop policy is both", () => {
    expect(loginSource).toContain('const loginMethodCanSwitch = isFrontOffice && loginModePolicy === "both"');
    expect(loginSource).toContain('if (!loginMethodCanSwitch || !["code", "password"].includes(mode)) return');
    expect(loginSource).toContain('aria-label="Allowed POS login methods"');
    expect(loginSource).toContain('aria-label="Open Back Office sign in"');
  });

  test("uses the compact Back Office landing and opens a separate credential modal", () => {
    expect(loginSource).toContain("const [backOfficeLoginOpen, setBackOfficeLoginOpen] = useState(false)");
    expect(loginSource).toContain('aria-label="Enter Back Office"');
    expect(loginSource).toContain('role="dialog"');
    expect(loginSource).toContain('onClick={openBackOfficeLogin}');
    expect(loginSource).toContain('openKeyboardFor(usernameRef.current, "Username")');
    expect(loginSource).not.toContain("Command every operation from one secure Back Office");
    expect(loginSource).not.toContain("Enterprise hospitality");
    expect(loginSource).not.toContain("ServeNest Enterprise Hospitality");
  });

  test("guards the login request synchronously against duplicate submit events", () => {
    expect(loginSource).toContain("const loginInFlightRef = useRef(false)");
    expect(loginSource).toContain("if (loginInFlightRef.current) return");
    expect(loginSource).toContain("loginInFlightRef.current = true");
    expect(loginSource).toContain("loginInFlightRef.current = false");
  });

  test("preserves policy-allowed alphanumeric login codes when submitting", () => {
    expect(loginSource).toContain("login_code: sanitizeLoginCode(formData.login_code)");
    expect(loginSource).toContain("numericOnly={loginCodeNumericOnly}");
    expect(loginSource).toContain('[..."ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"]');
    expect(loginSource).toContain("const canEnter = !disabled && !enterDisabled");
    expect(loginSource).not.toContain('String(value || "").length === 4');
    expect(loginSource).not.toContain(
      'login_code: String(formData.login_code || "").replace(/\\D/g, "")',
    );
  });

  test("uses the short-lived platform challenge without retaining credentials", () => {
    expect(loginSource).toContain("data?.company_selection_required");
    expect(loginSource).toContain('setCompanySelectionToken(String(data?.selection_token || ""))');
    expect(loginSource).toContain('setFormData((current) => ({ ...current, password: "" }))');
    expect(loginSource).toContain("selectBackOfficeCompany({");
    expect(loginSource).toContain("companyId: company.id");
    expect(loginSource).toContain('src="/servenest-static/servenest-logo.svg"');
    expect(loginSource).toContain("servenest-backoffice-login.png");
  });

  test("normalizes safe company chooser labels without depending on tenant codes", () => {
    const companies = extractBackOfficeCompanyChoices({
      companies: [{ id: "company-1", company_name: "ServeNest Foods", city: "Harare", country: { name: "Zimbabwe" } }],
    });

    expect(companies).toHaveLength(1);
    expect(backOfficeCompanyName(companies[0])).toBe("ServeNest Foods");
    expect(backOfficeCompanyLocation(companies[0])).toBe("Harare · Zimbabwe");
    expect(Object.keys(companies[0])).not.toContain("company_code");
  });
});

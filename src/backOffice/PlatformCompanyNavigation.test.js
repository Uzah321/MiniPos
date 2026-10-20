import fs from "fs";
import path from "path";

const adminLayout = fs.readFileSync(path.join(__dirname, "AdminLayOut.js"), "utf8");

describe("platform company navigation", () => {
  test("places each structure manager in the shared Store Configuration menu", () => {
    const storeStart = adminLayout.indexOf('name: "Store Configuration"');
    const peopleStart = adminLayout.indexOf('name: "People & Security"', storeStart);
    const storeNavigation = adminLayout.slice(storeStart, peopleStart);

    expect(storeNavigation).toContain('name: "Company Management"');
    expect(storeNavigation).toContain('modal: "company-management"');
    expect(storeNavigation).toContain('name: "Country Management"');
    expect(storeNavigation).toContain('modal: "country-management"');
    expect(storeNavigation).toContain('name: "Brand Management"');
    expect(storeNavigation).toContain('name: "Complex Management"');
    expect(storeNavigation).toContain('name: "Shop Management"');
    expect(storeNavigation).toContain('modal: "platform-till-management"');
    expect(storeNavigation).not.toContain('name: "Shop Settings"');
    expect(storeNavigation).not.toContain('name: "Till Settings"');
    expect(storeNavigation).not.toContain('name: "General Settings"');
    expect(storeNavigation).toContain('platformAdminAllowed: true');
    expect(storeNavigation).not.toContain('name: "Enterprise Hierarchy"');
    expect(storeNavigation).toContain("platformAdminOnly: true");
    expect(adminLayout).toContain('"Store Configuration",');
    expect(adminLayout).toContain("...sharedAdministrationNavigation");
    expect(adminLayout).not.toContain("onOpenTillSettings=");
  });

  test("keeps the brand import in menu management instead of Store Configuration", () => {
    const menuStart = adminLayout.indexOf('name: "Menu Items"');
    const menuEnd = adminLayout.indexOf('name: "Reports"', menuStart);
    const menuNavigation = adminLayout.slice(menuStart, menuEnd);
    const storeStart = adminLayout.indexOf('name: "Store Configuration"');
    const storeEnd = adminLayout.indexOf('name: "People & Security"', storeStart);
    const storeNavigation = adminLayout.slice(storeStart, storeEnd);

    expect(menuNavigation).toContain('name: "Brand Menu Import"');
    expect(menuNavigation).toContain('modal: "product-import"');
    expect(menuNavigation).toContain('permission: "import_products"');
    expect(menuNavigation).not.toContain('permission: ["import_products", "product_management"]');
    expect(storeNavigation).not.toContain('modal: "product-import"');
  });

  test("filters and rechecks platform-only items before opening them", () => {
    expect(adminLayout).toContain("isPlatformAdministrator,");
    expect(adminLayout).toContain("const userIsSuperAdmin = isSuperAdministrator");
    expect(adminLayout).toContain("const userIsPlatformAdmin = isPlatformAdministrator");
    expect(adminLayout).toContain("item.platformAdminOnly && !userIsPlatformAdmin(user)");
    expect(adminLayout).toContain("!child?.platformAdminOnly || userIsPlatformAdmin(user)");
    expect(adminLayout).toContain("if (!userIsPlatformAdmin(user))");
    expect(adminLayout).toContain('denyPermission("Company Management")');
    expect(adminLayout).toContain("<PlatformCompanyManagementModal");
    expect(adminLayout).toContain("isPlatformAdmin={userIsPlatformAdmin(user)}");
  });

  test("company-specific modals use the selected workspace company", () => {
    expect(adminLayout).toContain("const activeCompanyId =");
    expect(adminLayout).toContain("user?.selectedCompanyId");
    expect(adminLayout).toContain("workspaceCompany || user?.selectedCompany || user?.company");
    expect(adminLayout).toContain("activeCompany?.id || user?.selectedCompanyId");
    expect(adminLayout.match(/companyId=\{activeCompanyId\}/g)).toHaveLength(2);
  });

  test("an unscoped platform-owner session exposes separate structure managers", () => {
    expect(adminLayout).toContain("const platformOnly = isUnscopedPlatformAdministrator(user)");
    expect(adminLayout).toContain("const navigation = platformOnly");
    expect(adminLayout).toContain('name: "Company Management"');
    expect(adminLayout).toContain('name: "Country Management"');
    expect(adminLayout).toContain('name: "Brand Management"');
    expect(adminLayout).toContain('name: "Complex Management"');
    expect(adminLayout).toContain('name: "Shop Management"');
    expect(adminLayout).toContain('name: "Till Management"');
    expect(adminLayout).toContain("Platform management is available in this session");
    expect(adminLayout).toContain("Choose a management area from the left navigation");
    expect(adminLayout).not.toContain('modal: "enterprise-hierarchy"');
    expect(adminLayout).toContain("platformOnly={platformOnly}");
  });

  test("shows the selected company in the central Back Office chrome", () => {
    expect(adminLayout).toContain("const activeCompanyName = platformOnly");
    expect(adminLayout).toContain("COMPANY: {activeCompanyName}");
    expect(adminLayout).toContain("{activeCompanyName}</div>");
  });
});

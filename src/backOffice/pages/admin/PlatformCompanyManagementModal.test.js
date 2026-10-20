import React from "react";
import { render } from "@testing-library/react";
import PlatformCompanyManagementModal, {
  companyCodeForDisplay,
  companyCountryForDisplay,
  buildCompanyRequestPayload,
  extractCompanyCountries,
  extractPlatformCompanies,
  extractPlatformLicenses,
  formatCompactLicenseKey,
  PLATFORM_LICENSE_TYPES,
} from "./PlatformCompanyManagementModal";

describe("platform company management helpers", () => {
  test("formats activation keys as four uppercase groups", () => {
    expect(formatCompactLicenseKey("ab12 cd34-ef56_gh78-more")).toBe("AB12-CD34-EF56-GH78");
    expect(formatCompactLicenseKey("abcd")).toBe("ABCD");
  });

  test("offers only license types accepted by the platform API", () => {
    expect(PLATFORM_LICENSE_TYPES).toEqual(["trial", "standard", "enterprise"]);
    expect(PLATFORM_LICENSE_TYPES).not.toContain("professional");
  });

  test("normalizes paginated and direct company responses", () => {
    expect(extractPlatformCompanies({ companies: { data: [{ id: 1 }] } })).toEqual([{ id: 1 }]);
    expect(extractPlatformCompanies({ data: { companies: [{ id: 2 }] } })).toEqual([{ id: 2 }]);
  });

  test("normalizes the country register used by the company selector", () => {
    expect(extractCompanyCountries({ data: { data: [{ id: "zw", name: "Zimbabwe" }] } }))
      .toEqual([{ id: "zw", name: "Zimbabwe" }]);
  });

  test("builds multipart company requests when a real logo file is selected", () => {
    const logo = new File(["logo"], "brand.png", { type: "image/png" });
    const payload = buildCompanyRequestPayload({
      company: { company_name: "Simbisa", country_id: "zw", logo: null },
      logoFile: logo,
      initialBranch: { branch_name: "Head Office", is_main_branch: true },
      initialLicense: { license_type: "standard", max_devices: 10 },
    });

    expect(payload).toBeInstanceOf(FormData);
    expect(payload.get("country_id")).toBe("zw");
    expect(payload.get("logo")).toBe(logo);
    expect(payload.get("initial_branch[branch_name]")).toBe("Head Office");
  });

  test("normalizes company and direct license history responses", () => {
    expect(extractPlatformLicenses({ company: { licenses: [{ id: 3 }] } })).toEqual([{ id: 3 }]);
    expect(extractPlatformLicenses({ data: { licenses: [{ id: 4 }] } })).toEqual([{ id: 4 }]);
  });

  test("surfaces the immutable shared-cloud company code", () => {
    expect(companyCodeForDisplay({ company_code: "ACME-ZW" })).toBe("ACME-ZW");
    expect(companyCodeForDisplay({ tenant_code: "LEGACY" })).toBe("LEGACY");
  });

  test("renders a country relation as a display label instead of a React object", () => {
    expect(companyCountryForDisplay({ country: { name: "Zimbabwe", code: "ZW" } })).toBe("Zimbabwe");
    expect(companyCountryForDisplay({ country: "Zimbabwe" })).toBe("Zimbabwe");
    expect(companyCountryForDisplay({ country: { iso_alpha_3: "ZWE" } })).toBe("ZWE");
  });

  test("stays closed for a tenant super-admin without the platform flag", () => {
    const { container } = render(
      <PlatformCompanyManagementModal open isSuperAdmin onClose={jest.fn()} />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});

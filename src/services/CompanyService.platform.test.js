import api from "../utils/apiBack";
import {
  createPlatformCompany,
  createPlatformCompanyBranch,
  deletePlatformCompany,
  deletePlatformCompanyBranch,
  getPlatformCompanies,
  getPlatformCompany,
  getPlatformCompanyLicenses,
  renewPlatformCompanyLicense,
  updatePlatformCompany,
  updatePlatformCompanyBranch,
} from "./CompanyService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
    put: jest.fn(),
    delete: jest.fn(),
  },
}));

describe("platform company administration services", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: [] } });
    api.post.mockResolvedValue({ data: { message: "created" } });
    api.put.mockResolvedValue({ data: { message: "updated" } });
    api.delete.mockResolvedValue({ data: { message: "deleted" } });
  });

  test("uses platform company CRUD routes", async () => {
    const payload = { company_name: "Northwind", license_support_phone: "+263 77 000 0000" };

    await getPlatformCompanies({ per_page: 500 });
    await getPlatformCompany(8);
    await createPlatformCompany(payload);
    await updatePlatformCompany(8, payload);
    await deletePlatformCompany(8);

    expect(api.get).toHaveBeenCalledWith("/api/companies", expect.objectContaining({ params: { per_page: 500 } }));
    expect(api.get).toHaveBeenCalledWith("/api/companies/8", expect.any(Object));
    expect(api.post).toHaveBeenCalledWith("/api/companies", payload, expect.any(Object));
    expect(api.put).toHaveBeenCalledWith("/api/companies/8", payload, expect.any(Object));
    expect(api.delete).toHaveBeenCalledWith("/api/companies/8", expect.any(Object));
  });

  test("updates a company logo through a multipart method override", async () => {
    const payload = new FormData();
    const logo = new File(["logo"], "company.png", { type: "image/png" });
    payload.append("company_name", "Northwind");
    payload.append("logo", logo);

    await updatePlatformCompany(8, payload);

    expect(payload.get("_method")).toBe("PUT");
    expect(payload.get("logo")).toBe(logo);
    expect(api.post).toHaveBeenCalledWith("/api/companies/8", payload, expect.any(Object));
    expect(api.put).not.toHaveBeenCalled();
  });

  test("creates branches under a company and updates them through the platform branch route", async () => {
    const branch = { branch_name: "Harare", is_main_branch: true };

    await createPlatformCompanyBranch(8, branch);
    await updatePlatformCompanyBranch(12, branch);
    await deletePlatformCompanyBranch(12);

    expect(api.post).toHaveBeenCalledWith("/api/companies/8/branches", branch, expect.any(Object));
    expect(api.put).toHaveBeenCalledWith("/api/company-branches/12", branch, expect.any(Object));
    expect(api.delete).toHaveBeenCalledWith("/api/company-branches/12", expect.any(Object));
  });

  test("loads license history and renews through the selected company", async () => {
    const license = { license_type: "enterprise", expires_at: "2027-10-05" };

    await getPlatformCompanyLicenses(8);
    await renewPlatformCompanyLicense(8, license);

    expect(api.get).toHaveBeenCalledWith("/api/companies/8/licenses", expect.any(Object));
    expect(api.post).toHaveBeenCalledWith("/api/companies/8/licenses/renew", license, expect.any(Object));
  });
});

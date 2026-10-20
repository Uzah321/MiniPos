import api from "../utils/apiBack";
import {
  assignCompanyToCountry,
  createManagedHierarchyRecord,
  deleteManagedHierarchyRecord,
  createEnterpriseShop,
  createEnterpriseTill,
  getCompanyBrands,
  getEnterpriseCountries,
  getEnterpriseHierarchy,
  getTenantEnterpriseHierarchy,
  getShopMenuItems,
  getManagedHierarchyRecords,
  updateManagedHierarchyRecord,
  updateShopMenuItemVisibility,
} from "./EnterpriseHierarchyService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
    patch: jest.fn(),
    put: jest.fn(),
    delete: jest.fn(),
  },
}));

describe("enterprise hierarchy service", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: [] } });
    api.post.mockResolvedValue({ data: { data: {} } });
    api.put.mockResolvedValue({ data: { data: {} } });
    api.patch.mockResolvedValue({ data: { data: {} } });
    api.delete.mockResolvedValue({ data: { data: {} } });
  });

  test("uses unscoped platform administration for hierarchy reads", async () => {
    await getEnterpriseHierarchy({ status: "active" });
    await getEnterpriseCountries({ per_page: 20 });
    await getCompanyBrands("company-1");

    expect(api.get).toHaveBeenNthCalledWith(1, "/api/enterprise/hierarchy", expect.objectContaining({
      params: { status: "active" },
      salexaWorkspace: "back-office",
      salexaUnscopedPlatform: true,
    }));
    expect(api.get).toHaveBeenNthCalledWith(2, "/api/enterprise/countries", expect.objectContaining({ params: { per_page: 20 } }));
    expect(api.get).toHaveBeenNthCalledWith(3, "/api/enterprise/companies/company-1/brands", expect.any(Object));
  });

  test("uses the selected Back Office tenant for scoped hierarchy selectors", async () => {
    await getTenantEnterpriseHierarchy({ page: 2, per_page: 100 });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/enterprise/hierarchy",
      expect.objectContaining({
        params: { page: 2, per_page: 100 },
        salexaWorkspace: "back-office",
      }),
    );
  });

  test("uses the exact assignment, shop and till contracts", async () => {
    await assignCompanyToCountry("country-1", "company-1");
    await createEnterpriseShop("complex-1", { code: "CBD", name: "CBD", pos_login_mode: "both" });
    await createEnterpriseTill("shop-1", { till_code: "T01", till_name: "Counter 1" });

    expect(api.put).toHaveBeenCalledWith(
      "/api/enterprise/countries/country-1/companies/company-1",
      {},
      expect.objectContaining({ salexaUnscopedPlatform: true }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      1,
      "/api/enterprise/complexes/complex-1/shops",
      expect.objectContaining({ pos_login_mode: "both" }),
      expect.any(Object),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      2,
      "/api/enterprise/shops/shop-1/tills",
      { till_code: "T01", till_name: "Counter 1" },
      expect.any(Object),
    );
  });

  test("uses flat management contracts with explicit relationship filters", async () => {
    await getManagedHierarchyRecords("complexes", { company_id: "company-1" });
    await createManagedHierarchyRecord("shops", { brand_id: "brand-1", complex_id: "complex-1" });
    await updateManagedHierarchyRecord("tills", "till-1", { shop_id: "shop-1" });
    await deleteManagedHierarchyRecord("brands", "brand-1");

    expect(api.get).toHaveBeenCalledWith(
      "/api/enterprise/complexes",
      expect.objectContaining({ params: { company_id: "company-1" } }),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/enterprise/shops",
      { brand_id: "brand-1", complex_id: "complex-1" },
      expect.any(Object),
    );
    expect(api.patch).toHaveBeenCalledWith(
      "/api/enterprise/tills/till-1",
      { shop_id: "shop-1" },
      expect.any(Object),
    );
    expect(api.delete).toHaveBeenCalledWith(
      "/api/enterprise/brands/brand-1",
      expect.any(Object),
    );
  });

  test("sends hierarchy image updates as multipart POST overrides", async () => {
    const payload = new FormData();
    const logo = new File(["logo"], "brand.png", { type: "image/png" });
    payload.append("name", "Pizza Inn");
    payload.append("logo", logo);

    await updateManagedHierarchyRecord("brands", "brand-1", payload);

    expect(payload.get("_method")).toBe("PATCH");
    expect(payload.get("logo")).toBe(logo);
    expect(api.post).toHaveBeenCalledWith(
      "/api/enterprise/brands/brand-1",
      payload,
      expect.objectContaining({ salexaUnscopedPlatform: true }),
    );
    expect(api.patch).not.toHaveBeenCalled();
  });

  test("scopes menu visibility to a shop and the POS channel", async () => {
    await getShopMenuItems("shop-1", { q: "pizza", per_page: 50 });
    await updateShopMenuItemVisibility("shop-1", "product-1", {
      is_available: false,
      channel_code: "POS",
    });

    expect(api.get).toHaveBeenCalledWith(
      "/api/enterprise/shops/shop-1/menu-items",
      expect.objectContaining({ params: { channel: "POS", q: "pizza", per_page: 50 } }),
    );
    expect(api.put).toHaveBeenCalledWith(
      "/api/enterprise/shops/shop-1/menu-items/product-1",
      { is_available: false, channel_code: "POS" },
      expect.objectContaining({ salexaUnscopedPlatform: true }),
    );
  });
});


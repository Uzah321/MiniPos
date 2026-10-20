import {
  PLATFORM_FALLBACK_LOGO,
  menuItemIsVisibleAtShop,
  mergeWorkspaceBootstrap,
  normalizeHospitalityBootstrap,
  normalizeLoginMode,
} from "./hospitalityHierarchy";

describe("hospitality configuration normalization", () => {
  test("normalizes the complete enterprise hierarchy and keeps the brand logo first", () => {
    const result = normalizeHospitalityBootstrap({
      hierarchy: {
        country: { id: 1, code: "ZW", name: "Zimbabwe" },
        company: { id: 2, code: "SIM", name: "Simbisa" },
        brand: { id: 3, code: "PI", name: "Pizza Inn", logo_url: "/pizza.svg" },
        complex: { id: 4, code: "AVD", name: "Avondale" },
        shop: { id: 5, code: "PI-AVD", name: "Pizza Inn Avondale" },
        till: { id: 6, code: "T01", name: "Counter 1" },
      },
      branding: { logo_url: "/generic-company.svg" },
      authentication: { pos_login_mode: "both" },
    });

    expect(result.operation.business_mode).toBe("hospitality");
    expect(result.hierarchy.shop.name).toBe("Pizza Inn Avondale");
    expect(result.branch).toBe(result.shop);
    expect(result.authentication.pos_login_mode).toBe("both");
    expect(result.branding.logo).toBe("/pizza.svg");
    expect(result.branding.platform_fallback_logo).toBe(PLATFORM_FALLBACK_LOGO);
  });

  test("reads configuration nested in workspace bootstrap and filters authoritative shop menu", () => {
    const result = normalizeHospitalityBootstrap({
      hierarchy: { shop: { id: "shop-1", name: "CBD" } },
      configuration: {
        configuration_version: 42,
        authentication: { pos_login_mode: "username_password" },
        menu: {
          categories: [{ id: 10, name: "Pizza" }, { id: 20, name: "Drinks" }],
          items: [
            { id: 1, name: "Classic", category_id: 10, is_active: true },
            { id: 2, name: "Hidden here", category_id: 20, excluded_shops: ["shop-1"] },
          ],
        },
      },
    });

    expect(result.configuration_version).toBe(42);
    expect(result.authentication.pos_login_mode).toBe("password");
    expect(result.menu.authoritative).toBe(true);
    expect(result.menu.items.map((item) => item.id)).toEqual([1]);
    expect(result.menu.categories.map((category) => category.id)).toEqual([10]);
  });

  test("an authoritative empty shop menu never leaks unscoped categories", () => {
    const result = normalizeHospitalityBootstrap({
      hierarchy: { shop: { id: 5 } },
      menu: { categories: [{ id: 1, name: "Company-wide" }], items: [] },
    });

    expect(result.menu.authoritative).toBe(true);
    expect(result.menu.items).toEqual([]);
    expect(result.menu.categories).toEqual([]);
  });

  test("applies direct and exclusion-based shop visibility", () => {
    expect(menuItemIsVisibleAtShop({ shop_id: 8 }, 8)).toBe(true);
    expect(menuItemIsVisibleAtShop({ shop_id: 8 }, 9)).toBe(false);
    expect(menuItemIsVisibleAtShop({ shop_exclusions: [{ shop_id: 9 }] }, 9)).toBe(false);
    expect(menuItemIsVisibleAtShop({ is_active: false }, 9)).toBe(false);
  });

  test("merges terminal configuration over the authenticated workspace", () => {
    const merged = mergeWorkspaceBootstrap(
      { user: { id: 1 }, hierarchy: { company: { id: 2, name: "Simbisa" } } },
      { hierarchy: { brand: { id: 3, name: "Chicken Inn" } }, authentication: { pos_login_mode: "code" } },
    );

    expect(merged.user).toEqual({ id: 1 });
    expect(merged.company.name).toBe("Simbisa");
    expect(merged.brand.name).toBe("Chicken Inn");
    expect(merged.authentication.pos_login_mode).toBe("code");
  });

  test.each([
    ["PIN", "code"],
    ["username_password", "password"],
    ["both", "both"],
  ])("normalizes login policy %s", (input, expected) => {
    expect(normalizeLoginMode(input)).toBe(expected);
  });
});


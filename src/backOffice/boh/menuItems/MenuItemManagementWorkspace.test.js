import fs from "fs";
import path from "path";

import api from "../../../utils/apiBack";
import {
  buildMenuItemPayload,
  getMenuItems,
} from "../../../services/MenuItemService";

jest.mock("../../../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn() },
}));

const source = fs.readFileSync(
  path.join(__dirname, "MenuItemManagementModal.js"),
  "utf8",
);

describe("Hospitality menu item management workspace", () => {
  afterEach(() => jest.clearAllMocks());

  test("creates branch-owned menu items without opening stock", () => {
    const payload = buildMenuItemPayload({
      name: "Chicken Burger",
      branch_id: "branch-hospitality",
      base_uom_id: "each",
      product_type: "finished_good",
      price_level_id: "standard",
      price: "4.50",
      currency: "USD",
    });

    expect(payload).toEqual(
      expect.objectContaining({
        name: "Chicken Burger",
        branch_id: "branch-hospitality",
        is_global: false,
        product_type: "finished_good",
      }),
    );
    expect(payload).not.toHaveProperty("opening_stocks");
    expect(payload.prices[0]).toEqual(
      expect.objectContaining({
        branch_id: "branch-hospitality",
        price: 4.5,
        currency: "USD",
      }),
    );
  });

  test("keeps the menu listing explicitly branch and mode scoped", async () => {
    api.get.mockResolvedValue({ data: { data: [] } });

    await getMenuItems({
      branch_id: "branch-hospitality",
      branch_only: true,
      product_type: "service",
    });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/products",
      expect.objectContaining({
        params: expect.objectContaining({
          branch_id: "branch-hospitality",
          branch_only: true,
          product_types: "service",
        }),
      }),
    );
    expect(api.get.mock.calls[0][1].params).not.toHaveProperty("product_type");
  });

  test("keeps deferred recipe consumption as an explicit menu-item permission", () => {
    const payload = buildMenuItemPayload({
      name: "Deferred Issue Burger",
      branch_id: "branch-hospitality",
      base_uom_id: "each",
      product_type: "finished_good",
      stock_tracking_enabled: false,
      allow_negative_stock: true,
    });

    expect(payload.stock_tracking_enabled).toBe(false);
    expect(payload.allow_negative_stock).toBe(true);
    expect(source).toContain("Allow Recipe to Use Negative Front Stock");
    expect(source).toContain("must be cleared by a BOH-to-FRONT issue");
  });

  test("uses one scroll viewport, sticky vertical-dot actions and modal confirmations", () => {
    expect(source).toContain("h-full min-h-0 overflow-hidden");
    expect(source).toContain('min-w-[2440px]');
    expect(source).toContain("<GridHead stickyRight>⋮</GridHead>");
    expect(source).toContain("<GridCell stickyRight>");
    expect(source).toContain("createPortal(");
    expect(source).toContain("<EnterpriseConfirmDialog");
    expect(source).not.toContain("window.confirm");
    expect(source).not.toContain("h-[calc(100vh-86px)]");
  });

  test("shows selectable tax and locks branch identity while editing", () => {
    expect(source).toContain('<option value="">No Tax</option>');
    expect(source).toMatch(/name="branch_id"[\s\S]*?disabled=\{isEditing\}/);
    expect(source).toMatch(/name="price_level_id"[\s\S]*?disabled=\{isEditing\}/);
  });
});

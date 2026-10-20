import fs from "fs";
import path from "path";

const adminLayout = fs.readFileSync(path.join(__dirname, "AdminLayOut.js"), "utf8");

describe("Hospitality category management", () => {
  test("exposes category management under Hospitality Inventory", () => {
    const hospitalityStart = adminLayout.indexOf("const hospitalityOperationalNavigation");
    const hospitalityEnd = adminLayout.indexOf("const retailSalesControlNavigation", hospitalityStart);
    const inventoryStart = adminLayout.indexOf('name: "Inventory"', hospitalityStart);
    const purchasesStart = adminLayout.indexOf('name: "Purchases"', inventoryStart);
    const menuItemsStart = adminLayout.indexOf('name: "Menu Items"', hospitalityStart);
    const reportsStart = adminLayout.indexOf('name: "Reports"', menuItemsStart);
    const hospitalityNavigation = adminLayout.slice(hospitalityStart, hospitalityEnd);
    const inventoryNavigation = adminLayout.slice(inventoryStart, purchasesStart);
    const menuItemsNavigation = adminLayout.slice(menuItemsStart, reportsStart);

    expect(inventoryNavigation).toContain('name: "Category Management"');
    expect(inventoryNavigation).toContain('modal: "category-management"');
    expect(inventoryNavigation).toContain('permission: "view_product_category"');
    expect(hospitalityNavigation).not.toMatch(/\n    \{\r?\n      name: "Category Management"/);
    expect(menuItemsNavigation).not.toContain('name: "Category Management"');
    expect(menuItemsNavigation).not.toContain('modal: "category-management"');
    expect(menuItemsNavigation).toContain('name: "Menu Item Management"');
  });

  test("mounts the shared category modal in both business modes", () => {
    expect(adminLayout).toContain("<ProductCategoryModal");
    expect(adminLayout).not.toMatch(/!isHospitality\s*&&\s*\(\s*<ProductCategoryModal/);
  });
});

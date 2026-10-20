import fs from "fs";
import path from "path";

const adminLayout = fs.readFileSync(path.join(__dirname, "AdminLayOut.js"), "utf8");

describe("Issued Stock navigation", () => {
  test("places Issued Stock next to Issue Stock in Hospitality Inventory", () => {
    const hospitalityStart = adminLayout.indexOf("const hospitalityOperationalNavigation");
    const purchasesStart = adminLayout.indexOf('name: "Purchases"', hospitalityStart);
    const inventoryNavigation = adminLayout.slice(hospitalityStart, purchasesStart);

    const issuePosition = inventoryNavigation.indexOf('name: "Issue Stock"');
    const issuedPosition = inventoryNavigation.indexOf('name: "Issued Stock"');

    expect(issuePosition).toBeGreaterThan(-1);
    expect(issuedPosition).toBeGreaterThan(issuePosition);
    expect(inventoryNavigation).toContain('permission: ["boh_issue_stock", "inventory_management"]');
    expect(inventoryNavigation).toContain('modal: "issued-stock"');
    expect(adminLayout).toContain('"issued-stock": () => setIssuedStockOpen(true)');
    expect(adminLayout).toContain("<IssuedStockModal");
  });
});

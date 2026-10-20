import fs from "fs";
import path from "path";

const read = (relativePath) => fs.readFileSync(path.join(__dirname, relativePath), "utf8");

describe("operational table row actions", () => {
  test.each([
    ["manufacturing production inputs", "pages/manufacturing/ManufacturingOrderWorkflowModal.js"],
    ["receipt register", "pages/sales/ReceiptRegisterModal.js"],
    ["receipt controls", "pages/sales/ReceiptActionsModal.js"],
    ["credit customers and payments", "pages/sales/CreditCustomerManagementModal.js"],
  ])("%s uses an explicit final vertical-dot menu", (_name, relativePath) => {
    const source = read(relativePath);

    expect(source).toContain("action-menu-wrap");
    expect(source).toContain("⋮");
    expect(source).toContain("aria-label=");
    expect(source).toMatch(/Open (?:row|receipt) actions/i);
  });

  test("the stock-area register exposes its custom action menu directly", () => {
    const source = read("boh/inventory/StockAreaRegisterModal.js");

    expect(source).toContain('className="action-menu-wrap relative inline-flex"');
    expect(source).toContain("⋮");
    expect(source).toContain('aria-haspopup="menu"');
  });

  test("the migrated tables no longer render their former inline action groups", () => {
    const manufacturing = read("pages/manufacturing/ManufacturingOrderWorkflowModal.js");
    const register = read("pages/sales/ReceiptRegisterModal.js");
    const controls = read("pages/sales/ReceiptActionsModal.js");
    const credit = read("pages/sales/CreditCustomerManagementModal.js");

    expect(manufacturing).not.toContain('<div className="flex gap-1"><SmallButton disabled={completed}');
    expect(register).not.toContain('<div className="flex justify-end gap-1">');
    expect(controls).not.toContain('<div className="flex justify-end gap-1">');
    expect(credit).not.toContain("<IconButton");
  });
});

import fs from "fs";
import path from "path";

const read = (relativePath) =>
  fs.readFileSync(path.join(__dirname, relativePath), "utf8");

describe("Hospitality deferred FRONT-stock Day End flow", () => {
  const guard = read("DayEndEnterpriseGuardModal.js");
  const closing = read("../inventory/DailyFrontStockClosingModal.js");
  const adminLayout = read("../../AdminLayOut.js");

  test("blocks Day End and identifies the exact menu item and ingredient", () => {
    expect(guard).toContain("blocking_front_stock_issues");
    expect(guard).toContain("Issue Stock Before Reconciliation");
    expect(guard).toContain("row.menu_product_name");
    expect(guard).toContain("row.ingredient_name");
    expect(guard).toContain("row.issue_quantity_required");
    expect(guard).toContain("if (blockingFrontStockIssues.length > 0)");
  });

  test("does not send or expose the old Day End reconciliation bypass", () => {
    expect(guard).not.toContain("allow_without_manual_closing:");
    expect(guard).toContain("Physical Reconciliation Required");
    expect(guard).toContain("row.can_force_close === false");
  });

  test("prevents saving reconciliation while negative recipe issues remain", () => {
    expect(closing).toContain("blocking_front_stock_issues");
    expect(closing).toContain("if (blockingFrontStockIssues.length > 0)");
    expect(closing).toContain("Issue Stock to Front First");
    expect(closing).toContain("blockingFrontStockIssues.length > 0");
  });

  test("opens the correct modal from each blocking action", () => {
    expect(adminLayout).toContain("onOpenIssueStock={() => {");
    expect(adminLayout).toContain("setIssueStockToFrontOpen(true)");
    expect(adminLayout).toContain("onOpenFrontStockReconciliation={() => {");
    expect(adminLayout).toContain("setDailyFrontStockClosingOpen(true)");
  });
});

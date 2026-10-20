import fs from "fs";
import path from "path";
import { buildIngredientUsageVariance } from "./MenuRecipeDailyStockReconciliationModal";

const source = fs.readFileSync(
  path.join(__dirname, "MenuRecipeDailyStockReconciliationModal.js"),
  "utf8",
);

describe("daily stock reconciliation usage visual", () => {
  test("derives actual usage from backend expected usage and stock variance", () => {
    expect(buildIngredientUsageVariance([
      {
        ingredient_product_id: 7,
        ingredient_name: "Chicken",
        ingredient_code: "CHK",
        uom_code: "KG",
        sales_consumption_qty: 12,
        variance_qty: -2,
      },
      {
        ingredient_product_id: 8,
        ingredient_name: "Oil",
        sales_consumption_qty: 5,
        variance_qty: 1,
      },
    ])).toEqual([
      expect.objectContaining({ id: "7", expectedUsage: 12, actualUsage: 14, variance: -2 }),
      expect.objectContaining({ id: "8", expectedUsage: 5, actualUsage: 4, variance: 1 }),
    ]);
  });

  test("uses a chooser and a separate result workspace", () => {
    expect(source).toContain("<ReportOutputChooser");
    expect(source).toContain("<ReportViewShell");
    expect(source).toContain("onApply={openOutputChooser}");
    expect(source).not.toContain("h-[calc(100vh-86px)]");
  });

  test("keeps all report downloads on backend endpoints", () => {
    expect(source).toContain("downloadLiveMenuRecipeDsrExcel(");
    expect(source).toContain("downloadLiveMenuRecipeDsrPdf(");
    expect(source).toContain("downloadMenuRecipeDsrExcel(");
    expect(source).toContain("downloadMenuRecipeDsrPdf(");
  });

  test("enters view only after the report request succeeds", () => {
    const loadIndex = source.indexOf('if (key === "current") await loadLive');
    const generatedIndex = source.indexOf("setGenerated(true);", loadIndex);
    expect(generatedIndex).toBeGreaterThan(loadIndex);
    expect(source).toMatch(/catch \(error\) \{\s*setReport\(null\);\s*setGenerated\(false\)/);
  });
});

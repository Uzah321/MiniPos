import fs from "fs";
import path from "path";

const source = (relativePath) => fs.readFileSync(path.join(__dirname, relativePath), "utf8");

const hospitalityOnlyFiles = [
  "boh/inventory/InventoryVarianceAgentModal.js",
  "pages/product/ProductImportModal.js",
  "pages/product/ProductManagementFullModal.js",
  "pages/manufacturing/ManufacturingReportsModal.js",
  "pages/report/StockReportsModal.js",
  "pages/report/StockAdjustmentHistoryModal.js",
  "pages/report/EnterpriseTaxReportPage.js",
  "pages/report/ProductPriceAdjustmentHistoryModal.js",
  "pages/report/ProfitAndLossReport.js",
  "pages/report/DiscountReportsModal.js",
];

describe("Hospitality-only back-office UI contract", () => {
  test.each(hospitalityOnlyFiles)("%s has no user-facing Retail string", (relativePath) => {
    expect(source(relativePath)).not.toMatch(/["'`][^"'`\r\n]*\bRetail\b/i);
  });

  test("product import is brand-scoped hospitality setup", () => {
    const productImport = source("pages/product/ProductImportModal.js");

    expect(productImport).toContain("Brand Menu Import");
    expect(productImport).toContain("Products, recipes, modifiers and prep messages");
    expect(productImport).toContain("brand_id: brandId");
    expect(productImport).not.toContain("isHospitality ?");
  });

  test("stock reports no longer add reports based on a mode label", () => {
    const stockReports = source("pages/report/StockReportsModal.js");

    expect(stockReports).not.toContain('includes("hospitality")');
    expect(stockReports).toContain('businessModeLabel = "Hospitality"');
  });
});

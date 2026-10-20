import fs from "fs";
import path from "path";

const read = (file) => fs.readFileSync(path.join(__dirname, file), "utf8");

describe("report output workspaces", () => {
  const downloadableReports = [
    ["profit and loss", "ProfitAndLossReport.js", "downloadSalesReportExport"],
    ["monthly profit comparison", "MonthlyProfitComparisonReport.js", "downloadSalesReportExport"],
    ["discounts", "DiscountReportsModal.js", "exportDiscountReport"],
    ["tax", "EnterpriseTaxReportPage.js", "downloadEnterpriseTaxReport"],
  ];

  test.each(downloadableReports)(
    "%s uses the centered chooser, a separate result workspace and a backend export",
    (_name, file, backendExport) => {
    const source = read(file);

    expect(source).toContain("ReportOutputChooser");
    expect(source).toContain("ReportViewShell");
    expect(source).toContain("chooserMode");
    expect(source).toMatch(/applied(?:Filters|Params)/);
    expect(source).toContain(backendExport);
    expect(source).not.toContain("window.print()");
    expect(source).not.toContain("ReportDownloadModal");
    },
  );

  test.each([
    ["price adjustment history", "ProductPriceAdjustmentHistoryModal.js", "downloadProductPriceAdjustmentHistory"],
    ["stock adjustment history", "StockAdjustmentHistoryModal.js", "downloadStockAdjustmentHistory"],
  ])("%s offers backend PDF and Excel from its applied snapshot", (_name, file, backendExport) => {
    const source = read(file);

    expect(source).toContain("ReportOutputChooser");
    expect(source).toContain("ReportViewShell");
    expect(source).toContain("appliedConfig");
    expect(source).toContain(backendExport);
    expect(source).toContain("onDownloadPdf");
    expect(source).toContain("onDownloadExcel");
    expect(source).not.toMatch(/(?:pdf|excel):\s*\{\s*available:\s*false/);
  });

  test("all six clear stale results before returning from a failed view request", () => {
    [
      "ProfitAndLossReport.js",
      "MonthlyProfitComparisonReport.js",
      "DiscountReportsModal.js",
      "EnterpriseTaxReportPage.js",
      "ProductPriceAdjustmentHistoryModal.js",
      "StockAdjustmentHistoryModal.js",
    ].forEach((file) => {
      const source = read(file);
      expect(source).toMatch(/catch\s*\([^)]*\)\s*\{[\s\S]*?(?:clearResults\(\)|setGenerated\(false\))/);
    });
  });

  test("price history refresh and pagination stay on the applied product snapshot", () => {
    const source = read("ProductPriceAdjustmentHistoryModal.js");

    expect(source).toContain("const snapshot = { ...(requestedConfig || draftConfig()) };");
    expect(source).toMatch(/getProductPriceAdjustmentHistory\(\s*snapshot\.productId/);
    expect(source).toContain("onClick={() => loadHistory(1, appliedConfig)}");
    expect(source).toContain("onClick={() => loadHistory(page - 1, appliedConfig)}");
    expect(source).toContain("onClick={() => loadHistory(page + 1, appliedConfig)}");
  });

  test.each(["PosReportsModal.js", "StockReportsModal.js"])(
    "%s fills the FullScreenModal body without subtracting its header twice",
    (file) => {
      const source = read(file);
      expect(source).not.toContain("h-[calc(100vh-48px)]");
      expect(source).not.toContain("window.print()");
    },
  );
});

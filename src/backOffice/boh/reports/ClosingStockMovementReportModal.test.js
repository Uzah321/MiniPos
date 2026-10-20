import fs from "fs";
import path from "path";

const source = fs.readFileSync(
  path.join(__dirname, "ClosingStockMovementReportModal.js"),
  "utf8",
);

describe("Closing stock movement report output flow", () => {
  test("opens an output chooser instead of appending results below filters", () => {
    expect(source).toContain("<ReportOutputChooser");
    expect(source).toContain("<ReportViewShell");
    expect(source).toContain("onApply={openOutputChooser}");
    expect(source).toMatch(/\{!generated && \([\s\S]*?<HospitalityReportScopePanel/);
    expect(source).not.toContain("h-[calc(100vh-86px)]");
  });

  test("keeps the report navigator beside the complete filter and result workspace", () => {
    const workspaceIndex = source.indexOf('<div className="flex h-full min-h-0">');
    const navigatorIndex = source.indexOf("<HospitalityReportNavigator", workspaceIndex);
    const scopeIndex = source.indexOf("<HospitalityReportScopePanel", workspaceIndex);

    expect(workspaceIndex).toBeGreaterThan(-1);
    expect(navigatorIndex).toBeGreaterThan(workspaceIndex);
    expect(scopeIndex).toBeGreaterThan(navigatorIndex);
    expect(source).toContain('periodLabel="Trading Date Period"');
  });

  test("places closing-day selection in the unified report scope grid", () => {
    expect(source).toContain("const closingDayCells = [");
    expect(source).toContain('key: "closing-stock-days"');
    expect(source).toContain('key: "closing-stock-day-availability"');
    expect(source).toContain("extraCells={closingDayCells}");
    expect(source.match(/<HospitalityReportScopePanel/g)).toHaveLength(1);
  });

  test("downloads PDF and Excel from backend report services", () => {
    expect(source).toContain("downloadClosingStockMovementPdf(");
    expect(source).toContain("downloadClosingStockMovementExcel(");
    expect(source).toContain('downloadChosenFormat("pdf")');
    expect(source).toContain('downloadChosenFormat("xlsx")');
  });

  test("only opens the report workspace after a successful backend view", () => {
    const reportIndex = source.indexOf("setReport(data);");
    const generatedIndex = source.indexOf("setGenerated(true);");
    expect(generatedIndex).toBeGreaterThan(reportIndex);
    expect(source).toMatch(/catch \(error\) \{\s*setGenerated\(false\);\s*setAppliedClosingIds\(\[\]\)/);
  });

  test("handles an initial closing-day lookup failure inside the modal", () => {
    expect(source).toMatch(/await loadClosingDays\(\);[\s\S]+?catch \(error\) \{[\s\S]+?setClosingDays\(\[\]\);[\s\S]+?Failed to load closing stock days/);
  });
});

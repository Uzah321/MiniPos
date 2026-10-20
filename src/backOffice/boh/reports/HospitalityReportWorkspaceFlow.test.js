import fs from "fs";
import path from "path";

const REPORT_FILES = [
  "FoodIndustrySalesReportModal.js",
  "DailyFoodIndustrySalesReportModal.js",
  "ShiftReportModal.js",
  "StockIssuedToFrontReportModal.js",
  "StockWastageReportModal.js",
];

const sources = Object.fromEntries(REPORT_FILES.map((file) => [
  file,
  fs.readFileSync(path.join(__dirname, file), "utf8"),
]));

describe("hospitality report output workspaces", () => {
  test.each(REPORT_FILES)("%s uses the shared chooser and result shell", (file) => {
    const source = sources[file];

    expect(source).toContain('from "../../../components/ReportOutputChooser"');
    expect(source).toContain('from "../../../components/ReportViewShell"');
    expect(source).toContain('<ReportOutputChooser');
    expect(source).toContain('<ReportViewShell');
    expect(source).toContain('onApply={() => openChooser("configuration")}');
    expect(source).toContain('onBackToFilters={() => setViewing(false)}');
    expect(source).toContain('onClick={refreshReport}');
    expect(source).toContain('onClick={() => openChooser("result")}');
    expect(source).not.toContain("ReportDownloadModal");
  });

  test.each(REPORT_FILES)("%s snapshots applied filters and only opens a successful View", (file) => {
    const source = sources[file];
    const successBlock = /if \(loaded\) \{\s*setAppliedFilters\(nextFilters\);\s*setViewing\(true\);\s*\}/;

    expect(source).toContain("const [outputFilters, setOutputFilters] = useState(null);");
    expect(source).toContain("const [appliedFilters, setAppliedFilters] = useState(null);");
    expect(source).toContain("setReport(null);");
    expect(source).toMatch(successBlock);
    expect(source).toMatch(/if \(!loaded\) \{\s*setAppliedFilters\(null\);\s*setViewing\(false\);/);
  });

  test("all downloads use existing backend report services", () => {
    expect(sources["FoodIndustrySalesReportModal.js"]).toContain("downloadFoodIndustrySalesExcel(nextFilters)");
    expect(sources["FoodIndustrySalesReportModal.js"]).toContain("downloadFoodIndustrySalesPdf(nextFilters)");
    expect(sources["DailyFoodIndustrySalesReportModal.js"]).toContain("downloadFoodIndustryDailySalesExcel(nextApiFilters");
    expect(sources["DailyFoodIndustrySalesReportModal.js"]).toContain("downloadFoodIndustryDailySalesPdf(nextApiFilters");
    expect(sources["ShiftReportModal.js"]).toContain("downloadShiftReportExcel(nextFilters)");
    expect(sources["ShiftReportModal.js"]).toContain("downloadShiftReportPdf(nextFilters)");
    expect(sources["StockIssuedToFrontReportModal.js"]).toContain("downloadStockIssuedToFrontExcel(nextFilters");
    expect(sources["StockIssuedToFrontReportModal.js"]).toContain("downloadStockIssuedToFrontPdf(nextFilters");
    expect(sources["StockWastageReportModal.js"]).toContain("downloadStockWastageExcel(nextFilters)");
    expect(sources["StockWastageReportModal.js"]).toContain("downloadStockWastagePdf(nextFilters)");
  });

  test("Food Industry Sales keeps report navigation beside configuration and results", () => {
    const source = sources["FoodIndustrySalesReportModal.js"];
    const navigator = source.indexOf("<HospitalityReportNavigator");
    const scopePanel = source.indexOf("<HospitalityReportScopePanel");

    expect(navigator).toBeGreaterThan(-1);
    expect(scopePanel).toBeGreaterThan(navigator);
    expect(source).toContain('activeKey={HOSPITALITY_REPORT_KEYS.DETAILED_SALES}');
    expect(source).toContain('className="flex h-full min-h-0"');
  });
});

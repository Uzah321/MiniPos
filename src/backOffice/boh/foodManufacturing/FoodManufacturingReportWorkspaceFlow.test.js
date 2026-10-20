import fs from "fs";
import path from "path";

const source = fs.readFileSync(
  path.join(__dirname, "FoodManufacturingReportsModal.js"),
  "utf8",
);

describe("food manufacturing report output workspace", () => {
  test("uses the shared chooser and a separate result workspace", () => {
    expect(source).toContain('<ReportOutputChooser');
    expect(source).toContain('<ReportViewShell');
    expect(source).toContain('onApply={() => openChooser("configuration")}');
    expect(source).toContain('onBackToFilters={() => setViewing(false)}');
    expect(source).toContain('onClick={refreshReport}');
    expect(source).toContain('onClick={() => openChooser("result")}');
  });

  test("keeps one persistent left report navigator beside configuration and results", () => {
    expect((source.match(/<ReportViewNavigator/g) || [])).toHaveLength(1);
    expect(source).toMatch(/<div className="flex h-full min-h-0 overflow-hidden border[^>]+>\s*<ReportViewNavigator[^>]+>\s*<div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">\s*\{!viewing/);
    expect(source).toContain('className="flex h-full min-h-0 flex-col overflow-y-auto"');
    expect(source).toContain('className="min-h-0 min-w-0 flex-1 overflow-auto"');
  });

  test("integrates contextual production filters into the main scope grid", () => {
    expect(source).toContain("extraCells={productionFilterCells}");
    expect(source).toContain('const isPlanningView = (tab) => ["planning", "shortages"].includes(tab);');
    expect(source).toContain('...(isPlanningView(activeTab) ? [{');
    expect(source).toContain('aria-label="Planned quantity"');
    expect(source).toContain('aria-label="Recipe"');
    expect(source).toContain('aria-label="Consume from"');
    expect(source).toContain('aria-label="Output to"');
    expect(source).toContain('className: isPlanningView(activeTab) ? "" : "md:col-span-2"');
    expect(source).not.toContain("Production reporting desk");
    expect(source).not.toContain("Recipe / Plan Item");
  });

  test("requires recipe and quantity for planning views and omits stale quantity elsewhere", () => {
    expect(source).toMatch(/planned_quantity: isPlanningView\(tab\) \? value\?\.planned_quantity \|\| "" : ""/);
    expect(source).toContain('title: "Recipe Required"');
    expect(source).toContain('title: "Planned Quantity Required"');
    expect(source).toContain("Number(nextFilters.planned_quantity) > 0");
    expect(source).toContain("onChange={handleReportViewChange}");
    expect(source).toMatch(/viewing[\s\S]+?isPlanningView\(nextTab\)[\s\S]+?appliedFilters\?\.recipe_id[\s\S]+?setViewing\(false\)/);
  });

  test("captures the selected tab and one of the three backend artifact contexts", () => {
    expect(source).toContain('const REPORT_ARTIFACTS = {');
    expect(source).toContain('production: {');
    expect(source).toContain('saved_plans: {');
    expect(source).toContain('planning: {');
    expect(source).toContain('setOutputArtifact(artifactForTab(activeTab));');
    expect(source).toContain('setOutputTab(activeTab);');
    expect(source).toContain('setActiveTab(nextTab);');
  });

  test("routes PDF and Excel choices to backend download endpoints for the captured artifact", () => {
    expect(source).toContain('downloadFoodManufacturingProductionExcel(nextParams)');
    expect(source).toContain('downloadFoodManufacturingProductionPdf(nextParams)');
    expect(source).toContain('downloadFoodManufacturingPlanningExcel(nextParams)');
    expect(source).toContain('downloadFoodManufacturingPlanningPdf(nextParams)');
    expect(source).toContain('downloadFoodManufacturingSavedPlansExcel(nextParams)');
    expect(source).toContain('downloadFoodManufacturingSavedPlansPdf(nextParams)');
    expect(source).not.toContain('URL.createObjectURL');
  });

  test("keeps failed View generation on configuration and clears stale output", () => {
    expect(source).toMatch(/setReport\(null\);\s*setPlan\(null\);\s*setSavedPlans\(\[\]\);/);
    expect(source).toMatch(/if \(loaded\) \{\s*setAppliedFilters\(nextFilters\);\s*setActiveTab\(nextTab\);\s*setViewing\(true\);/);
    expect(source).toMatch(/if \(!loaded\) \{\s*setAppliedFilters\(null\);\s*setViewing\(false\);/);
  });
});

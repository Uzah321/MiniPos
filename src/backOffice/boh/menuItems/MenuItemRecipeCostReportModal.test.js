import fs from "fs";
import path from "path";

const source = fs.readFileSync(
  path.join(__dirname, "MenuItemRecipeCostReportModal.js"),
  "utf8",
);

describe("Menu item recipe cost report workspace", () => {
  test("uses the full modal body and a responsive master-detail viewport", () => {
    expect(source).toContain("menu-recipe-report flex h-full min-h-0 overflow-hidden");
    expect(source).not.toContain("h-[calc(100vh-86px)]");
    expect(source).toContain("overflow-x-hidden overflow-y-auto");
    expect(source).toContain("xl:grid-cols-[minmax(300px,36%)_minmax(0,64%)]");
    expect(source).not.toContain("min-w-[900px]");
    expect(source).not.toContain("min-w-[820px]");
    expect(source).not.toContain("min-w-[1120px]");
    expect(source).toContain("min-w-[720px]");
  });

  test("keeps the result toolbar concise and removes duplicate cost cards and banners", () => {
    expect(source).toContain('placeholder="Search menu item, code or recipe"');
    expect(source).toContain("Cost breakdown");
    expect(source).toContain("Total recipe cost");
    expect(source).toContain("Average cost / unit");
    expect(source).not.toContain("Menu Recipe Cost Workbench");
    expect(source).not.toContain("Backend costing loaded successfully");
    expect(source).not.toContain("<SummaryBox");
    expect(source).not.toContain("<MiniMetric");
  });

  test("uses compact enterprise tables and vertical row actions without cut-off duplicate columns", () => {
    expect(source).toContain("sx-enterprise-table w-full table-fixed");
    expect(source).toContain("<MoreVertical");
    expect(source).toContain("Avg Unit Cost");
    expect(source).toContain("Linked Recipe");
    expect(source).not.toContain("<GridTh>Type</GridTh>");
    expect(source).not.toContain("<GridTh>#</GridTh>");
  });

  test("keys backend costing by branch and recipe and refreshes when branch changes", () => {
    expect(source).toMatch(/costingBranchId.*activeBaseRecipe\.id/);
    expect(source).toMatch(/selectedBranchId \|\| "company".*recipeId/);
    expect(source).toContain("appliedScopeFilters?.branch_id, branchId, generated, hydrateRecipe]);");
    expect(source).toContain("requestId !== hydrationRequestRef.current");
  });

  test("only exposes generated results after a successful load", () => {
    const successIndex = source.indexOf("setGenerated(true);");
    const productIndex = source.indexOf("setMenuItems(products);");

    expect(successIndex).toBeGreaterThan(productIndex);
    expect(source).toMatch(/catch \(error\) \{\s*setGenerated\(false\);/);
  });

  test("offers backend-generated Excel and PDF downloads", () => {
    expect(source).toContain("<ReportOutputChooser");
    expect(source).toContain('downloadChosenFormat("xlsx")');
    expect(source).toContain('downloadChosenFormat("pdf")');
    expect(source).toContain('downloadConfiguredFormat("xlsx")');
    expect(source).toContain('downloadConfiguredFormat("pdf")');
    expect(source).toContain("downloadMenuItemRecipeCostReport(menuItemIds, format");
    expect(source).toContain("branch_only: true");
    expect(source).toContain("exported successfully from the backend");
    expect(source).not.toContain('View the report and select menu items first.');
  });

  test("separates configuration from the generated report workspace", () => {
    expect(source).toContain("<ReportViewShell");
    expect(source).toContain("onApply={openGenerateChooser}");
    expect(source).toContain("onBackToFilters={() => setGenerated(false)}");
    expect(source).toContain("setAppliedScopeFilters({ ...nextScope");
  });

  test("scopes report input styling to this report", () => {
    expect(source).toContain(".menu-recipe-report .report-input");
    expect(source).not.toMatch(/(?:^|[;}])\s*\.report-input\s*\{/);
  });

  test("shows a branch label instead of exposing a branch identifier", () => {
    expect(source).toContain("const reportBranchName = useMemo");
    expect(source).toMatch(/subtitle=\{`Branch: \$\{reportBranchName\}`\}/);
    expect(source).toContain("getBranchName(lookups?.branchById?.[selectedBranchId])");
  });
});

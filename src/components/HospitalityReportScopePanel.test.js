import { filterReportLookupsByBranchIds } from "./HospitalityReportScopePanel";
import fs from "fs";
import path from "path";

describe("filterReportLookupsByBranchIds", () => {
  const hospitalityBranchIds = ["hospitality-a", "hospitality-b"];

  it("keeps only tills and shifts from same-mode workspace branches", () => {
    const rows = [
      { id: "till-a", branch_id: "hospitality-a" },
      { id: "shift-b", branch: { id: "hospitality-b" } },
      { id: "retail-row", branch_id: "retail-a" },
    ];

    expect(filterReportLookupsByBranchIds(rows, hospitalityBranchIds).map((row) => row.id))
      .toEqual(["till-a", "shift-b"]);
  });

  it("keeps a cashier assigned to any same-mode workspace branch", () => {
    const rows = [
      { id: "cashier-a", branch_id: "hospitality-a", branches: [] },
      { id: "cashier-b", branch_id: "retail-a", branches: [{ id: "hospitality-b" }] },
      { id: "retail-only", branch_id: "retail-a", branches: [{ id: "retail-b" }] },
    ];

    expect(filterReportLookupsByBranchIds(rows, hospitalityBranchIds).map((row) => row.id))
      .toEqual(["cashier-a", "cashier-b"]);
  });

  it("fails closed while workspace branches are unavailable", () => {
    expect(filterReportLookupsByBranchIds([{ id: "retail-row", branch_id: "retail-a" }], []))
      .toEqual([]);
  });
});

describe("HospitalityReportScopePanel report-specific cells", () => {
  const source = fs.readFileSync(path.join(__dirname, "HospitalityReportScopePanel.js"), "utf8");

  it("renders extra cells inside the main scope grid before business mode and actions", () => {
    expect(source).toContain('extraCells = []');
    expect(source).toMatch(/Array\.isArray\(extraCells\)[\s\S]+?<ScopeCell[\s\S]+?key=[\s\S]+?cell\?\.content[\s\S]+?<ScopeCell label="Business Mode"/);
    expect(source).toContain('className={cell?.className || ""}');
  });
});

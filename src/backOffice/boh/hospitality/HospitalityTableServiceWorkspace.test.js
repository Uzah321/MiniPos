import { readFileSync } from "fs";
import path from "path";

describe("Back Office dining table configuration workspace", () => {
  test("is reachable from Hospitality navigation with read/manage permissions", () => {
    const source = readFileSync(path.resolve(__dirname, "../../AdminLayOut.js"), "utf8");

    expect(source).toContain('name: "Dining & Table Service"');
    expect(source).toContain('modal: "hospitality-table-service"');
    expect(source).toContain('permission: ["view_hospitality_tables", "manage_hospitality_tables"]');
    expect(source).toContain('"hospitality-table-service": () => openHospitalityOnly(');
    expect(source).toContain("<HospitalityTableServiceModal");
  });
});

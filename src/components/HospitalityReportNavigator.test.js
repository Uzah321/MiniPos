import { getHospitalityReportItems, HOSPITALITY_REPORT_KEYS } from "./HospitalityReportNavigator";

describe("HospitalityReportNavigator", () => {
  test("keeps sales and inventory report groups separate", () => {
    const sales = getHospitalityReportItems("sales").map((item) => item.key);
    const inventory = getHospitalityReportItems("inventory").map((item) => item.key);

    expect(sales).toEqual([
      HOSPITALITY_REPORT_KEYS.DAILY_SALES,
      HOSPITALITY_REPORT_KEYS.DETAILED_SALES,
      HOSPITALITY_REPORT_KEYS.SHIFT,
    ]);
    expect(inventory).toContain(HOSPITALITY_REPORT_KEYS.STOCK_ISSUED);
    expect(inventory).toContain(HOSPITALITY_REPORT_KEYS.MENU_RECIPE_COST);
    expect(inventory).not.toContain(HOSPITALITY_REPORT_KEYS.DAILY_SALES);
  });
});

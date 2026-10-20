import { identifyComparisonPeriods, monthPeriod } from "./MonthlyProfitComparisonReport";

describe("MonthlyProfitComparisonReport", () => {
  test("uses today as the end of the active month", () => {
    expect(monthPeriod("2026-10", "2026-10-02")).toEqual({
      month: "2026-10",
      date_from: "2026-10-01",
      date_to: "2026-10-02",
    });
  });

  test("uses the full range for a completed month", () => {
    expect(monthPeriod("2026-09", "2026-10-02")).toEqual({
      month: "2026-09",
      date_from: "2026-09-01",
      date_to: "2026-09-30",
    });
  });

  test("identifies current and prior periods without relying on array order", () => {
    const current = { key: "current_month", gross_profit: 100 };
    const prior = { key: "previous_month", gross_profit: 80 };

    expect(identifyComparisonPeriods([current, prior])).toEqual({ current, prior });
  });
});

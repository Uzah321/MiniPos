import {
  applyAuthoritativeTradingDate,
  cashMovementKpiRows,
  defaultFilters,
  reportTillLabel,
  resolveAuthoritativeTradingDate,
} from "./PosReportsModal";

describe("POS cash movement KPI projection", () => {
  test("uses full-result API summary totals instead of the current page", () => {
    const rows = cashMovementKpiRows({
      summary: {
        movement_count: 37,
        totals_by_currency: [{
          currency_code: "USD",
          cashout_total: 100,
          cashin_total: 25,
          net_cash_movement: -75,
        }],
      },
      rows: [
        { currency_code: "USD", movement_type: "cash_out", amount: 5 },
        { currency_code: "USD", movement_type: "cash_in", amount: 2 },
      ],
      currencyCodes: ["USD"],
      total: 2,
    });

    expect(rows).toEqual([
      ["Movements", 37],
      ["USD Cash Out", "USD 100.00"],
      ["USD Cash In", "USD 25.00"],
      ["USD Net", "USD -75.00"],
    ]);
  });
});

describe("POS report Hospitality scope", () => {
  test("always sends the canonical Hospitality mode", () => {
    expect(defaultFilters().business_mode).toBe("fast_food_boh");
    expect(defaultFilters({ businessMode: "retail" }).business_mode).toBe("fast_food_boh");
  });

  test("does not invent a browser date while the authoritative date is unresolved", () => {
    const filters = defaultFilters({
      businessMode: "fast_food_boh",
      branchId: "branch-a",
      tradingDate: "",
      useTradingDate: true,
    });

    expect(filters.business_date).toBe("");
    expect(filters.date_from).toBe("");
    expect(filters.date_to).toBe("");
    expect(filters.branch_id).toBe("branch-a");
  });

  test("resolves the selected branch trading date and applies it consistently", async () => {
    const loader = jest.fn().mockResolvedValue({ trading_date: "2026-10-03" });

    const tradingDate = await resolveAuthoritativeTradingDate("branch-b", loader);
    const filters = applyAuthoritativeTradingDate({ branch_id: "branch-b" }, tradingDate);

    expect(loader).toHaveBeenCalledWith({ branch_id: "branch-b" });
    expect(filters).toEqual({
      branch_id: "branch-b",
      business_date: "2026-10-03",
      date_from: "2026-10-03",
      date_to: "2026-10-03",
    });
  });

  test("fails closed when the backend has no authoritative trading date", async () => {
    const loader = jest.fn().mockResolvedValue({
      trading_date: null,
      sale_block_reason: "Trading day is closed.",
    });

    await expect(resolveAuthoritativeTradingDate("branch-a", loader))
      .rejects.toThrow("Trading day is closed.");
  });
});

describe("POS report till labels", () => {
  test("shows both till code and name without duplicating fallback values", () => {
    expect(reportTillLabel({ till_code: "T01", till_name: "Front Till" }))
      .toBe("T01 - Front Till");
    expect(reportTillLabel({ till_code: "T01", till_name: "T01" })).toBe("T01");
    expect(reportTillLabel({ till_name: "Front Till" })).toBe("Front Till");
  });
});

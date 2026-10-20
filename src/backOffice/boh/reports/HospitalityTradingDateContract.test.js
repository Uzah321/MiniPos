import fs from "fs";
import path from "path";

const read = (relativePath) => fs.readFileSync(
  path.join(__dirname, relativePath),
  "utf8",
);

describe("Hospitality trading-date report contracts", () => {
  test("daily reconciliation defaults to the authoritative trading day", () => {
    const liveSource = read("MenuRecipeDailyStockReconciliationModal.js");
    const closedSource = read("DailyStockReconciliationReportModal.js");

    expect(liveSource).toContain("const day = await getCurrentTradingDay();");
    expect(liveSource).toContain("No authoritative Hospitality trading date is open for this branch.");
    expect(liveSource).toContain("trading_date: day?.trading_date");
    expect(liveSource).not.toContain("const today = new Date().toISOString().slice(0, 10)");

    expect(closedSource).toContain("getCurrentTradingDay,");
    expect(closedSource).toContain("date_to: tradingDate");
    expect(closedSource).toContain('periodLabel="Trading Date Range"');
    expect(closedSource).not.toContain("date_to: today()");
  });

  test("cashup history uses one explicit Trading Date for view and downloads", () => {
    const source = read("CashupHistoryReportModal.js");

    expect(source).toContain("withAuthoritativeTradingDate");
    expect(source).toContain("getCurrentTradingDay({");
    expect(source).toContain("business_date: tradingDate");
    expect(source).toContain("date_from: tradingDate");
    expect(source).toContain("date_to: tradingDate");
    expect(source).toContain("singleTradingDate");
    expect(source).toContain('["Trading Date", "Closed At"');
    expect(source).toContain("downloadCashupHistoryExcel(filters)");
    expect(source).toContain("downloadCashupHistoryPdf(filters)");
    expect(source).not.toContain("date_from: today()");
  });

  test("cashup entry displays but cannot override the backend trading date", () => {
    const source = read("../../../components/EnterpriseCashupModal.js");
    const hospitalityDateHelper = source.match(/const getHospitalityTradingDate[\s\S]*?\n};/)?.[0] || "";

    expect(hospitalityDateHelper).toContain("summary?.trading_date");
    expect(hospitalityDateHelper).not.toContain("today()");
    expect(source).toContain("<CashupKpi label={getCashupBracketLabel()}");
    expect(source).toMatch(/value=\{header\.trading_date\}[\s\S]*?readOnly[\s\S]*?disabled/);
    expect(source).toContain('setError("Trading date is required in Hospitality mode.")');
    expect(source).not.toContain("business_mode:");
    expect(source).not.toMatch(/retail/i);
  });
});

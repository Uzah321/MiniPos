import fs from "fs";
import path from "path";

const source = fs.readFileSync(
  path.join(__dirname, "StockReportsModal.js"),
  "utf8",
);
const adjustmentHistorySource = fs.readFileSync(
  path.join(__dirname, "StockAdjustmentHistoryModal.js"),
  "utf8",
);

describe("stock report average-cost valuation contract", () => {
  test("removes retail-price valuation from every stock report view", () => {
    expect(source).not.toMatch(/selling(?:_|\s|-)*(?:price|value|currency)/i);
    expect(source).not.toContain("Potential Margin");
    expect(source).not.toContain("window.print()");
    expect(source).not.toContain("row.cost_value ?? row.total_cost ?? row.value_difference");
  });

  test("labels current inventory valuation explicitly as average cost", () => {
    expect(source).toContain('"Stock Value at Average Cost"');
    expect(source).toContain('"Average Cost Value"');
    expect(source).toContain("Current stock snapshot valued at each product's average cost; it is not limited by a movement date range.");
    expect(source).toContain("Inventory quantity multiplied by each product's average cost.");
    expect(source).toContain("Historical quantity valued using each product's average cost.");
  });

  test("keeps current balance independent from movement date filters", () => {
    expect(source).toContain("const currentBalanceFilters = () => {");
    expect(source).toContain("delete filters.date_from;");
    expect(source).toContain("delete filters.date_to;");
    expect(source).toContain("initialFilters={currentBalanceFilters()}");
    expect(source).toContain("showDateRange={false}");
  });

  test("uses dedicated average-cost values and cost currencies for adjustments", () => {
    expect(source).toContain('const costCurrency = row.cost_currency_code || "";');
    expect(source).toContain('"Average Cost Value",\n    "Cost Currency"');
    expect(source).toContain("money(row.average_cost, costCurrency)");
    expect(source).toContain("money(row.cost_value, costCurrency)");
    expect(source).toContain("summary?.currency_totals ?? summary?.by_currency");
    expect(source).toContain("insight.currency_totals.flatMap");
    expect(source).toContain("currencySeriesOf(stockByBranch)");
    expect(source).toContain("currencySeriesOf(stockByCategory)");
  });

  test("shows average cost fields in the separate adjustment audit workspace", () => {
    expect(adjustmentHistorySource).toContain('"Average Cost"');
    expect(adjustmentHistorySource).toContain('"Average-Cost Value"');
    expect(adjustmentHistorySource).toContain('"Cost Currency"');
    expect(adjustmentHistorySource).toContain("item.average_cost, item.cost_currency_code");
    expect(adjustmentHistorySource).toContain("item.cost_value, item.cost_currency_code");
    expect(adjustmentHistorySource).not.toContain("item.total_cost");
    expect(adjustmentHistorySource).not.toContain("item.unit_cost");
  });

  test("keeps received-stock audit values at recorded acquisition cost", () => {
    expect(source).toContain("Received quantities and their recorded acquisition cost.");
    expect(source).toContain('"Unit Cost", "Cost Value", "Currency"');
    expect(source).toContain('summaryValueLabel="Recorded Cost Value"');
  });
});

import { amountColumn, receiptLineCount } from "./FoodIndustrySalesReportModal";

describe("Food Industry report API projections", () => {
  test("renders tendered payment amount supplied by the report API", () => {
    const entry = {
      byCurrency: {
        USD: {
          amount: 10,
          amount_tendered: 12,
          change_amount: 2,
        },
      },
    };

    expect(amountColumn(entry, "USD", "amount")).toBe("10.00");
    expect(amountColumn(entry, "USD", "amount_tendered")).toBe("12.00");
    expect(amountColumn(entry, "USD", "change_amount")).toBe("2.00");
  });

  test("uses the receipt line_count projection without coercing it away", () => {
    expect(receiptLineCount({ line_count: 3 })).toBe("3");
    expect(receiptLineCount({ receipt_lines_count: 0, line_count: 7 })).toBe("0");
  });
});

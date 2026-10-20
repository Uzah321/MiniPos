import {
  getCashMovementDisplayState,
  nullableMoney,
  nullablePercent,
} from "./ProfitAndLossReport";

describe("ProfitAndLossReport currency-safe formatting", () => {
  test("does not turn unavailable amounts into zero", () => {
    expect(nullableMoney(null, "USD")).toBe("—");
    expect(nullablePercent(undefined)).toBe("—");
  });

  test("labels unavailable mixed-currency totals as per currency", () => {
    expect(nullableMoney(null, "USD", true)).toBe("Per currency");
    expect(nullablePercent(null, true)).toBe("Per currency");
  });

  test("still renders a real zero as a monetary or percentage zero", () => {
    expect(nullableMoney(0, "ZWL", true)).toBe("ZWL 0.00");
    expect(nullablePercent(0, true)).toBe("0.00%");
  });

  test("uses the exact mixed and unconverted backend contract", () => {
    expect(getCashMovementDisplayState({
      cash_movement_currency_compatible: false,
      cash_movement_is_mixed_currency: true,
      cash_movement_has_unconverted_amounts: true,
      cash_movement_message: "Cash movements are available per currency.",
    }, {
      currency_compatible: true,
      is_mixed_currency: false,
      has_unconverted_amounts: false,
    })).toEqual({
      currencyCompatible: false,
      isMixedCurrency: true,
      hasUnconvertedAmounts: true,
      message: "Cash movements are available per currency.",
      perCurrency: true,
    });
  });

  test("accepts the cashout summary contract when mirror fields are absent", () => {
    expect(getCashMovementDisplayState({}, {
      currency_compatible: false,
      is_mixed_currency: true,
      has_unconverted_amounts: true,
      message: "Do not add these currencies together.",
    })).toEqual({
      currencyCompatible: false,
      isMixedCurrency: true,
      hasUnconvertedAmounts: true,
      message: "Do not add these currencies together.",
      perCurrency: true,
    });
  });
});

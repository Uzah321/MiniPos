import { canViewCashupFinancialAmounts } from "./CashupPermissions";

describe("cashup financial visibility", () => {
  test("accepts the canonical permission at the report response level", () => {
    expect(canViewCashupFinancialAmounts({
      permissions: { can_view_cashup_financial_amounts: true },
    })).toBe(true);
  });

  test("supports the compatibility flag on an active summary", () => {
    expect(canViewCashupFinancialAmounts({
      permissions: { can_view_expected_cashup_amounts: "1" },
    })).toBe(true);
  });

  test("defaults to denied when the backend does not grant visibility", () => {
    expect(canViewCashupFinancialAmounts({ permissions: {} })).toBe(false);
    expect(canViewCashupFinancialAmounts(null)).toBe(false);
  });
});


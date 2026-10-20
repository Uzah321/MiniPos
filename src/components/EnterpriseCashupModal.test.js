import { isCashPayment } from "./EnterpriseCashupModal";

describe("EnterpriseCashupModal payment classification", () => {
  test("keeps EcoCash and other cash-named digital tenders non-cash", () => {
    expect(isCashPayment({ payment_name: "EcoCash", money_code: "mobile_money" })).toBe(false);
    expect(isCashPayment({ payment_name: "Cashless Wallet", money_code: "wallet" })).toBe(false);
  });

  test("recognises physical cash without relying on a substring match", () => {
    expect(isCashPayment({ payment_name: "Cash", money_code: "cash" })).toBe(true);
    expect(isCashPayment({ payment_name: "USD Cash", money_code: "cash_usd" })).toBe(true);
  });

  test("does not treat drawer bookkeeping rows as tender", () => {
    expect(isCashPayment({ payment_name: "Cash Drawer", money_code: "cash_drawer" })).toBe(false);
  });
});

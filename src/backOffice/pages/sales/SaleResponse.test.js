import { getSaleResponsePayload, resolveCreditedSaleChange } from "./SaleResponse";

describe("POS sale response integrity", () => {
  const payload = {
    sale: { id: "sale-1", invoice_number: "INV-1" },
    receipt_number: "INV-1",
    receipt_url: "/receipts/INV-1.pdf",
    print: { auto_print: true },
  };

  test("parses normal and canonical duplicate full responses identically", () => {
    const normal = { message: "Sale created.", data: payload };
    const duplicate = { message: "Sale already exists.", duplicate: true, data: payload };
    const axiosDuplicate = { data: duplicate };

    expect(getSaleResponsePayload(normal)).toEqual(payload);
    expect(getSaleResponsePayload(duplicate)).toEqual(payload);
    expect(getSaleResponsePayload(axiosDuplicate)).toEqual(payload);
  });

  test("uses the backend's partial account allocation and physical remainder", () => {
    expect(resolveCreditedSaleChange({
      change: 7,
      change_credit_amount: 3,
      credited_change: {
        change_amount: 3,
        returned_change_amount: 7,
      },
    }, 10)).toEqual({
      creditedChange: 3,
      cashChangeReturned: 7,
    });
  });

  test("falls back to computed change only for legacy responses", () => {
    expect(resolveCreditedSaleChange({ change_credit_amount: 4 }, 10)).toEqual({
      creditedChange: 4,
      cashChangeReturned: 6,
    });
  });
});

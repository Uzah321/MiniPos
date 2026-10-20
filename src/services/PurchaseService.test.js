import api from "../utils/apiBack";
import {
  downloadPurchaseDocument,
  downloadPurchaseRegister,
  fetchPurchaseDocumentPdf,
} from "./PurchaseService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn() },
}));

describe("backend purchase downloads", () => {
  let clickSpy;

  beforeEach(() => {
    Object.defineProperty(window.URL, "createObjectURL", {
      configurable: true,
      value: jest.fn(() => "blob:purchase-report"),
    });
    Object.defineProperty(window.URL, "revokeObjectURL", {
      configurable: true,
      value: jest.fn(),
    });
    clickSpy = jest.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
  });

  afterEach(() => {
    clickSpy.mockRestore();
    jest.clearAllMocks();
  });

  test("downloads individual purchase documents from the backend", async () => {
    api.get.mockResolvedValue({
      data: new Blob(["pdf"], { type: "application/pdf" }),
      headers: {
        "content-type": "application/pdf",
        "content-disposition": 'attachment; filename="purchase-order-PO-100.pdf"',
      },
    });

    await downloadPurchaseDocument("order", "order-1");

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/purchases/orders/order-1/pdf",
      expect.objectContaining({
        params: { download: 1 },
        responseType: "blob",
      })
    );
    expect(clickSpy).toHaveBeenCalled();
  });

  test("returns the backend PDF blob and filename for native sharing", async () => {
    const blob = new Blob(["pdf"], { type: "application/pdf" });
    api.get.mockResolvedValue({
      data: blob,
      headers: {
        "content-type": "application/pdf",
        "content-disposition": 'inline; filename="goods-received-GRN-25.pdf"',
      },
    });

    const result = await fetchPurchaseDocumentPdf("receipt", "receipt-25");

    expect(result.blob).toBe(blob);
    expect(result.fileName).toBe("goods-received-GRN-25.pdf");
    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/purchases/receipts/receipt-25/pdf",
      expect.objectContaining({ params: { download: 0 }, responseType: "blob" })
    );
  });

  test("exports the filtered purchase register from the backend", async () => {
    api.get.mockResolvedValue({
      data: new Blob(["xlsx"]),
      headers: { "content-disposition": 'attachment; filename="supplier-invoices.xlsx"' },
    });

    await downloadPurchaseRegister("invoices", "xlsx", {
      date_from: "2026-09-01",
      date_to: "2026-09-30",
      branch_id: "",
    });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/purchases/reports/invoices/export/xlsx",
      expect.objectContaining({
        params: { date_from: "2026-09-01", date_to: "2026-09-30" },
        responseType: "blob",
      })
    );
  });
});

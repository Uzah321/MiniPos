import api from "../utils/apiBack";
import {
  captureCreditCustomerPosPayment,
  captureCreditCustomerPayment,
  downloadCreditCustomerPaymentReceipt,
  getCreditCustomerPosAccount,
  getCreditCustomersForPos,
} from "./CreditCustomerService";
import { downloadBackendReport } from "../utils/BackendReportDownload";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock("../utils/BackendReportDownload", () => ({
  downloadBackendReport: jest.fn(),
}));

describe("POS credit customer account services", () => {
  beforeEach(() => jest.clearAllMocks());

  test("loads account-payment customers without treating blocked borrowing as ineligible", async () => {
    api.get.mockResolvedValue({ data: { data: [{ id: "customer-1", status: "blocked" }] } });

    const result = await getCreditCustomersForPos({ purpose: "account_payment", q: "alex", limit: 50 });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/credit-customers/pos-lookup",
      expect.objectContaining({
        params: expect.objectContaining({ purpose: "account_payment", q: "alex", limit: 50 }),
      })
    );
    expect(result[0].status).toBe("blocked");
  });

  test("loads the till-bound account summary and history", async () => {
    api.get.mockResolvedValue({ data: { data: { customer: { id: "customer-1" }, statement: { rows: [] } } } });

    const result = await getCreditCustomerPosAccount("customer-1");

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/credit-customers/customer-1/pos-account",
      expect.objectContaining({ params: {} })
    );
    expect(result.customer.id).toBe("customer-1");
  });

  test("keeps the full payment envelope so idempotency and credit results reach the UI", async () => {
    const envelope = {
      idempotent_replay: false,
      data: { payment_number: "CCP-1" },
      account: { applied_to_debt: 5, credit_created: 2 },
      statement: { rows: [] },
    };
    api.post.mockResolvedValue({ data: envelope });

    const result = await captureCreditCustomerPosPayment("customer-1", {
      amount: 7,
      currency_code: "USD",
      client_payment_id: "attempt-1",
    });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/credit-customers/customer-1/pos-payments",
      expect.objectContaining({ client_payment_id: "attempt-1" }),
      expect.any(Object)
    );
    expect(result).toEqual(envelope);
  });

  test("keeps the Back Office payment envelope and sends its stable attempt id", async () => {
    const envelope = {
      idempotent_replay: true,
      data: { id: "payment-1", payment_number: "CCP-1" },
      payment_receipt: { payment_id: "payment-1" },
    };
    api.post.mockResolvedValue({ data: envelope });

    const result = await captureCreditCustomerPayment("customer-1", {
      amount: 10,
      currency_code: "USD",
      client_payment_id: "bo-attempt-1",
    });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/credit-customers/customer-1/payments",
      expect.objectContaining({ client_payment_id: "bo-attempt-1" }),
      expect.any(Object)
    );
    expect(result).toEqual(envelope);
  });

  test("opens the backend 80mm payment confirmation by default", async () => {
    downloadBackendReport.mockResolvedValue({ data: new Blob(["pdf"]) });

    await downloadCreditCustomerPaymentReceipt("customer-1", "payment-1");

    expect(downloadBackendReport).toHaveBeenCalledWith(expect.objectContaining({
      client: api,
      url: "/api/pos/credit-customers/customer-1/payments/payment-1/receipt/pdf",
      params: { paper: "80mm", download: 0 },
      delivery: "open",
      mimeType: "application/pdf",
    }));
  });

  test("downloads the requested payment confirmation from the backend", async () => {
    downloadBackendReport.mockResolvedValue({ data: new Blob(["pdf"]) });

    await downloadCreditCustomerPaymentReceipt(
      "customer-1",
      "payment-1",
      { paper: "a4" },
      "download"
    );

    expect(downloadBackendReport).toHaveBeenCalledWith(expect.objectContaining({
      params: { paper: "a4", download: 1 },
      delivery: "download",
    }));
  });
});

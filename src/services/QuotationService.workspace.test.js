import api from "../utils/apiBack";
import {
  getQuotations,
  lookupQuotationByCode,
  markQuotationConverted,
  saveQuotation,
} from "./QuotationService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
    put: jest.fn(),
  },
}));

describe("QuotationService workspace isolation", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: {} });
    api.post.mockResolvedValue({ data: {} });
    api.put.mockResolvedValue({ data: {} });
  });

  test("Front Office uses only the till-scoped quotation surface", async () => {
    window.history.pushState({}, "", "/front");

    await getQuotations({ status: "issued" });
    await saveQuotation({ customer_name: "Counter customer" });
    await lookupQuotationByCode("Q 100");
    await markQuotationConverted("Q 100", "sale-1");

    expect(api.get).toHaveBeenNthCalledWith(
      1,
      "/api/pos/front/quotations",
      expect.objectContaining({
        params: { status: "issued" },
        salexaWorkspace: "front-office",
      }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      1,
      "/api/pos/front/quotations",
      { customer_name: "Counter customer" },
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
    expect(api.get).toHaveBeenNthCalledWith(
      2,
      "/api/pos/front/quotations/code/Q%20100",
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      2,
      "/api/pos/front/quotations/code/Q%20100/convert",
      { sales_transaction_id: "sale-1" },
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
  });

  test("Back Office keeps using the management quotation surface", async () => {
    window.history.pushState({}, "", "/admin");

    await getQuotations();
    await saveQuotation({ id: "quote-1", customer_name: "Account customer" });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/quotations",
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
    expect(api.put).toHaveBeenCalledWith(
      "/api/pos/quotations/quote-1",
      expect.objectContaining({ id: "quote-1" }),
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
  });
});

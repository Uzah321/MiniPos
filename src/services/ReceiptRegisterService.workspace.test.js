import api from "../utils/apiBack";
import {
  getReceiptRegister,
  getReceiptRegisterSale,
  prepareReceiptPdf,
} from "./ReceiptRegisterService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
  },
}));

describe("ReceiptRegisterService workspace selection", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: {} });
  });

  test.each([
    ["/front", "front-office"],
    ["/admin", "back-office"],
  ])("uses the %s page workspace explicitly", async (path, expectedWorkspace) => {
    window.history.pushState({}, "", path);

    await getReceiptRegister({ date_from: "2026-10-06" });
    await getReceiptRegisterSale("sale-1");
    await prepareReceiptPdf("sale-1");

    expect(api.get).toHaveBeenNthCalledWith(
      1,
      "/api/pos/receipts/register",
      expect.objectContaining({
        params: { date_from: "2026-10-06" },
        salexaWorkspace: expectedWorkspace,
      }),
    );
    expect(api.get).toHaveBeenNthCalledWith(
      2,
      "/api/pos/receipts/register/sale-1",
      expect.objectContaining({ salexaWorkspace: expectedWorkspace }),
    );
    expect(api.get).toHaveBeenNthCalledWith(
      3,
      "/api/pos/receipts/register/sale-1/pdf",
      expect.objectContaining({ salexaWorkspace: expectedWorkspace }),
    );
  });
});

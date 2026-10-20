import api from "../utils/apiBack";
import {
  downloadCashoutReportExport,
  downloadCashupHistoryExport,
} from "./CashupService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
  },
}));

describe("cashup report downloads", () => {
  let clickSpy;

  beforeEach(() => {
    api.get.mockResolvedValue({
      data: new Blob(["report"]),
      headers: { "content-disposition": 'attachment; filename="report.pdf"' },
    });

    Object.defineProperty(window.URL, "createObjectURL", {
      configurable: true,
      value: jest.fn(() => "blob:report"),
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

  test("accepts filters before the format without passing a string as Axios params", async () => {
    await downloadCashupHistoryExport(
      { date_from: "2026-09-01", date_to: "2026-09-30", empty: "" },
      "pdf"
    );

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/cashups/export/pdf",
      expect.objectContaining({
        params: { date_from: "2026-09-01", date_to: "2026-09-30" },
        responseType: "blob",
      })
    );
  });

  test("accepts the format before filters for cash-movement exports", async () => {
    await downloadCashoutReportExport("excel", {
      movement_type: "cash_out",
      currency_codes: ["USD", "ZWG"],
    });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/cashouts/report/export/excel",
      expect.objectContaining({
        params: {
          movement_type: "cash_out",
          currency_codes: ["USD", "ZWG"],
        },
        responseType: "blob",
      })
    );
  });
});

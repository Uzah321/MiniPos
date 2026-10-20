import api from "../utils/apiBack";
import {
  beginBackStockMonthEndStockTake,
  downloadBackStockMonthEndBalanceSnapshot,
  downloadBackStockMonthEndCountSheet,
  getBackStockMonthEndSnapshot,
  uploadBackStockMonthEndCountSheet,
} from "./HospitalityMonthEndService";
import {
  getFrontStockClosingSnapshot,
  saveFrontStockDailyClosing,
} from "./FrontStockDailyClosingService";

jest.mock("../utils/apiBack", () => ({
  get: jest.fn(),
  post: jest.fn(),
}));

describe("Hospitality stock-take backend routes", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: [] }, headers: {} });
    api.post.mockResolvedValue({ data: { data: {} } });
    window.URL.createObjectURL = jest.fn(() => "blob:stock-take");
    window.URL.revokeObjectURL = jest.fn();
    jest.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  test("Front Stock count reads and saves through the daily-closing endpoints", async () => {
    await getFrontStockClosingSnapshot({ closing_date: "2026-10-02", stock_area: "front" });
    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/front-stock/daily-closings/snapshot",
      expect.objectContaining({ params: { closing_date: "2026-10-02", stock_area: "front" } })
    );

    const payload = { closing_date: "2026-10-02", stock_area: "front", lines: [{ product_id: "p1", counted_quantity: 2 }] };
    await saveFrontStockDailyClosing(payload);
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/front-stock/daily-closings",
      payload,
      expect.objectContaining({ headers: expect.any(Object) })
    );
  });

  test("Back Stock snapshot, start, upload and downloads use the formal month-end endpoints", async () => {
    await getBackStockMonthEndSnapshot({ month_end_date: "2026-10-31" });
    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/stock-takes/month-end/back-stock/snapshot",
      expect.objectContaining({ params: { month_end_date: "2026-10-31" } })
    );

    await beginBackStockMonthEndStockTake({ month_end_date: "2026-10-31", stock_area: "back" });
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/stock-takes/month-end/back-stock/begin-trading",
      expect.objectContaining({ month_end_date: "2026-10-31", stock_area: "back" }),
      expect.objectContaining({ headers: expect.any(Object) })
    );

    await uploadBackStockMonthEndCountSheet(new File(["product_id,counted_quantity"], "count.csv"), {
      month_end_date: "2026-10-31",
      stock_area: "back",
    });
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/stock-takes/month-end/back-stock/upload-trading-count-sheets",
      expect.any(FormData),
      expect.objectContaining({ headers: expect.objectContaining({ "Content-Type": "multipart/form-data" }) })
    );

    await downloadBackStockMonthEndBalanceSnapshot({ month_end_date: "2026-10-31" });
    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/stock-takes/month-end/back-stock/download-balance-snapshot",
      expect.objectContaining({ responseType: "blob" })
    );

    await downloadBackStockMonthEndCountSheet({ month_end_date: "2026-10-31" });
    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/stock-takes/month-end/back-stock/download-trading-count-sheet",
      expect.objectContaining({ responseType: "blob" })
    );
  });
});

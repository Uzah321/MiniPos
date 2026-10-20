import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";
import {
  downloadProductPriceAdjustmentHistory,
  downloadStockAdjustmentHistory,
} from "./ProductService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn() },
}));

jest.mock("../utils/BackendReportDownload", () => ({
  downloadBackendReport: jest.fn(),
}));

describe("history report backend downloads", () => {
  beforeEach(() => jest.clearAllMocks());

  test("downloads product price history as a backend PDF with its product scope", async () => {
    downloadBackendReport.mockResolvedValue({ data: new Blob(["pdf"]) });

    await downloadProductPriceAdjustmentHistory("product-1", "pdf", {
      date_from: "2026-09-01",
      date_to: "2026-09-30",
    });

    expect(downloadBackendReport).toHaveBeenCalledWith(expect.objectContaining({
      client: api,
      url: "/api/pos/products/product-1/price-adjustments/export/pdf",
      params: { date_from: "2026-09-01", date_to: "2026-09-30" },
      extension: "pdf",
      mimeType: "application/pdf",
    }));
  });

  test("normalizes product price history Excel downloads to xlsx", async () => {
    downloadBackendReport.mockResolvedValue({ data: new Blob(["xlsx"]) });

    await downloadProductPriceAdjustmentHistory("product-1", "excel", {});

    expect(downloadBackendReport).toHaveBeenCalledWith(expect.objectContaining({
      url: "/api/pos/products/product-1/price-adjustments/export/xlsx",
      extension: "xlsx",
      mimeType: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    }));
  });

  test("downloads filtered stock adjustment history from the backend", async () => {
    downloadBackendReport.mockResolvedValue({ data: new Blob(["xlsx"]) });
    const filters = {
      branch_id: "branch-1",
      q: "flour",
      adjustment_type: "decrease",
      date_from: "2026-10-01",
      date_to: "2026-10-02",
    };

    await downloadStockAdjustmentHistory("xlsx", filters);

    expect(downloadBackendReport).toHaveBeenCalledWith(expect.objectContaining({
      client: api,
      url: "/api/pos/stock-adjustment-history/export/xlsx",
      params: filters,
      extension: "xlsx",
      mimeType: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    }));
  });
});

import {
  buildKitchenHtml,
  dispatchSalePrints,
} from "./FastFoodPrintDispatcher";
import { qzPrintHtml } from "./QzService";
import { acknowledgeKitchenPrintJob } from "./KitchenPrintJobService";

jest.mock("./QzService", () => ({
  qzPrintPdfUrl: jest.fn(),
  qzPrintHtml: jest.fn(),
  qzPrintRawText: jest.fn(),
}));

jest.mock("./KitchenPrintJobService", () => ({
  acknowledgeKitchenPrintJob: jest.fn(),
}));

describe("hospitality kitchen print dispatch", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    qzPrintHtml.mockResolvedValue(true);
    acknowledgeKitchenPrintJob.mockResolvedValue({});
  });

  test("prints the configured number of QZ copies and acknowledges the job", async () => {
    const response = {
      data: {
        data: {
          kitchen_print: {
            auto_print: true,
            copies: 2,
            printer: { printer_name: "Kitchen Pass" },
            job: { id: "job-1" },
            payload: { order_number: "Q-84", items: [] },
            html: "<html><body>Kitchen order</body></html>",
          },
        },
      },
    };

    await expect(dispatchSalePrints(response, { printCustomer: false })).resolves.toEqual({
      customer: false,
      kitchen: true,
    });

    expect(qzPrintHtml).toHaveBeenCalledTimes(2);
    expect(qzPrintHtml).toHaveBeenNthCalledWith(1, expect.objectContaining({
      printerName: "Kitchen Pass",
      jobName: "Kitchen Q-84 (1/2)",
    }));
    expect(acknowledgeKitchenPrintJob).toHaveBeenCalledWith("job-1", "printed");
  });

  test("marks the persisted print job failed when QZ rejects it", async () => {
    qzPrintHtml.mockRejectedValue(new Error("QZ offline"));
    const response = {
      data: {
        data: {
          kitchen_print: {
            auto_print: true,
            printer: { printer_name: "Kitchen Pass" },
            job: { id: "job-2" },
            html: "<html><body>Kitchen order</body></html>",
          },
        },
      },
    };

    await expect(dispatchSalePrints(response, { printCustomer: false })).rejects.toThrow("QZ offline");
    expect(acknowledgeKitchenPrintJob).toHaveBeenCalledWith("job-2", "failed", "QZ offline");
  });

  test("renders hospitality service and table details safely", () => {
    const html = buildKitchenHtml({
      order_number: "Q-12",
      service_type: "dine_in",
      table: { name: "Patio <1>" },
      items: [{ name: "Classic Pizza", quantity: 1 }],
    });

    expect(html).toContain("DINE IN");
    expect(html).toContain("Patio &lt;1&gt;");
    expect(html).toContain("1 x Classic Pizza");
  });
});

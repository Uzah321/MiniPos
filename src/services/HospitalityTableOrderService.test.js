import api from "../utils/apiBack";
import {
  clearHospitalityTableOrder,
  createHospitalityTableOrder,
  getHospitalityTableOrders,
  getHospitalityTableService,
  hangHospitalityTableOrder,
  recallHospitalityTableOrder,
  sendHospitalityTableOrderToKitchen,
  updateHospitalityTableOrder,
} from "./HospitalityTableOrderService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}));

describe("HospitalityTableOrderService", () => {
  beforeEach(() => jest.clearAllMocks());

  test("loads till-scoped table service and open/hanging orders", async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: { settings: {}, tables: [] } } })
      .mockResolvedValueOnce({ data: { data: [{ id: "order-1" }] } });

    await expect(getHospitalityTableService()).resolves.toEqual({ settings: {}, tables: [] });
    await expect(getHospitalityTableOrders()).resolves.toEqual([{ id: "order-1" }]);
    expect(api.get).toHaveBeenNthCalledWith(1, "/api/pos/hospitality/table-service", { salexaWorkspace: "front-office" });
    expect(api.get).toHaveBeenNthCalledWith(2, "/api/pos/hospitality/table-orders", expect.objectContaining({
      salexaWorkspace: "front-office",
      params: expect.objectContaining({ status: "open,hanging" }),
    }));
  });

  test("creates and revision-controls the mutable order snapshot", async () => {
    api.post.mockResolvedValue({ data: { data: { id: "order-1", revision: 1 } } });
    api.put.mockResolvedValue({ data: { data: { id: "order-1", revision: 2 } } });
    const payload = {
      client_request_id: "client-1",
      service_type: "dine_in",
      assignment_mode: "manual",
      table_id: "table-1",
      currency_code: "USD",
      items: [{ product_id: "product-1", quantity: 1, unit_price: 4 }],
    };

    await createHospitalityTableOrder(payload);
    await updateHospitalityTableOrder("order-1", { ...payload, expected_revision: 1 });

    expect(api.post).toHaveBeenCalledWith("/api/pos/hospitality/table-orders", payload, { salexaWorkspace: "front-office" });
    expect(api.put).toHaveBeenCalledWith(
      "/api/pos/hospitality/table-orders/order-1",
      expect.objectContaining({ expected_revision: 1 }),
      { salexaWorkspace: "front-office" },
    );
  });

  test("uses revisions and command ids for hang, recall, kitchen send and audited clear", async () => {
    api.post.mockResolvedValue({ data: { data: { id: "order-1", revision: 4 } } });

    await hangHospitalityTableOrder("order-1", 1);
    await recallHospitalityTableOrder("order-1", 2);
    await sendHospitalityTableOrderToKitchen("order-1", { expected_revision: 3, command_id: "kitchen-1" });
    await clearHospitalityTableOrder("order-1", {
      expected_revision: 4,
      reason: "Duplicate order",
      manager_approval_id: "approval-1",
      command_id: "clear-1",
    });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/hospitality/table-orders/order-1/send-to-kitchen",
      { expected_revision: 3, command_id: "kitchen-1" },
      { salexaWorkspace: "front-office" },
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/hospitality/table-orders/order-1/clear",
      expect.objectContaining({
        expected_revision: 4,
        reason: "Duplicate order",
        manager_approval_id: "approval-1",
        command_id: "clear-1",
      }),
      { salexaWorkspace: "front-office" },
    );
  });
});


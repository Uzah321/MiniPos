import api from "../utils/apiBack";
import {
  getCurrentTradingDay,
  getDayEndPreflight,
  getTradingDayEndProgress,
  runTradingDayEnd,
} from "./TradingDayEndEnterpriseService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

describe("TradingDayEndEnterpriseService workspace isolation", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: {} } });
    api.post.mockResolvedValue({ data: { data: {} } });
  });

  test("Front Office uses the till-scoped day-end surface", async () => {
    window.history.pushState({}, "", "/front");

    await getCurrentTradingDay();
    await getDayEndPreflight({ branch_id: "local-branch" });
    await runTradingDayEnd({ branch_id: "local-branch" });
    await getTradingDayEndProgress("run key");

    expect(api.get).toHaveBeenNthCalledWith(
      1,
      "/api/pos/trading-day/current",
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
    expect(api.get).toHaveBeenNthCalledWith(
      2,
      "/api/pos/front/trading-day/day-end/preflight",
      expect.objectContaining({
        params: { branch_id: "local-branch" },
        salexaWorkspace: "front-office",
      }),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/front/trading-day/day-end",
      { branch_id: "local-branch" },
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
    expect(api.get).toHaveBeenNthCalledWith(
      3,
      "/api/pos/front/trading-day/day-end/progress/run%20key",
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
  });

  test("Back Office keeps the existing management day-end routes", async () => {
    window.history.pushState({}, "", "/admin");

    await getDayEndPreflight();
    await runTradingDayEnd({ branch_id: "managed-branch" });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/trading-day/day-end/preflight",
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/trading-day/day-end",
      { branch_id: "managed-branch" },
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
  });
});

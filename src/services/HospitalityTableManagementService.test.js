import api from "../utils/apiBack";
import {
  createHospitalityTable,
  getHospitalityTables,
  getHospitalityTableServiceSettings,
  updateHospitalityTable,
  updateHospitalityTableServiceSettings,
} from "./HospitalityTableManagementService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}));

const scope = { branch_id: "branch-1", shop_id: "shop-1" };

describe("Back Office hospitality table management service", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: [] } });
    api.post.mockResolvedValue({ data: { data: {} } });
    api.put.mockResolvedValue({ data: { data: {} } });
  });

  test("always sends both branch and shop scope", async () => {
    await getHospitalityTableServiceSettings(scope);
    await getHospitalityTables(scope);
    await updateHospitalityTableServiceSettings(scope, { assignment_mode: "auto" });
    await createHospitalityTable(scope, { code: "T01", name: "Table 1", capacity: 4 });
    await updateHospitalityTable("table/id", scope, { is_active: false });

    expect(api.get).toHaveBeenNthCalledWith(
      1,
      "/api/pos/management/hospitality/table-service/settings",
      { salexaWorkspace: "back-office", params: scope },
    );
    expect(api.get).toHaveBeenNthCalledWith(
      2,
      "/api/pos/management/hospitality/table-service/tables",
      { salexaWorkspace: "back-office", params: scope },
    );
    expect(api.put).toHaveBeenNthCalledWith(
      1,
      "/api/pos/management/hospitality/table-service/settings",
      expect.objectContaining({ ...scope, assignment_mode: "auto" }),
      { salexaWorkspace: "back-office" },
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/management/hospitality/table-service/tables",
      expect.objectContaining({ ...scope, code: "T01", table_type: "dining" }),
      { salexaWorkspace: "back-office" },
    );
    expect(api.put).toHaveBeenNthCalledWith(
      2,
      "/api/pos/management/hospitality/table-service/tables/table%2Fid",
      expect.objectContaining({ ...scope, is_active: false, table_type: "dining" }),
      { salexaWorkspace: "back-office" },
    );
  });

  test("fails locally instead of issuing an unscoped management request", async () => {
    await expect(getHospitalityTables({ branch_id: "branch-1" })).rejects.toThrow(/both branch and shop scope/i);
    expect(api.get).not.toHaveBeenCalled();
  });
});

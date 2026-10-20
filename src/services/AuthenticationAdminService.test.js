import api from "../utils/apiBack";
import {
  disableAuthenticationPolicy,
  getEffectiveAuthenticationPolicy,
  grantAccessAssignment,
  listAccessAssignments,
  listAuthenticationPolicies,
  publishAuthenticationPolicy,
  revokeAccessAssignment,
} from "./AuthenticationAdminService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

describe("authentication administration service", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: [] } });
    api.post.mockResolvedValue({ data: { data: {} } });
  });

  test("uses Back Office identity for assignment management", async () => {
    await listAccessAssignments({ status: "active", per_page: 100, user_q: "manager" });
    await grantAccessAssignment({ user_id: "user-1", role_id: "role-1", scope_type: "shop", target_id: "shop-1" });
    await revokeAccessAssignment("assignment/1", { reason: "Role changed" });

    expect(api.get).toHaveBeenCalledWith(
      "/api/auth-admin/access-assignments",
      expect.objectContaining({
        params: { status: "active", per_page: 100, user_q: "manager" },
        salexaWorkspace: "back-office",
      }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      1,
      "/api/auth-admin/access-assignments",
      expect.objectContaining({ scope_type: "shop", target_id: "shop-1" }),
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      2,
      "/api/auth-admin/access-assignments/assignment%2F1/revoke",
      { reason: "Role changed" },
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
  });

  test("uses the versioned authentication policy contracts", async () => {
    await listAuthenticationPolicies({ policy_key: "credential" });
    await getEffectiveAuthenticationPolicy({ policy_key: "credential", scope_type: "shop", target_id: "shop-1" });
    await publishAuthenticationPolicy({ policy_key: "credential", scope_type: "shop", target_id: "shop-1", settings: {} });
    await disableAuthenticationPolicy("policy/1");

    expect(api.get).toHaveBeenNthCalledWith(
      1,
      "/api/auth-admin/policies",
      expect.objectContaining({ params: { policy_key: "credential" } }),
    );
    expect(api.get).toHaveBeenNthCalledWith(
      2,
      "/api/auth-admin/policies/effective",
      expect.objectContaining({ params: expect.objectContaining({ target_id: "shop-1" }) }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      1,
      "/api/auth-admin/policies",
      expect.objectContaining({ policy_key: "credential", settings: {} }),
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      2,
      "/api/auth-admin/policies/policy%2F1/disable",
      {},
      expect.any(Object),
    );
  });
});

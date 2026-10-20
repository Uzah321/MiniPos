import api from "../utils/apiBack";
import { getWorkspaceBootstrap } from "./WorkspaceService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn() },
}));

describe("workspace bootstrap", () => {
  beforeEach(() => jest.clearAllMocks());

  test("merges authoritative till configuration into a Front Office workspace", async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: { user: { id: 1 }, hierarchy: { company: { id: 2, name: "Simbisa" } } } } })
      .mockResolvedValueOnce({ data: { data: {
        hierarchy: { brand: { id: 3, name: "Pizza Inn", logo_url: "/pizza.svg" }, shop: { id: 4, name: "CBD" } },
        authentication: { pos_login_mode: "both" },
        menu: { items: [{ id: 9, name: "Classic Pizza" }] },
      } } });

    const result = await getWorkspaceBootstrap(null, { workspace: "front-office" });

    expect(api.get).toHaveBeenNthCalledWith(1, "/api/pos/workspace/bootstrap", expect.objectContaining({ salexaWorkspace: "front-office" }));
    expect(api.get).toHaveBeenNthCalledWith(2, "/api/pos/configuration/bootstrap", { salexaWorkspace: "front-office" });
    expect(result.user.id).toBe(1);
    expect(result.branding.logo).toBe("/pizza.svg");
    expect(result.authentication.pos_login_mode).toBe("both");
    expect(result.menu.items[0].name).toBe("Classic Pizza");
  });

  test("fails closed whenever authoritative terminal configuration is unavailable", async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: { hierarchy: { shop: { id: 4 } }, menu: { items: [] } } } })
      .mockRejectedValueOnce({ response: { status: 404 } });

    await expect(getWorkspaceBootstrap(null, { workspace: "front-office" })).rejects.toEqual(
      expect.objectContaining({ response: { status: 404 } }),
    );

    api.get
      .mockResolvedValueOnce({ data: { data: { hierarchy: { shop: { id: 4 } } } } })
      .mockRejectedValueOnce({ response: { status: 403 } });

    await expect(getWorkspaceBootstrap(null, { workspace: "front-office" })).rejects.toEqual(expect.objectContaining({ response: { status: 403 } }));
  });

  test("Back Office stays username/password scoped and does not call the till endpoint", async () => {
    api.get.mockResolvedValueOnce({ data: { data: { authentication: { pos_login_mode: "code" } } } });

    const result = await getWorkspaceBootstrap(null, { workspace: "back-office" });

    expect(api.get).toHaveBeenCalledTimes(1);
    expect(result.authentication.pos_login_mode).toBe("code");
  });
});


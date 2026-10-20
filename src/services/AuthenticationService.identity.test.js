import api from "../utils/apiBack";
import { getPosLoginModeSetting, loginUser, selectBackOfficeCompany } from "./AuthenticationService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

describe("workspace-aware login identity", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    api.post.mockResolvedValue({ data: { token: "token" } });
  });

  test("front login sends paired till identity even when the modal is rendered at root", async () => {
    localStorage.setItem("salexa_till_token", "paired-till-token");

    await loginUser({
      workspace: "front-office",
      login_mode: "code",
      login_code: "1234",
      branch_id: "branch-1",
    });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos-auth/front-office/login",
      expect.objectContaining({ login_mode: "code", login_code: "1234", branch_id: "branch-1" }),
      expect.objectContaining({
        salexaWorkspace: "front-office",
        headers: expect.objectContaining({ "X-Till-Token": "paired-till-token" }),
      }),
    );
    expect(api.post.mock.calls[0][1]).toHaveProperty("workspace", "front-office");
  });

  test("front login preserves a policy-compliant code longer than four characters", async () => {
    localStorage.setItem("salexa_till_token", "paired-till-token");

    await loginUser({
      workspace: "front-office",
      login_mode: "code",
      login_code: "12345678",
      branch_id: "branch-1",
    });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos-auth/front-office/login",
      expect.objectContaining({ login_code: "12345678" }),
      expect.any(Object),
    );
  });

  test("back login ignores a supplied legacy company code and resolves the account server-side", async () => {
    localStorage.setItem("salexa_company_code", "STALE-COMPANY");

    await loginUser({
      workspace: "back-office",
      company_code: "acme",
      username: "admin",
      password: "secret",
    });

    expect(api.post.mock.calls[0][1]).toEqual(expect.objectContaining({
      login_mode: "password",
      username: "admin",
      password: "secret",
    }));
    expect(api.post.mock.calls[0][1]).not.toHaveProperty("company_code");
    expect(api.post.mock.calls[0][1]).not.toHaveProperty("company_id");
    expect(api.post.mock.calls[0][0]).toBe("/api/pos-auth/back-office/login");
    expect(api.post.mock.calls[0][2]).toEqual(expect.objectContaining({
      salexaWorkspace: "back-office",
      salexaUnscopedPlatform: true,
      headers: {},
    }));
  });

  test("companyless Back Office login explicitly suppresses stale tenant identity", async () => {
    localStorage.setItem("salexa_company_code", "STALE-COMPANY");

    await loginUser({
      workspace: "back-office",
      company_code: "",
      username: "platform-owner",
      password: "secret",
    });

    expect(api.post.mock.calls[0][1]).not.toHaveProperty("company_code");
    expect(api.post.mock.calls[0][0]).toBe("/api/pos-auth/back-office/login");
    expect(api.post.mock.calls[0][2]).toEqual(expect.objectContaining({
      salexaWorkspace: "back-office",
      salexaUnscopedPlatform: true,
      headers: {},
    }));
  });

  test("platform company selection uses only the bootstrap bearer and opaque company id", async () => {
    localStorage.setItem("salexa_company_code", "STALE-COMPANY");

    await selectBackOfficeCompany({
      selectionToken: "selection-token",
      companyId: "company-uuid",
    });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos-auth/back-office/select-company",
      { company_id: "company-uuid" },
      expect.objectContaining({
        headers: { Authorization: "Bearer selection-token" },
        salexaWorkspace: "back-office",
        salexaUnscopedPlatform: true,
        servenestBootstrapAuthorization: true,
      }),
    );
    expect(api.post.mock.calls[0][1]).not.toHaveProperty("company_code");
  });

  test("front login policy exposes effective code rules from the till bootstrap", async () => {
    api.get.mockResolvedValueOnce({
      data: {
        authentication: {
          pos_login_mode: "both",
          credential_policy: {
            login_code: {
              minimum_length: 6,
              maximum_length: 10,
              numeric_only: false,
              reject_sequences: true,
            },
          },
        },
      },
    });

    await expect(getPosLoginModeSetting({
      workspace: "front-office",
      includePolicy: true,
    })).resolves.toEqual({
      login_mode: "both",
      credential_policy: {
        login_code: {
          minimum_length: 6,
          maximum_length: 10,
          numeric_only: false,
          reject_repeated_digits: false,
          reject_sequences: true,
        },
      },
    });
  });
});

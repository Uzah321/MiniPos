import {
  clearAuthenticationForWorkspace,
  clearWorkspaceAuthentication,
  getLicenseFailure,
  isAuthenticationSessionFailure,
  isPosLoginRequest,
  LICENSE_LOGOUT_STORAGE_KEY,
} from "./apiBack";

describe("API license failure handling", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  test("recognizes explicit structured license codes", () => {
    expect(
      getLicenseFailure({
        response: {
          status: 423,
          data: {
            message: "License expired",
            license: { code: "LICENSE_EXPIRED", status: "expired" },
          },
        },
      })
    ).toEqual(expect.objectContaining({ code: "LICENSE_EXPIRED", status: "expired" }));

    expect(
      getLicenseFailure({
        response: { status: 403, data: { code: "LICENSE_MISSING" } },
      })
    ).toEqual(expect.objectContaining({ code: "LICENSE_MISSING" }));

    ["COMPANY_INACTIVE", "LICENSE_NOT_STARTED", "LICENSE_TAMPERED"].forEach((code) => {
      expect(getLicenseFailure({
        response: { status: 423, data: { code, force_logout: true } },
      })).toEqual(expect.objectContaining({ code }));
    });
  });

  test.each([
    ["DAY_END_RUNNING", "Day End is already running."],
    ["SHIFT_SALES_CUTOFF_REACHED", "This shift can no longer accept sales."],
  ])("does not treat operational HTTP 423 %s as a license logout", (code, message) => {
    expect(getLicenseFailure({
      response: { status: 423, data: { code, message } },
    })).toBeNull();
  });

  test("identifies login failures that must remain on the activation screen", () => {
    expect(
      isPosLoginRequest({ config: { url: "/api/pos-auth/login?workspace=back-office" } })
    ).toBe(true);
    expect(
      isPosLoginRequest({ config: { url: "https://pos.test/api/pos-auth/login/" } })
    ).toBe(true);
    expect(isPosLoginRequest({ config: { url: "/api/pos/license/status" } })).toBe(false);
  });

  test("distinguishes an expired login from background sync and business 401 responses", () => {
    expect(isAuthenticationSessionFailure({
      response: { status: 401, data: { message: "Unauthenticated." } },
    })).toBe(true);
    expect(isAuthenticationSessionFailure({
      response: { status: 401, data: { code: "AUTH_TOKEN_REVOKED" } },
    })).toBe(true);
    expect(isAuthenticationSessionFailure({
      response: { status: 401, data: { code: "SYNC_LOCAL_CONTROL_REQUIRED" } },
    })).toBe(false);
    expect(isAuthenticationSessionFailure({
      response: { status: 401, data: { message: "Manager approval is required." } },
    })).toBe(false);
    expect(isAuthenticationSessionFailure({
      config: { url: "/api/pos/sync/status" },
      response: { status: 401, data: { message: "Unauthenticated." } },
    })).toBe(false);
  });

  test("clears normal sessions while preserving a superadmin workspace", () => {
    localStorage.setItem("front_authToken", "normal-token");
    localStorage.setItem("front_user", JSON.stringify({ role: { name: "cashier" } }));
    localStorage.setItem("back_authToken", "super-token");
    localStorage.setItem("back_user", JSON.stringify({ is_super_admin: true }));

    clearWorkspaceAuthentication({ failure: { code: "LICENSE_EXPIRED" } });

    expect(localStorage.getItem("front_authToken")).toBeNull();
    expect(localStorage.getItem("front_user")).toBeNull();
    expect(localStorage.getItem("back_authToken")).toBe("super-token");
    expect(JSON.parse(localStorage.getItem("back_user"))).toEqual({ is_super_admin: true });
    expect(localStorage.getItem(LICENSE_LOGOUT_STORAGE_KEY)).toContain("LICENSE_EXPIRED");
  });

  test("clears only the workspace whose token is invalid", () => {
    localStorage.setItem("front_authToken", "front-token");
    localStorage.setItem("front_user", JSON.stringify({ id: "front-user" }));
    localStorage.setItem("back_authToken", "back-token");
    localStorage.setItem("back_user", JSON.stringify({ id: "back-user" }));

    clearAuthenticationForWorkspace("back-office");

    expect(localStorage.getItem("back_authToken")).toBeNull();
    expect(localStorage.getItem("back_user")).toBeNull();
    expect(localStorage.getItem("front_authToken")).toBe("front-token");
    expect(JSON.parse(localStorage.getItem("front_user"))).toEqual({ id: "front-user" });
  });
});

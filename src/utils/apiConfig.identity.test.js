import {
  API_BASE_URL,
  COMPANY_CODE_STORAGE_KEY,
  SYNC_LOCAL_CONTROL_STORAGE_KEY,
  companyHeaders,
  getSavedCompanyCode,
  saveCompanyCode,
  getSyncLocalControlToken,
  saveSyncLocalControlToken,
} from "./apiConfig";
import { applyRequestIdentityHeaders, applyRequestPayloadHeaders } from "./apiBack";

describe("tenant and terminal request identity", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  test("production-style hosts use the same Nginx origin for /api routes", () => {
    expect(API_BASE_URL).toBe(window.location.origin);
  });

  test("persists only a normalized non-secret company code", () => {
    expect(saveCompanyCode(" acme-zw ")).toBe("ACME-ZW");
    expect(localStorage.getItem(COMPANY_CODE_STORAGE_KEY)).toBe("ACME-ZW");
    expect(getSavedCompanyCode()).toBe("ACME-ZW");
  });

  test("keeps the edge local-control token in local installation storage", () => {
    saveSyncLocalControlToken("local-control-token");
    expect(getSyncLocalControlToken()).toBe("local-control-token");
    expect(localStorage.getItem(SYNC_LOCAL_CONTROL_STORAGE_KEY)).toBe("local-control-token");
  });

  test("back office relies on the authenticated token and strips legacy tenant hints", () => {
    saveCompanyCode("acme");
    const config = {
      salexaWorkspace: "back-office",
      headers: { "X-Company-Domain": "legacy.example" },
    };

    applyRequestIdentityHeaders(config);

    expect(config.headers["X-Company-Code"]).toBeUndefined();
    expect(config.headers["X-Company-Domain"]).toBeUndefined();
  });

  test("selected Back Office company context never revives a stale browser code", () => {
    saveCompanyCode("previous-company");
    localStorage.setItem("back_authToken", "tenant-scoped-session");
    localStorage.setItem("back_user", JSON.stringify({
      id: "platform-owner",
      selectedCompanyId: "selected-company-id",
    }));
    const config = {
      salexaWorkspace: "back-office",
      headers: { "X-Company-Code": "PREVIOUS-COMPANY" },
    };

    applyRequestIdentityHeaders(config);

    expect(config.headers["X-Company-Code"]).toBeUndefined();
    expect(config.headers["X-Company-Domain"]).toBeUndefined();
  });

  test("front office uses the paired till even while the login modal is at the root URL", () => {
    saveCompanyCode("acme");
    localStorage.setItem("salexa_till_token", "till-secret");
    localStorage.setItem("salexa_till_id", "till-7");
    const headers = companyHeaders({ workspace: "front-office" });
    const config = {
      salexaWorkspace: "front-office",
      headers: { "X-Company-Code": "WRONG", "X-Company-Domain": "legacy.example" },
    };

    applyRequestIdentityHeaders(config);

    expect(headers["X-Till-Token"]).toBe("till-secret");
    expect(config.headers["X-Till-Token"]).toBe("till-secret");
    expect(config.headers["X-Company-Code"]).toBeUndefined();
    expect(config.headers["X-Company-Domain"]).toBeUndefined();
  });

  test("a local credential probe verifies its candidate instead of a previously stored till", () => {
    localStorage.setItem("salexa_till_token", "old-stored-token");
    localStorage.setItem("salexa_till_id", "old-till");
    const tillProbe = {
      salexaWorkspace: "front-office",
      salexaLocalCredentialProbe: true,
      headers: { "X-Till-Token": "candidate-till-token" },
    };
    const controlProbe = {
      salexaWorkspace: "front-office",
      salexaLocalCredentialProbe: true,
      headers: { "X-Sync-Local-Control": "candidate-control-token" },
    };

    applyRequestIdentityHeaders(tillProbe);
    applyRequestIdentityHeaders(controlProbe);

    expect(tillProbe.headers).toEqual({ "X-Till-Token": "candidate-till-token" });
    expect(controlProbe.headers).toEqual({
      "X-Sync-Local-Control": "candidate-control-token",
    });
  });

  test("an explicitly unscoped platform request drops every stale tenant identity", () => {
    saveCompanyCode("stale-company");
    localStorage.setItem("salexa_till_token", "stale-till-token");
    localStorage.setItem("salexa_till_id", "stale-till");
    const config = {
      salexaWorkspace: "back-office",
      salexaUnscopedPlatform: true,
      headers: {
        "X-Company-Code": "WRONG",
        "X-Company-Domain": "legacy.example",
        "X-Branch-ID": "branch-1",
        "X-Till-Token": "wrong-till-token",
        "X-Till-Id": "wrong-till",
      },
    };

    applyRequestIdentityHeaders(config);

    expect(config.headers).toEqual({});
    expect(companyHeaders({
      workspace: "back-office",
      unscopedPlatform: true,
    })).toEqual({});
  });

  test("multipart requests let the browser generate its boundary", () => {
    const form = new FormData();
    form.append("image", new File(["image"], "logo.png", { type: "image/png" }));
    const config = {
      data: form,
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
    };

    applyRequestPayloadHeaders(config);

    expect(config.headers["Content-Type"]).toBeUndefined();
    expect(config.headers.Accept).toBe("application/json");
  });
});

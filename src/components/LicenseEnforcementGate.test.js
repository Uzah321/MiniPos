import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import LicenseEnforcementGate from "./LicenseEnforcementGate";
import { getLicenseStatus } from "../services/GeneralSettingsService";
import { clearWorkspaceAuthentication } from "../utils/apiBack";
import { useAdminAuth } from "../backOffice/context/AdminAuthContext";
import { useFrontAuth } from "../context/FrontAuthContext";

const mockNavigate = jest.fn();

jest.mock("react-router-dom", () => ({
  useLocation: () => ({ pathname: "/admin" }),
  useNavigate: () => mockNavigate,
}), { virtual: true });

jest.mock("../services/GeneralSettingsService", () => ({
  getLicenseStatus: jest.fn(),
}));

jest.mock("../utils/apiBack", () => ({
  LICENSE_BLOCKED_EVENT: "salexa:license-blocked",
  clearWorkspaceAuthentication: jest.fn(),
  getLicenseFailure: jest.fn(() => null),
}));

jest.mock("../backOffice/context/AdminAuthContext", () => ({
  useAdminAuth: jest.fn(),
}));

jest.mock("../context/FrontAuthContext", () => ({
  useFrontAuth: jest.fn(),
}));

const normalUser = {
  id: "user-1",
  company_id: "company-1",
  role: { name: "manager" },
};

const renderGate = () =>
  render(
    <LicenseEnforcementGate>
      <div>Protected workspace</div>
    </LicenseEnforcementGate>
  );

describe("LicenseEnforcementGate", () => {
  beforeEach(() => {
    localStorage.clear();
    jest.clearAllMocks();
    useAdminAuth.mockReturnValue({
      user: normalUser,
      isAuthenticated: true,
      logout: jest.fn(),
    });
    useFrontAuth.mockReturnValue({
      user: null,
      isAuthenticated: false,
      logout: jest.fn(),
    });
  });

  test("shows the expiring-license reminder once for the server date", async () => {
    getLicenseStatus.mockResolvedValue({
      server_date: "2026-10-05",
      license: {
        valid: true,
        status: "active",
        code: "LICENSE_OK",
        company_id: "company-1",
        license_id: "license-1",
        expires_at: "2026-12-20T23:59:59Z",
        days_remaining: 76,
        warning: true,
        warning_days: 90,
        license_support_phone: "+263 77 123 4567",
        bypass: false,
      },
    });

    renderGate();

    expect(await screen.findByRole("dialog")).toHaveTextContent("76 days remaining");
    expect(screen.getByRole("dialog")).toHaveTextContent("+263 77 123 4567");
    expect(localStorage.getItem("salexa_license_warning:company-1:license-1")).toBe(
      "2026-10-05"
    );
  });

  test("requests forced sign-out for a blocked normal user", async () => {
    getLicenseStatus.mockResolvedValue({
      license: {
        valid: false,
        status: "expired",
        code: "LICENSE_EXPIRED",
        bypass: false,
      },
    });

    renderGate();

    await waitFor(() => {
      expect(clearWorkspaceAuthentication).toHaveBeenCalledWith({
        failure: expect.objectContaining({ code: "LICENSE_EXPIRED" }),
      });
    });
  });

  test("never ejects a recognized super administrator", async () => {
    useAdminAuth.mockReturnValue({
      user: { ...normalUser, is_super_admin: true },
      isAuthenticated: true,
      logout: jest.fn(),
    });
    getLicenseStatus.mockResolvedValue({
      license: {
        valid: false,
        status: "expired",
        code: "LICENSE_EXPIRED",
        bypass: true,
      },
    });

    renderGate();

    expect(await screen.findByText("Protected workspace")).toBeInTheDocument();
    expect(clearWorkspaceAuthentication).not.toHaveBeenCalled();
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(getLicenseStatus).toHaveBeenCalledTimes(1);
  });

  test("still reminds a bypassed super administrator when the underlying license is active", async () => {
    useAdminAuth.mockReturnValue({
      user: { ...normalUser, is_super_admin: true },
      isAuthenticated: true,
      logout: jest.fn(),
    });
    getLicenseStatus.mockResolvedValue({
      server_date: "2026-10-05",
      license: {
        valid: true,
        status: "active",
        code: "LICENSE_OK",
        company_id: "company-1",
        license_id: "license-2",
        expires_at: "2026-11-01T23:59:59Z",
        days_remaining: 27,
        warning: true,
        warning_days: 90,
        bypass: true,
      },
    });

    renderGate();

    expect(await screen.findByRole("dialog")).toHaveTextContent("27 days remaining");
    expect(clearWorkspaceAuthentication).not.toHaveBeenCalled();
  });

  test("does not request a tenant license for an unscoped platform-owner session", async () => {
    useAdminAuth.mockReturnValue({
      user: {
        id: "platform-owner",
        is_platform_admin: true,
        platform_context_required: true,
        company_id: null,
      },
      isAuthenticated: true,
      logout: jest.fn(),
    });

    renderGate();

    expect(await screen.findByText("Protected workspace")).toBeInTheDocument();
    expect(getLicenseStatus).not.toHaveBeenCalled();
    expect(clearWorkspaceAuthentication).not.toHaveBeenCalled();
  });
});

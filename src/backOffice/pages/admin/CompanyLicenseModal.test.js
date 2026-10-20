import React from "react";
import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import CompanyLicenseModal from "./CompanyLicenseModal";
import {
  getCompanyLicenses,
  renewLicenseOffline,
} from "../../../services/GeneralSettingsService";

jest.mock("../../../services/GeneralSettingsService", () => ({
  getCompanyLicenses: jest.fn(),
  renewLicenseOffline: jest.fn(),
}));

const activeLicense = {
  id: "internal-active-license-id",
  license_key: "ABCD-EFGH-JKMN-PQRS",
  license_type: "enterprise",
  max_branches: 7,
  max_users: 20,
  max_devices: 10,
  starts_at: "2026-09-25",
  expires_at: "2027-05-01",
  status: "active",
  created_at: "2026-09-25T08:00:00Z",
};

const expiredLicense = {
  id: "internal-expired-license-id",
  license_key: "WXYZ-2345-6789-ABCD",
  license_type: "business",
  max_branches: 2,
  max_users: 6,
  max_devices: 3,
  starts_at: "2025-01-01",
  expires_at: "2025-12-31",
  status: "expired",
  created_at: "2025-01-01T08:00:00Z",
};

const response = {
  company: {
    id: "company-1",
    company_name: "ServeNest Holdings",
    domain: "servenest.co.zw",
    licenses: [expiredLicense, activeLicense],
  },
};

const renderModal = () =>
  render(
    <CompanyLicenseModal
      open
      companyId="company-1"
      onClose={jest.fn()}
    />
  );

describe("CompanyLicenseModal", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getCompanyLicenses.mockResolvedValue(response);
  });

  test("shows one compact active-license summary and a centered activation workflow", async () => {
    renderModal();

    expect(await screen.findByText("ServeNest Holdings")).toBeInTheDocument();
    expect(screen.getByText("Active license")).toBeInTheDocument();
    expect(screen.getByText("ABCD-••••-••••-PQRS")).toBeInTheDocument();
    expect(screen.getByText("01 May 2027")).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Activate" })).toHaveAttribute(
      "aria-selected",
      "true"
    );
    expect(screen.getByRole("tabpanel")).toHaveAttribute(
      "aria-labelledby",
      "license-activate-tab"
    );
    expect(screen.getByLabelText("License key")).toBeInTheDocument();
    expect(screen.queryByText("License Stats")).not.toBeInTheDocument();
    expect(screen.queryByText("Renewal Result")).not.toBeInTheDocument();
    expect(screen.queryByText("Clear")).not.toBeInTheDocument();
    expect(screen.queryByText(activeLicense.license_key)).not.toBeInTheDocument();
    expect(screen.queryByText(activeLicense.id)).not.toBeInTheDocument();
  });

  test("activates a formatted compact key and reports success", async () => {
    renewLicenseOffline.mockResolvedValue({
      message: "License activated successfully.",
      license: activeLicense,
    });
    renderModal();

    await screen.findByText("ServeNest Holdings");
    const keyInput = screen.getByLabelText("License key");

    fireEvent.change(keyInput, { target: { value: "abcd efgh jkmn pqrs" } });

    expect(keyInput).toHaveValue("ABCD-EFGH-JKMN-PQRS");
    expect(screen.getByText("Clear")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Activate License" }));

    await waitFor(() => {
      expect(renewLicenseOffline).toHaveBeenCalledWith({
        license_key: "ABCDEFGHJKMNPQRS",
      });
    });
    expect(await screen.findByRole("dialog", { name: "License Activated" })).toHaveTextContent(
      "License activated successfully."
    );
    expect(keyInput).toHaveValue("");
    expect(getCompanyLicenses).toHaveBeenCalledTimes(2);
  });

  test("keeps history behind its tab and masks keys and internal IDs", async () => {
    renderModal();

    await screen.findByText("ServeNest Holdings");
    expect(screen.queryByRole("columnheader", { name: "Period" })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: "History (2)" }));

    expect(screen.getByRole("tab", { name: "History (2)" })).toHaveAttribute(
      "aria-selected",
      "true"
    );
    expect(screen.getByRole("columnheader", { name: "Period" })).toBeInTheDocument();
    const historyTable = within(screen.getByRole("table"));
    expect(historyTable.getByText("ABCD-••••-••••-PQRS")).toBeInTheDocument();
    expect(historyTable.getByText("WXYZ-••••-••••-ABCD")).toBeInTheDocument();
    expect(screen.queryByText(activeLicense.license_key)).not.toBeInTheDocument();
    expect(screen.queryByText(expiredLicense.license_key)).not.toBeInTheDocument();
    expect(screen.queryByText(activeLicense.id)).not.toBeInTheDocument();
    expect(screen.queryByText(expiredLicense.id)).not.toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "Refresh" })).toHaveLength(1);
  });
});

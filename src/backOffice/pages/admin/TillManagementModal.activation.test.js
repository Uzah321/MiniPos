import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import TillManagementModal from "./TillManagementModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import {
  createStandaloneTillActivationCode,
  getTill,
  getTills,
} from "../../../services/TillService";

let mockBackendMode = "standalone";

jest.mock("../../../config/DeploymentConfig", () => ({
  BACKEND_MODES: {
    EDGE: "edge",
    STANDALONE: "standalone",
    CLOUD: "cloud",
  },
  getBackendMode: () => mockBackendMode,
}));

jest.mock("../../../services/AuthenticationService", () => ({
  getAuthorisedCompany: jest.fn(),
}));

jest.mock("../../../services/TillService", () => ({
  getTills: jest.fn(),
  getTill: jest.fn(),
  createTill: jest.fn(),
  updateTill: jest.fn(),
  deleteTill: jest.fn(),
  createStandaloneTillActivationCode: jest.fn(),
  unregisterTill: jest.fn(),
  resolveCurrentTill: jest.fn(),
  generateTillCode: jest.fn(),
}));

const till = {
  id: "till-1",
  branch_id: "branch-1",
  till_code: "TILL-001",
  till_name: "Reception",
  device_label: "Reception browser",
  is_active: true,
  is_registered: false,
};

const renderModal = () => render(
  <TillManagementModal open onClose={jest.fn()} currentUser={{ branch_id: "branch-1" }} />,
);

describe("separated browser activation in Till Management", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    mockBackendMode = "standalone";
    getAuthorisedCompany.mockResolvedValue({
      company: { branches: [{ id: "branch-1", branch_name: "Main Branch" }] },
    });
    getTills.mockResolvedValue({ data: [till] });
    getTill.mockResolvedValue({ data: till });
    createStandaloneTillActivationCode.mockResolvedValue({
      data: {
        terminal_activation_code: "TERM-01234567-89ABCDEF-01234567-89ABCDEF",
        expires_at: "2026-10-08T12:10:00Z",
      },
    });
    Object.defineProperty(window.navigator, "clipboard", {
      configurable: true,
      value: { writeText: jest.fn().mockResolvedValue(undefined) },
    });
  });

  test("standalone Back Office displays and copies a one-time code without storing a till token", async () => {
    renderModal();

    fireEvent.click(await screen.findByRole("button", { name: "More actions for Reception" }));
    fireEvent.click(await screen.findByRole("menuitem", { name: "Create activation code" }));
    fireEvent.click(await screen.findByRole("button", { name: "Generate Code" }));

    await waitFor(() => expect(createStandaloneTillActivationCode).toHaveBeenCalledWith(
      "till-1",
      { device_label: "Reception browser" },
    ));
    const code = await screen.findByRole("textbox", { name: "One-time terminal activation code" });
    expect(code).toHaveValue("TERM-01234567-89ABCDEF-01234567-89ABCDEF");
    expect(screen.getByText(/Expires:/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Copy" }));
    await waitFor(() => expect(window.navigator.clipboard.writeText).toHaveBeenCalledWith(
      "TERM-01234567-89ABCDEF-01234567-89ABCDEF",
    ));
    expect(await screen.findByRole("button", { name: "Copied" })).toBeInTheDocument();
    expect(localStorage.getItem("salexa_till_token")).toBeNull();
  });

  test("edge Back Office keeps activation in Deployment & Sync", async () => {
    mockBackendMode = "edge";
    renderModal();

    await screen.findByText("Reception");
    fireEvent.click(screen.getByRole("button", { name: "More actions for Reception" }));
    expect(screen.queryByRole("menuitem", { name: /activation/i })).not.toBeInTheDocument();
  });
});

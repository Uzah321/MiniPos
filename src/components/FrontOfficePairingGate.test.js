import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import {
  FRONT_OFFICE_GATE_STATES,
  PairingCard,
  TerminalActivationCard,
  resolveFrontOfficeGateState,
} from "./FrontOfficePairingGate";
import { useSyncStatus } from "../context/SyncStatusContext";

jest.mock("../context/SyncStatusContext", () => ({
  useSyncStatus: jest.fn(),
}));

describe("strict Front Office pairing gate", () => {
  const activateTerminal = jest.fn();
  const pairNode = jest.fn();
  const edgeReady = {
    deploymentMode: "edge",
    paired: true,
    pairingRequired: false,
    tillSetupRequired: false,
    configurationPending: false,
    sellReady: true,
    localBackendReachable: true,
  };

  const resolve = (overrides = {}) => resolveFrontOfficeGateState({
    dedicatedFrontOffice: true,
    contractAvailable: true,
    status: edgeReady,
    localTillTokenPresent: true,
    ...overrides,
  });

  beforeEach(() => {
    useSyncStatus.mockReturnValue({
      pairNode,
      pairing: false,
      error: "",
      importLocalCredentials: jest.fn(),
      importingLocalCredentials: false,
      credentialImportError: "",
      activateTerminal,
      terminalActivating: false,
      terminalActivationError: "",
    });
  });

  test("does not bypass a missing contract unless compatibility is explicit", () => {
    expect(resolve({ contractAvailable: false })).toBe(FRONT_OFFICE_GATE_STATES.CONTRACT_REQUIRED);
    expect(resolve({ contractAvailable: false, legacyAllowed: true })).toBe(FRONT_OFFICE_GATE_STATES.READY);
  });

  test("blocks a malformed or non-edge deployment response", () => {
    expect(resolve({ status: { paired: true, sellReady: true } })).toBe(FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH);
    expect(resolve({ status: { ...edgeReady, deploymentMode: "standalone" } })).toBe(FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH);
    expect(resolve({
      legacyAllowed: true,
      status: { ...edgeReady, deploymentMode: "cloud" },
    })).toBe(FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH);
  });

  test("accepts standalone only when both runtime and local status are explicit", () => {
    const standaloneReady = {
      ...edgeReady,
      deploymentMode: "standalone",
      localOnly: true,
    };

    expect(resolve({
      backendMode: "standalone",
      status: standaloneReady,
    })).toBe(FRONT_OFFICE_GATE_STATES.READY);
    expect(resolve({
      backendMode: "standalone",
      status: { ...standaloneReady, localOnly: false },
    })).toBe(FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH);
    expect(resolve({
      backendMode: "standalone",
      status: { ...standaloneReady, deploymentMode: "edge" },
    })).toBe(FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH);
    expect(resolve({ status: standaloneReady })).toBe(FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH);
  });

  test("standalone requires local setup and a browser till token without entering pairing", () => {
    const standaloneReady = {
      ...edgeReady,
      deploymentMode: "standalone",
      localOnly: true,
    };

    expect(resolve({
      backendMode: "standalone",
      status: { ...standaloneReady, configurationPending: true, sellReady: false },
    })).toBe(FRONT_OFFICE_GATE_STATES.CONFIGURATION_PENDING);
    expect(resolve({
      backendMode: "standalone",
      status: { ...standaloneReady, tillSetupRequired: true, sellReady: false },
      localTillTokenPresent: false,
    })).toBe(FRONT_OFFICE_GATE_STATES.TILL_RECOVERY_REQUIRED);
  });

  test("requires explicit pairing, a browser terminal activation and sell readiness", () => {
    expect(resolve({ status: { ...edgeReady, paired: false, pairingRequired: true } })).toBe(FRONT_OFFICE_GATE_STATES.PAIRING_REQUIRED);
    expect(resolve({ localTillTokenPresent: false })).toBe(FRONT_OFFICE_GATE_STATES.TILL_RECOVERY_REQUIRED);
    expect(resolve({ status: { ...edgeReady, configurationPending: true, sellReady: false } })).toBe(FRONT_OFFICE_GATE_STATES.CONFIGURATION_PENDING);
    expect(resolve()).toBe(FRONT_OFFICE_GATE_STATES.READY);
  });

  test("keeps first-time setup on the one-time pairing-code flow", () => {
    render(<PairingCard status={{ node: { cloudUrlMasked: "https://cloud.example.test" } }} />);

    expect(screen.getByRole("button", { name: "Pair POS Server" })).toBeInTheDocument();
    expect(screen.queryByLabelText("Server recovery code")).not.toBeInTheDocument();
    expect(screen.queryByText(/advanced recovery/i)).not.toBeInTheDocument();
  });

  test("shows the explicit server-replacement flow when the cloud revoked this node", () => {
    render(<PairingCard replacement status={{ node: { cloudUrlMasked: "https://cloud.example.test" } }} />);

    expect(screen.getByRole("heading", { name: "Replace this revoked POS server" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Replace Revoked POS Server" })).toBeInTheDocument();
    expect(screen.getByText(/existing sales remain in the local database/i)).toBeInTheDocument();
    expect(screen.queryByText(/advanced recovery/i)).not.toBeInTheDocument();
  });

  test("uses one-time terminal activation for another browser on a paired server", () => {
    render(<TerminalActivationCard status={{ node: { name: "Branch Server" } }} />);

    expect(screen.getByRole("button", { name: "Activate This Browser" })).toBeInTheDocument();
    expect(screen.getByPlaceholderText("TERM-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX")).toBeInTheDocument();
    expect(screen.queryByLabelText("Server recovery code")).not.toBeInTheDocument();
  });

  test("standalone activation points to Till Management and hides edge recovery", () => {
    render(<TerminalActivationCard standalone status={{ branch: { name: "Main Branch" } }} />);

    expect(screen.getByText("Till Management")).toBeInTheDocument();
    expect(screen.queryByText("Deployment & Sync")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /repair or replace the POS server/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /restore browser recovery tokens/i })).not.toBeInTheDocument();
  });

  test("explains a pasted server pairing code without calling browser activation", () => {
    render(<TerminalActivationCard status={{ node: { name: "Branch Server" } }} />);

    fireEvent.change(screen.getByRole("textbox", { name: /one-time browser activation code/i }), {
      target: { value: "CMP019F93B4B704-RM-M1ARIAEO.APMB1UV6ESC7-DPCCQLHP9RC7" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Activate This Browser" }));

    expect(screen.getByRole("alert")).toHaveTextContent(/POS server pairing\/re-pair code/i);
    expect(screen.getByRole("alert")).toHaveTextContent(/Activate Browser/i);
    expect(activateTerminal).not.toHaveBeenCalled();
  });

  test("submits a correctly shaped TERM browser activation code", async () => {
    activateTerminal.mockResolvedValue({ activated: true });
    render(<TerminalActivationCard status={{ node: { name: "Branch Server" } }} />);

    fireEvent.change(screen.getByRole("textbox", { name: /one-time browser activation code/i }), {
      target: { value: "term-0123abcd-4567ef89-a1b2c3d4-e5f60718" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Activate This Browser" }));

    await waitFor(() => expect(activateTerminal).toHaveBeenCalledWith(
      "TERM-0123ABCD-4567EF89-A1B2C3D4-E5F60718",
    ));
  });

  test("keeps POS server re-pair behind an explicit separate disclosure", () => {
    render(<TerminalActivationCard status={{ node: { name: "Branch Server", cloudUrlMasked: "https://cloud.example.test" } }} />);

    const disclosure = screen.getByRole("button", { name: /repair or replace the POS server instead/i });
    expect(disclosure).toHaveAttribute("aria-expanded", "false");

    fireEvent.click(disclosure);

    expect(disclosure).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(screen.getByRole("button", { name: /re-pair active \/ suspended server/i }));
    expect(screen.getByRole("button", { name: "Re-pair POS Server" })).toBeInTheDocument();
    expect(screen.getByText(/revoked server must use the separate replacement workflow/i)).toBeInTheDocument();
  });

  test("keeps revoked-server replacement distinct from ordinary re-pair", () => {
    render(<TerminalActivationCard status={{ node: { name: "Branch Server", cloudUrlMasked: "https://cloud.example.test" } }} />);

    fireEvent.click(screen.getByRole("button", { name: /repair or replace the POS server instead/i }));
    fireEvent.click(screen.getByRole("button", { name: /replace revoked server/i }));

    expect(screen.getByRole("button", { name: "Replace Revoked POS Server" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Re-pair POS Server" })).not.toBeInTheDocument();
    expect(screen.getByText(/zero unsent or failed sync events/i)).toBeInTheDocument();
  });

  test("reveals installer recovery values only from the advanced disclosure", () => {
    render(<TerminalActivationCard status={{ node: { name: "Branch Server" } }} />);

    const disclosure = screen.getByRole("button", { name: /restore browser recovery tokens/i });
    expect(disclosure).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByLabelText("Server recovery code")).not.toBeInTheDocument();

    fireEvent.click(disclosure);

    expect(disclosure).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByLabelText("Server recovery code")).toBeInTheDocument();
    expect(screen.getByLabelText("Terminal recovery code")).toBeInTheDocument();
  });
});

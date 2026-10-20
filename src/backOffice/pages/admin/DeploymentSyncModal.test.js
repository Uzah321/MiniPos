import React from "react";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import DeploymentSyncModal, {
  extractPairingTills,
  findExplicitTerminal,
  posSetupDetails,
  syncNodeActionState,
  terminalActivationConfirmation,
  terminalSetupDetails,
} from "./DeploymentSyncModal";
import { useSyncStatus } from "../../../context/SyncStatusContext";
import {
  createSyncPairingCode,
  createTerminalActivationCode,
  listSyncNodes,
} from "../../../services/SyncService";
import { getTills } from "../../../services/TillService";
import EnterpriseConfirmHost from "../../../components/EnterpriseConfirmHost";

jest.mock("../../../context/SyncStatusContext", () => ({
  useSyncStatus: jest.fn(),
  useOptionalSyncStatus: jest.fn(() => null),
}));

jest.mock("../../../config/DeploymentConfig", () => ({
  getDeploymentMode: () => "back-office",
  isDedicatedBackOffice: () => true,
  isDedicatedFrontOffice: () => false,
}));

jest.mock("../../../services/SyncService", () => ({
  createSyncPairingCode: jest.fn(),
  createTerminalActivationCode: jest.fn(),
  isSyncContractUnavailable: jest.fn(() => false),
  listSyncNodes: jest.fn(),
  revokeSyncNode: jest.fn(),
}));

jest.mock("../../../services/TillService", () => ({
  getTills: jest.fn(),
}));

describe("deployment sync pairing", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    localStorage.setItem("back_user", JSON.stringify({
      selectedBranchId: "branch-1",
      selectedBranch: { id: "branch-1", branch_name: "Harare" },
    }));
    useSyncStatus.mockReturnValue({
      status: { company: { code: "ACME" } },
      contractAvailable: true,
      error: "",
      refreshing: false,
      running: false,
      refresh: jest.fn().mockResolvedValue(null),
      runNow: jest.fn().mockResolvedValue(null),
    });
    listSyncNodes.mockResolvedValue([{
      id: "node-1",
      node_name: "Branch Server",
      branch_id: "branch-1",
      till_id: "till-1",
      status: "active",
    }]);
    getTills.mockResolvedValue({
      data: [{
        id: "till-1",
        branch_id: "branch-1",
        till_name: "Main Till",
        is_active: true,
      }],
    });
    createSyncPairingCode.mockResolvedValue({});
  });

  test("extracts active tills from the paginated till service response", () => {
    const tills = [{ id: "till-1", till_name: "Main Counter" }];
    expect(extractPairingTills({ data: { data: tills, current_page: 1 } })).toEqual(tills);
    expect(extractPairingTills({ data: tills })).toEqual(tills);
  });

  test("requires an exact browser till instead of inheriting the POS server till", () => {
    const tills = [
      { id: "till-1", till_code: "TILL-001", till_name: "Old Counter" },
      { id: "till-2", till_code: "TILL-002", till_name: "New Counter" },
    ];

    expect(findExplicitTerminal(tills, "")).toBeNull();
    expect(findExplicitTerminal(tills, "till-2")).toEqual(tills[1]);
    expect(terminalActivationConfirmation({
      node: { node_name: "Harare Branch Server" },
      till: tills[1],
    })).toContain("TILL-002 · New Counter");
  });

  test("builds a complete non-secret handoff for the physical POS", () => {
    const details = posSetupDetails({
      companyCode: "CMP-ACME",
      pairing: {
        pairing_code: "NODE-1.ONE-TIME",
        expires_at: "2026-10-06T12:00:00Z",
        node: {
          node_name: "Main Counter",
          node_code: "NODE-1",
          branch: { branch_name: "Harare", branch_code: "HRE" },
          till: { till_name: "Till One", till_code: "TILL-001" },
        },
      },
    });

    expect(details).toContain("Company code: CMP-ACME");
    expect(details).toContain("Branch: Harare (HRE)");
    expect(details).toContain("Till: Till One (TILL-001)");
    expect(details).toContain("POS server: Main Counter (NODE-1)");
    expect(details).toContain("Pairing code: NODE-1.ONE-TIME");
    expect(details).toContain("Cloud server: configured automatically");
    expect(details).toContain("DO NOT enter this on Browser Terminal Activation");
    expect(details).not.toMatch(/credential|local control|till token|private key/i);
  });

  test("builds an Android direct-cloud handoff without exposing credentials", () => {
    const details = posSetupDetails({
      companyCode: "CMP-ACME",
      pairing: {
        pairing_code: "ANDROID-1.ONE-TIME",
        expires_at: "2026-10-07T15:00:00Z",
        node: {
          node_name: "Harare Tablet 01",
          node_code: "ANDROID-1",
          target_platform: "android",
          branch: { branch_name: "Harare", branch_code: "HRE" },
          till: { till_name: "Tablet Till", till_code: "TAB-001" },
        },
      },
    });

    expect(details).toContain("Android direct-cloud terminal");
    expect(details).toContain("Android terminal: Harare Tablet 01 (ANDROID-1)");
    expect(details).toContain("Android Initial Setup");
    expect(details).not.toMatch(/credential|till token|private key/i);
  });

  test("builds an explicitly browser-only terminal activation handoff", () => {
    const details = terminalSetupDetails({
      activation: {
        terminal_activation_code: "TERM-0123ABCD-4567EF89-A1B2C3D4-E5F60718",
        expires_at: "2026-10-06T12:00:00Z",
        node: { node_name: "Branch Server", node_code: "NODE-1" },
        terminal: { till_name: "Till One", till_code: "TILL-001" },
      },
    });

    expect(details).toContain("Code type: browser terminal activation (starts with TERM-)");
    expect(details).toContain("Activation code: TERM-0123ABCD-4567EF89-A1B2C3D4-E5F60718");
    expect(details).toContain("DO NOT use this to pair or re-pair the POS server");
  });

  test("treats revoked servers as permanently non-actionable", () => {
    expect(syncNodeActionState({ status: "active" })).toMatchObject({
      canActivateBrowser: true,
      canRepairServer: true,
      canRevoke: true,
    });
    expect(syncNodeActionState({ status: "active", deployment_mode: "android" })).toMatchObject({
      platform: "android",
      canActivateBrowser: false,
      canRepairServer: true,
    });
    expect(syncNodeActionState({
      id: "node-1",
      branch_id: "branch-1",
      till_id: "till-1",
      status: "revoked",
      revoked_at: "2026-10-06T10:00:00Z",
    })).toMatchObject({
      canActivateBrowser: false,
      canRepairServer: false,
      canReplaceServer: true,
      canRevoke: false,
    });
  });

  test("separates new-server pairing from browser activation controls", async () => {
    render(<DeploymentSyncModal open onClose={jest.fn()} />);

    expect(await screen.findByText("1. Pair a new POS device")).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /assigned till/i })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /browser activation till/i })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /browser activation till/i })).toHaveValue("");
    expect(screen.getByRole("button", { name: /refresh tills/i })).toBeInTheDocument();
    expect(await screen.findByRole("button", { name: "Activate Browser" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Re-pair Server" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Generate New Server Code" })).toBeInTheDocument();
  });

  test("issues a direct-cloud Android pairing code for the selected branch and till", async () => {
    createSyncPairingCode.mockResolvedValue({
      pairing_code: "ANDROID-1.ONE-TIME",
      node: { target_platform: "android", node_name: "Harare Tablet 01" },
    });
    render(<DeploymentSyncModal open onClose={jest.fn()} />);

    await waitFor(() => expect(getTills).toHaveBeenCalled());
    fireEvent.change(screen.getByRole("combobox", { name: /device type/i }), { target: { value: "android" } });
    fireEvent.change(screen.getByPlaceholderText("e.g. Harare Tablet 01"), { target: { value: "Harare Tablet 01" } });
    fireEvent.change(screen.getByRole("combobox", { name: /assigned till/i }), { target: { value: "till-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Generate Android Code" }));

    await waitFor(() => expect(createSyncPairingCode).toHaveBeenCalledWith({
      branch_id: "branch-1",
      till_id: "till-1",
      node_name: "Harare Tablet 01",
      target_platform: "android",
    }));
    expect(await screen.findByText("ANDROID-1.ONE-TIME")).toBeInTheDocument();
    expect(screen.getByText(/syncs directly with the cloud/i)).toBeInTheDocument();
  });

  test("does not issue a browser activation until a till is selected explicitly", async () => {
    render(<DeploymentSyncModal open onClose={jest.fn()} />);

    const activate = await screen.findByRole("button", { name: "Activate Browser" });
    await waitFor(() => expect(activate).toBeEnabled());
    expect(screen.getByRole("combobox", { name: /browser activation till/i })).toHaveValue("");
    fireEvent.click(activate);

    expect(await screen.findByText(/select the browser activation till explicitly/i)).toBeInTheDocument();
    expect(createTerminalActivationCode).not.toHaveBeenCalled();
  });

  test("refreshes tills and issues the code only for the confirmed new terminal", async () => {
    const oldTill = { id: "till-1", till_code: "TILL-001", till_name: "Old Counter", is_active: true };
    const newTill = { id: "till-2", till_code: "TILL-002", till_name: "New Counter", is_active: true };
    getTills
      .mockResolvedValueOnce({ data: { data: [oldTill] } })
      .mockResolvedValue({ data: { data: [oldTill, newTill] } });
    createTerminalActivationCode.mockResolvedValue({
      terminal_activation_code: "TERM-01234567-89ABCDEF-01234567-89ABCDEF",
      expires_at: "2026-10-07T10:00:00Z",
      node: { id: "node-1", node_name: "Branch Server" },
      terminal: newTill,
    });
    render(<><EnterpriseConfirmHost /><DeploymentSyncModal open onClose={jest.fn()} /></>);

    await waitFor(() => expect(getTills).toHaveBeenCalledTimes(1));
    const refreshTills = screen.getByRole("button", { name: /refresh tills/i });
    await waitFor(() => expect(refreshTills).toBeEnabled());
    fireEvent.click(refreshTills);
    await waitFor(() => expect(getTills).toHaveBeenCalledTimes(2));
    const tillSelect = screen.getByRole("combobox", { name: /browser activation till/i });
    await waitFor(() => expect(Array.from(tillSelect.options).map((option) => option.value)).toContain("till-2"));
    fireEvent.change(tillSelect, { target: { value: "till-2" } });
    fireEvent.click(await screen.findByRole("button", { name: "Activate Browser" }));

    expect(await screen.findByRole("dialog", { name: "Activate Terminal" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Activate terminal" }));
    await waitFor(() => expect(createTerminalActivationCode).toHaveBeenCalledWith("node-1", "till-2"));
    expect(createTerminalActivationCode).not.toHaveBeenCalledWith("node-1", "till-1");
    expect(await screen.findByText("TERM-01234567-89ABCDEF-01234567-89ABCDEF")).toBeInTheDocument();
  });

  test("offers only the explicit replacement action for a scoped revoked server", async () => {
    listSyncNodes.mockResolvedValue([{
      id: "node-1",
      node_name: "Retired Server",
      branch_id: "branch-1",
      till_id: "till-1",
      status: "revoked",
      revoked_at: "2026-10-06T10:00:00Z",
    }]);
    createSyncPairingCode.mockResolvedValue({
      pairing_code: "NODE-2.ABCDEFGHIJKL-MNOPQRSTUVWX",
      replacement_node_id: "node-1",
      node: { id: "node-2", node_name: "Retired Server" },
    });
    render(<><EnterpriseConfirmHost /><DeploymentSyncModal open onClose={jest.fn()} /></>);

    const replace = await screen.findByRole("button", { name: "Replace Revoked Server" });
    expect(screen.queryByRole("button", { name: "Activate Browser" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Re-pair Server" })).not.toBeInTheDocument();

    fireEvent.click(replace);

    expect(await screen.findByText(/creates a new same-scope server identity/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Replace server" }));

    await waitFor(() => expect(createSyncPairingCode).toHaveBeenCalledWith({
      branch_id: "branch-1",
      till_id: "till-1",
      node_name: "Retired Server",
      target_platform: "edge",
      replacement_node_id: "node-1",
      replacement_authorized: true,
    }));
    expect(await screen.findByText("Revoked POS server replacement code — not a browser code")).toBeInTheDocument();
    expect(screen.getByText(/old node remains revoked/i)).toBeInTheDocument();
  });

  test("does not repopulate a terminal activation secret when its request resolves after close", async () => {
    let resolveActivation;
    createTerminalActivationCode.mockReturnValue(new Promise((resolve) => {
      resolveActivation = resolve;
    }));
    const onClose = jest.fn();
    const { rerender } = render(<><EnterpriseConfirmHost /><DeploymentSyncModal open onClose={onClose} /></>);

    const activate = await screen.findByRole("button", { name: "Activate Browser" });
    await waitFor(() => expect(activate).toBeEnabled());
    fireEvent.change(screen.getByRole("combobox", { name: /browser activation till/i }), { target: { value: "till-1" } });
    fireEvent.click(activate);

    fireEvent.click(await screen.findByRole("button", { name: "Activate terminal" }));

    await waitFor(() => expect(createTerminalActivationCode).toHaveBeenCalledWith("node-1", "till-1"));
    expect(screen.getByRole("button", { name: "Close form" })).toBeDisabled();

    rerender(<><EnterpriseConfirmHost /><DeploymentSyncModal open={false} onClose={onClose} /></>);
    await act(async () => {
      resolveActivation({
        terminal_activation_code: "LATE-SECRET",
        expires_at: "2026-10-06T12:00:00Z",
        node: { id: "node-1", node_name: "Branch Server" },
        terminal: { id: "till-1", till_name: "Main Till" },
      });
      await Promise.resolve();
    });

    rerender(<><EnterpriseConfirmHost /><DeploymentSyncModal open onClose={onClose} /></>);
    await waitFor(() => expect(screen.queryByText("Loading nodes…")).not.toBeInTheDocument());
    await waitFor(() => expect(screen.getByRole("combobox", { name: /browser activation till/i })).toBeEnabled());
    expect(screen.queryByText("LATE-SECRET")).not.toBeInTheDocument();
  });
});

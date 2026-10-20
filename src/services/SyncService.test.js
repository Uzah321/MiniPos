import api from "../utils/apiBack";
import {
  createSyncPairingCode,
  createTerminalActivationCode,
  isSyncContractUnavailable,
  normalizeSyncStatus,
  pairSyncNode,
  redeemTerminalActivationCode,
  runSyncNow,
  validateLocalSyncCredentials,
} from "./SyncService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
    delete: jest.fn(),
  },
}));

describe("POS synchronization service", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    window.__SERVENEST_RUNTIME_CONFIG__ = { deployment_mode: "front-office" };
  });

  test("normalizes the local backend status contract", () => {
    const status = normalizeSyncStatus({ data: {
      paired: true,
      deployment_mode: "edge",
      node_id: "node-1",
      node_code: "POS-01",
      company_id: "company-1",
      company_code: "ACME",
      branch_id: "branch-1",
      branch_name: "Harare",
      pending_events: 4,
      cloud_url: "https://cloud.example.test",
      last_sync_at: "2026-10-05T10:00:00Z",
      configuration_version: 12,
      till_setup_required: false,
      configuration_pending: false,
      sell_ready: true,
      dead_letter_events: 2,
    } });

    expect(status).toEqual(expect.objectContaining({
      paired: true,
      deploymentMode: "edge",
      pendingUploads: 4,
      pendingTotal: 4,
      cloudConfigured: true,
      lastSuccessAt: "2026-10-05T10:00:00Z",
      configVersion: 12,
      sellReady: true,
      deadLetterEvents: 2,
    }));
    expect(status.company.code).toBe("ACME");
    expect(status.branch.name).toBe("Harare");
    expect(status.node.name).toBe("POS-01");
  });

  test("preserves an explicit non-edge pairing_required false value", () => {
    const status = normalizeSyncStatus({ data: {
      paired: false,
      pairing_required: false,
      deployment_mode: "cloud",
      cloud_url_masked: "https://cloud.example.test/…",
    } });

    expect(status.paired).toBe(false);
    expect(status.pairingRequired).toBe(false);
    expect(status.cloudConfigured).toBe(true);
    expect(status.deploymentMode).toBe("cloud");
  });

  test("normalizes both acknowledged directions and fails closed when proof is missing", () => {
    const status = normalizeSyncStatus({ data: {
      paired: true,
      deployment_mode: "edge",
      directions: {
        pos_to_cloud: {
          state: "synced",
          in_sync: true,
          pending: 0,
          last_acknowledged_at: "2026-10-06T10:00:00Z",
        },
        cloud_to_pos: {
          state: "pending",
          in_sync: false,
          pending: 2,
          local_cursor: 8,
          cloud_cursor: 10,
        },
      },
    } });

    expect(status.directions.posToCloud).toEqual(expect.objectContaining({
      state: "synced",
      inSync: true,
      pending: 0,
    }));
    expect(status.directions.cloudToPos).toEqual(expect.objectContaining({
      state: "pending",
      inSync: false,
      pending: 2,
      localCursor: 8,
      cloudCursor: 10,
    }));

    const unverified = normalizeSyncStatus({ data: { paired: true, deployment_mode: "edge" } });
    expect(unverified.directions.posToCloud.inSync).toBe(false);
    expect(unverified.directions.cloudToPos.inSync).toBe(false);

    const proofStatus = normalizeSyncStatus({ data: {
      paired: true,
      deployment_mode: "edge",
      pos_to_cloud: { equal: true, pending_events: 0, last_checked_at: "now" },
      cloud_to_pos: { equal: true, pending_changes: 0, local_cursor: 10, remote_head_cursor: 10 },
    } });
    expect(proofStatus.directions.posToCloud.inSync).toBe(true);
    expect(proofStatus.directions.cloudToPos.inSync).toBe(true);
    expect(proofStatus.directions.cloudToPos.cloudCursor).toBe(10);

    const inconsistentProof = normalizeSyncStatus({ data: {
      paired: true,
      deployment_mode: "edge",
      pos_to_cloud: { state: "synced", equal: true, comparison_known: "false", fresh: true },
      cloud_to_pos: { state: "synced", equal: true, comparison_known: true, fresh: "false" },
    } });
    expect(inconsistentProof.directions.posToCloud.inSync).toBe(false);
    expect(inconsistentProof.directions.cloudToPos.inSync).toBe(false);
    expect(inconsistentProof.directions.posToCloud.state).not.toBe("synced");
    expect(inconsistentProof.directions.cloudToPos.state).not.toBe("synced");
  });

  test("preserves the safe pinned origin for an unpaired edge", () => {
    const status = normalizeSyncStatus({ data: {
      paired: false,
      pairing_required: true,
      deployment_mode: "edge",
      cloud_configured: true,
      cloud_url_masked: "http://127.0.0.1:8082",
      cloud_url_locked: true,
    } });

    expect(status.cloudConfigured).toBe(true);
    expect(status.node.cloudUrlMasked).toBe("http://127.0.0.1:8082");
    expect(status.node.cloudUrlLocked).toBe(true);
  });

  test("normalizes an authoritative revoked node as requiring replacement", () => {
    const status = normalizeSyncStatus({ data: {
      paired: false,
      pairing_required: true,
      replacement_required: true,
      status: "revoked",
      deployment_mode: "edge",
    } });

    expect(status.replacementRequired).toBe(true);
    expect(status.state).toBe("revoked");
  });

  test("fails closed when a status response omits explicit pairing identity", () => {
    const status = normalizeSyncStatus({ data: {} });

    expect(status.paired).toBe(false);
    expect(status.pairingRequired).toBe(true);
    expect(status.deploymentMode).toBe("");
  });

  test("sends pairing secrets only to the local API request", async () => {
    api.post.mockResolvedValue({ data: { data: { paired: true, company_code: "ACME" } } });

    await pairSyncNode({
      company_code: "acme",
      pairing_code: "one-time",
      cloud_url: "https://cloud.example.test",
      node_name: "Main Counter POS",
    });

    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/sync/pair",
      {
        company_code: "ACME",
        pairing_code: "one-time",
        cloud_url: "https://cloud.example.test",
        node_name: "Main Counter POS",
      },
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
    expect(localStorage.getItem("pairing_code")).toBeNull();
  });

  test("uses mode-aware run and pairing-code contracts", async () => {
    localStorage.setItem("salexa_sync_local_control_token", "local-control-token");
    api.post.mockResolvedValue({ data: { data: { pull: { applied: 2 } } } });

    const run = await runSyncNow();
    await createSyncPairingCode({
      branch_id: "branch-1",
      till_id: "till-1",
      node_name: "Counter POS",
    });
    await createSyncPairingCode({
      branch_id: "branch-1",
      till_id: "till-1",
      node_name: "Counter POS",
      recovery_node_id: "node-1",
      recovery_authorized: true,
    });
    await createSyncPairingCode({
      branch_id: "branch-1",
      till_id: "till-1",
      node_name: "Counter POS replacement",
      replacement_node_id: "node-revoked",
    });

    expect(run.payload.pull.applied).toBe(2);
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/sync/run",
      {},
      expect.objectContaining({
        headers: { "X-Sync-Local-Control": "local-control-token" },
      }),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/sync/pairing-codes",
      { branch_id: "branch-1", till_id: "till-1", node_name: "Counter POS" },
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/sync/pairing-codes",
      expect.objectContaining({
        recovery_node_id: "node-1",
        recovery_authorized: true,
      }),
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/sync/pairing-codes",
      {
        branch_id: "branch-1",
        till_id: "till-1",
        node_name: "Counter POS replacement",
        replacement_node_id: "node-revoked",
      },
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
  });

  test("issues activation in Back Office and redeems it only through the local edge", async () => {
    api.post.mockResolvedValue({ data: { data: {
      terminal_activation_code: "TERM-ONE-TIME",
      terminal: { id: "till-2" },
    } } });

    await createTerminalActivationCode("node-1", "till-2");
    await redeemTerminalActivationCode(" term-one-time ");

    expect(api.post).toHaveBeenNthCalledWith(
      1,
      "/api/pos/sync/nodes/node-1/terminal-activation-codes",
      { till_id: "till-2" },
      expect.objectContaining({ salexaWorkspace: "back-office" }),
    );
    expect(api.post).toHaveBeenNthCalledWith(
      2,
      "/api/pos/sync/terminal-activations/redeem",
      { terminal_activation_code: "TERM-ONE-TIME" },
      expect.objectContaining({ salexaWorkspace: "front-office" }),
    );
  });

  test("does not treat authentication failures as a missing sync contract", () => {
    expect(isSyncContractUnavailable({ response: { status: 404 } })).toBe(true);
    expect(isSyncContractUnavailable({ response: { status: 401 } })).toBe(false);
  });

  test("verifies both CLI-issued browser credentials against one immutable edge identity", async () => {
    const verifiedResponse = {
      data: {
        data: {
          paired: true,
          deployment_mode: "edge",
          node_id: "node-1",
          company_id: "company-1",
          branch_id: "branch-1",
          till_id: "till-1",
        },
      },
    };
    api.get.mockResolvedValue(verifiedResponse);

    const status = await validateLocalSyncCredentials({
      local_control_token: " local-control-token ",
      till_token: " till-token ",
    });

    expect(status).toEqual(expect.objectContaining({
      deploymentMode: "edge",
      paired: true,
    }));
    expect(api.get).toHaveBeenNthCalledWith(
      1,
      "/api/pos/sync/status",
      expect.objectContaining({
        salexaWorkspace: "front-office",
        salexaLocalCredentialProbe: true,
        headers: { "X-Sync-Local-Control": "local-control-token" },
      }),
    );
    expect(api.get).toHaveBeenNthCalledWith(
      2,
      "/api/pos/sync/status",
      expect.objectContaining({
        salexaWorkspace: "front-office",
        salexaLocalCredentialProbe: true,
        headers: { "X-Till-Token": "till-token" },
      }),
    );
  });

  test("refuses tokens that resolve to different terminal identities", async () => {
    api.get
      .mockResolvedValueOnce({ data: { data: {
        paired: true,
        deployment_mode: "edge",
        node_id: "node-1",
        company_id: "company-1",
        branch_id: "branch-1",
        till_id: "till-1",
      } } })
      .mockResolvedValueOnce({ data: { data: {
        paired: true,
        deployment_mode: "edge",
        node_id: "node-2",
        company_id: "company-1",
        branch_id: "branch-1",
        till_id: "till-2",
      } } });

    await expect(validateLocalSyncCredentials({
      local_control_token: "local-control-token",
      till_token: "till-token",
    })).rejects.toThrow("same paired terminal");
  });
});

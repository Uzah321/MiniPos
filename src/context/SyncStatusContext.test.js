import {
  isEdgeAutoSyncEligible,
  isStandaloneLocalRuntime,
  localCredentialRecoveryStatusFromError,
  localTillIdentityFromPairing,
  statusAfterTransientSyncFailure,
} from "./SyncStatusContext";

describe("edge automatic synchronization guard", () => {
  const ready = {
    deploymentMode: "edge",
    localOnly: false,
    paired: true,
    pairingRequired: false,
    cloudConfigured: true,
  };

  test("runs only on an online, paired physical POS", () => {
    expect(isEdgeAutoSyncEligible({ status: ready, online: true, dedicatedFrontOffice: true })).toBe(true);
    expect(isEdgeAutoSyncEligible({ status: ready, online: false, dedicatedFrontOffice: true })).toBe(false);
    expect(isEdgeAutoSyncEligible({ status: ready, online: true, dedicatedFrontOffice: false })).toBe(false);
    expect(isEdgeAutoSyncEligible({
      status: { ...ready, tillSetupRequired: true },
      online: true,
      dedicatedFrontOffice: true,
    })).toBe(false);
  });

  test("does not run before pairing or cloud configuration", () => {
    expect(isEdgeAutoSyncEligible({
      status: { ...ready, paired: false, pairingRequired: true },
      online: true,
      dedicatedFrontOffice: true,
    })).toBe(false);
    expect(isEdgeAutoSyncEligible({
      status: { ...ready, cloudConfigured: false },
      online: true,
      dedicatedFrontOffice: true,
    })).toBe(false);
  });

  test("never schedules cloud synchronization for a standalone status", () => {
    expect(isEdgeAutoSyncEligible({
      status: {
        ...ready,
        deploymentMode: "standalone",
        localOnly: true,
      },
      online: true,
      dedicatedFrontOffice: true,
    })).toBe(false);

    expect(isEdgeAutoSyncEligible({
      status: { ...ready, localOnly: true },
      online: true,
      dedicatedFrontOffice: true,
    })).toBe(false);
  });

  test("blocks manual cloud sync as soon as either runtime config or status proves local-only", () => {
    expect(isStandaloneLocalRuntime({ backendMode: "standalone" })).toBe(true);
    expect(isStandaloneLocalRuntime({
      backendMode: "",
      status: { deploymentMode: "standalone", localOnly: true },
    })).toBe(true);
    expect(isStandaloneLocalRuntime({
      backendMode: "edge",
      status: { deploymentMode: "edge", localOnly: false },
    })).toBe(false);
  });

  test("extracts the one-time local till credential and immutable branch binding", () => {
    expect(localTillIdentityFromPairing({
      payload: {
        till: { id: "till-1", till_code: "POS-01", branch_id: "branch-1" },
        till_token: "one-time-local-token",
      },
      status: {},
    })).toEqual(expect.objectContaining({
      id: "till-1",
      till_code: "POS-01",
      branch_id: "branch-1",
      till_token: "one-time-local-token",
    }));
  });

  test("preserves safe edge status when browser-local terminal activation is missing", () => {
    const status = localCredentialRecoveryStatusFromError({
      response: {
        status: 428,
        data: {
          code: "SYNC_LOCAL_CONTROL_REQUIRED",
          data: {
            deployment_mode: "edge",
            paired: true,
            pairing_required: false,
            local_control_required: true,
          },
        },
      },
    });

    expect(status).toEqual(expect.objectContaining({
      deploymentMode: "edge",
      paired: true,
      pairingRequired: false,
      tillSetupRequired: true,
      sellReady: false,
    }));
    expect(localCredentialRecoveryStatusFromError({ response: { status: 428, data: {} } })).toBeNull();
  });

  test("a rate-limited status refresh preserves the verified sale workspace identity", () => {
    const current = {
      deploymentMode: "edge",
      paired: true,
      pairingRequired: false,
      tillSetupRequired: false,
      sellReady: true,
      localBackendReachable: true,
      company: { id: "company-1" },
      branch: { id: "branch-1" },
      till: { id: "till-1" },
      directions: {
        posToCloud: { inSync: true, state: "synced" },
        cloudToPos: { inSync: true, state: "synced" },
      },
    };

    const next = statusAfterTransientSyncFailure(
      current,
      "Synchronization status is busy and will retry automatically. Local trading remains available.",
    );

    expect(next).toEqual(expect.objectContaining({
      paired: true,
      tillSetupRequired: false,
      sellReady: true,
      localBackendReachable: true,
      company: { id: "company-1" },
      branch: { id: "branch-1" },
      till: { id: "till-1" },
    }));
    expect(next.directions.posToCloud.inSync).toBe(false);
    expect(next.directions.cloudToPos.inSync).toBe(false);
  });
});

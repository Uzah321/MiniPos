import React from "react";
import { render, screen } from "@testing-library/react";
import SyncStatusBadge, { describeSyncDirections } from "./SyncStatusBadge";
import { useOptionalSyncStatus } from "../context/SyncStatusContext";

jest.mock("../context/SyncStatusContext", () => ({
  useOptionalSyncStatus: jest.fn(),
}));

describe("SyncStatusBadge", () => {
  const edgeStatus = {
    deploymentMode: "edge",
    paired: true,
    directions: {
      posToCloud: { state: "synced", inSync: true, pending: 0 },
      cloudToPos: { state: "pending", inSync: false, pending: 2 },
    },
  };

  beforeEach(() => {
    jest.clearAllMocks();
    useOptionalSyncStatus.mockReturnValue({
      status: edgeStatus,
      initialLoading: false,
      contractAvailable: true,
      running: false,
      error: "",
    });
  });

  test("shows independent POS-to-cloud and cloud-to-POS proof", () => {
    render(<SyncStatusBadge />);

    expect(screen.getByLabelText("POS → Cloud: green")).toHaveClass("text-[#117556]");
    expect(screen.getByLabelText("Cloud → POS: red")).toHaveClass("text-[#B42318]");
    expect(screen.getByLabelText("Synchronization: not synced in both directions")).toBeInTheDocument();
    expect(screen.queryByText("POS → Cloud")).not.toBeInTheDocument();
    expect(screen.queryByText("Cloud → POS")).not.toBeInTheDocument();
    expect(screen.queryByTitle(/pending/i)).not.toBeInTheDocument();
  });

  test("announces synchronized only when both directional proofs are explicit", () => {
    useOptionalSyncStatus.mockReturnValue({
      status: {
        ...edgeStatus,
        directions: {
          posToCloud: { state: "synced", inSync: true, pending: 0 },
          cloudToPos: { state: "synced", inSync: true, pending: 0 },
        },
      },
      initialLoading: false,
      contractAvailable: true,
      running: false,
      error: "",
    });

    render(<SyncStatusBadge />);

    expect(screen.getByLabelText("Synchronization: synced in both directions")).toBeInTheDocument();
  });

  test("never reports green while a synchronization run is active", () => {
    const directions = describeSyncDirections({ status: edgeStatus, running: true });

    expect(directions.every((direction) => direction.inSync === false)).toBe(true);
    expect(directions.every((direction) => direction.state === "syncing")).toBe(true);
  });

  test("shows a binary icon without status text for non-edge deployments", () => {
    useOptionalSyncStatus.mockReturnValue({
      status: { deploymentMode: "cloud", localBackendReachable: true },
      initialLoading: false,
      contractAvailable: true,
      running: false,
      error: "",
    });

    render(<SyncStatusBadge />);

    expect(screen.getByLabelText("Synchronization indicator: green")).toHaveClass("text-[#117556]");
    expect(screen.queryByText("Cloud hub")).not.toBeInTheDocument();
  });
});

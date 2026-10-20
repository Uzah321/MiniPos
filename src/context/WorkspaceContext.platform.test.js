import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import { WorkspaceProvider, useWorkspace } from "./WorkspaceContext";
import { getWorkspaceBootstrap } from "../services/WorkspaceService";

jest.mock("../services/WorkspaceService", () => ({
  getWorkspaceBootstrap: jest.fn(),
}));

const Probe = () => {
  const workspace = useWorkspace();
  return (
    <div>
      <span>{workspace.mode}</span>
      <span>{workspace.loading ? "loading" : "ready"}</span>
      <span>{workspace.company ? "company" : "no-company"}</span>
    </div>
  );
};

describe("platform-only workspace context", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    sessionStorage.clear();
  });

  test("provides a tenantless platform workspace without bootstrapping a company", async () => {
    render(
      <WorkspaceProvider workspace="back-office" platformOnly>
        <Probe />
      </WorkspaceProvider>,
    );

    await waitFor(() => expect(screen.getByText("ready")).toBeInTheDocument());
    expect(screen.getByText("platform")).toBeInTheDocument();
    expect(screen.getByText("no-company")).toBeInTheDocument();
    expect(getWorkspaceBootstrap).not.toHaveBeenCalled();
  });
});

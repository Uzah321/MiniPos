import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import LocalCredentialImportForm from "./LocalCredentialImportForm";
import { useSyncStatus } from "../context/SyncStatusContext";

jest.mock("../context/SyncStatusContext", () => ({
  useSyncStatus: jest.fn(),
}));

describe("LocalCredentialImportForm", () => {
  const importLocalCredentials = jest.fn();

  beforeEach(() => {
    jest.clearAllMocks();
    useSyncStatus.mockReturnValue({
      importLocalCredentials,
      importingLocalCredentials: false,
      credentialImportError: "",
    });
  });

  test("keeps CLI tokens masked and imports both without asking for a node secret", async () => {
    importLocalCredentials.mockResolvedValue({ node: { id: "node-1" } });
    const onImported = jest.fn();
    render(<LocalCredentialImportForm onImported={onImported} />);

    const controlInput = screen.getByLabelText("Server recovery code");
    const tillInput = screen.getByLabelText("Terminal recovery code");
    expect(controlInput).toHaveAttribute("type", "password");
    expect(tillInput).toHaveAttribute("type", "password");
    expect(screen.queryByLabelText(/node signing secret/i)).not.toBeInTheDocument();

    fireEvent.change(controlInput, { target: { value: " control-token " } });
    fireEvent.change(tillInput, { target: { value: " till-token " } });
    fireEvent.click(screen.getByRole("button", { name: "Verify and Save Codes" }));

    await waitFor(() => expect(importLocalCredentials).toHaveBeenCalledWith({
      local_control_token: "control-token",
      till_token: "till-token",
    }));
    expect(onImported).toHaveBeenCalledWith({ node: { id: "node-1" } });
    await waitFor(() => {
      expect(controlInput).toHaveValue("");
      expect(tillInput).toHaveValue("");
    });
  });

  test("does not submit a partial credential set", () => {
    render(<LocalCredentialImportForm />);

    fireEvent.change(screen.getByLabelText("Server recovery code"), {
      target: { value: "control-token" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Verify and Save Codes" }));

    expect(screen.getByRole("alert")).toHaveTextContent("Enter both values");
    expect(importLocalCredentials).not.toHaveBeenCalled();
  });
});

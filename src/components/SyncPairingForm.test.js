import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import SyncPairingForm from "./SyncPairingForm";
import EnterpriseConfirmHost from "./EnterpriseConfirmHost";
import { useSyncStatus } from "../context/SyncStatusContext";

jest.mock("../context/SyncStatusContext", () => ({
  useSyncStatus: jest.fn(),
}));

describe("SyncPairingForm", () => {
  const pairNode = jest.fn();

  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    useSyncStatus.mockReturnValue({ pairNode, pairing: false, error: "" });
  });

  test("submits only the operator codes with the installer-configured cloud origin", async () => {
    pairNode.mockResolvedValue({ paired: true });
    render(<SyncPairingForm initialCloudUrl="https://cloud.example.test" />);

    expect(screen.getByText("https://cloud.example.test")).toBeInTheDocument();
    expect(screen.getByText(/configured by the installer and locked/i)).toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: /cloud url/i })).not.toBeInTheDocument();

    fireEvent.change(screen.getByRole("textbox", { name: /company code/i }), {
      target: { value: "acme" },
    });
    fireEvent.change(screen.getByRole("textbox", { name: /one-time pos server pairing code/i }), {
      target: { value: "node-1.abcdefghijkl-mnopqrstuvwx" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Pair POS Server" }));

    await waitFor(() => expect(pairNode).toHaveBeenCalledWith({
      company_code: "ACME",
      pairing_code: "NODE-1.ABCDEFGHIJKL-MNOPQRSTUVWX",
      cloud_url: "https://cloud.example.test",
    }));
  });

  test("does not send a TERM browser code to the POS server pairing endpoint", () => {
    render(<SyncPairingForm initialCloudUrl="https://cloud.example.test" />);

    fireEvent.change(screen.getByRole("textbox", { name: /company code/i }), {
      target: { value: "acme" },
    });
    fireEvent.change(screen.getByRole("textbox", { name: /one-time pos server pairing code/i }), {
      target: { value: "TERM-0123ABCD-4567EF89-A1B2C3D4-E5F60718" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Pair POS Server" }));

    expect(screen.getByRole("alert")).toHaveTextContent(/browser activation code/i);
    expect(pairNode).not.toHaveBeenCalled();
  });

  test("requires explicit confirmation before redeeming a revoked-server replacement code", async () => {
    pairNode.mockResolvedValue({ paired: true });
    render(<><EnterpriseConfirmHost /><SyncPairingForm replacement initialCloudUrl="https://cloud.example.test" /></>);

    fireEvent.change(screen.getByRole("textbox", { name: /company code/i }), {
      target: { value: "acme" },
    });
    fireEvent.change(screen.getByRole("textbox", { name: /one-time revoked server replacement code/i }), {
      target: { value: "node-2.abcdefghijkl-mnopqrstuvwx" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Replace Revoked POS Server" }));

    expect(await screen.findByRole("dialog", { name: "Replace Revoked Server" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Replace server" }));
    await waitFor(() => expect(pairNode).toHaveBeenCalledWith({
      company_code: "ACME",
      pairing_code: "NODE-2.ABCDEFGHIJKL-MNOPQRSTUVWX",
      cloud_url: "https://cloud.example.test",
    }));
  });

  test("blocks pairing until the local API reports a configured cloud server", () => {
    const { rerender } = render(<SyncPairingForm />);

    expect(screen.getByRole("alert")).toHaveTextContent("SERVENEST_SYNC_CLOUD_URL");
    expect(screen.getByRole("button", { name: "Pair POS Server" })).toBeDisabled();

    rerender(<SyncPairingForm initialCloudUrl="http://127.0.0.1:8082" />);

    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    expect(screen.getByText("http://127.0.0.1:8082")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Pair POS Server" })).toBeEnabled();
  });
});

import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import CurrentTerminalPrinterModal from "./CurrentTerminalPrinterModal";
import {
  deleteCurrentTerminalQzCertificate,
  getCurrentTerminalKitchenOutput,
  getCurrentTerminalPrinters,
  getCurrentTerminalQzCertificate,
  saveCurrentTerminalQzCertificate,
  updateCurrentTerminalKitchenOutput,
} from "../services/CurrentTerminalService";
import { qzConnect } from "../services/QzService";

jest.mock("../services/CurrentTerminalService", () => ({
  createCurrentTerminalPrinter: jest.fn(),
  deleteCurrentTerminalPrinter: jest.fn(),
  getCurrentTerminalPrinters: jest.fn(),
  updateCurrentTerminalPrinter: jest.fn(),
  getCurrentTerminalQzCertificate: jest.fn(),
  saveCurrentTerminalQzCertificate: jest.fn(),
  deleteCurrentTerminalQzCertificate: jest.fn(),
  getCurrentTerminalKitchenOutput: jest.fn(),
  updateCurrentTerminalKitchenOutput: jest.fn(),
}));

jest.mock("../services/QzService", () => ({
  qzConnect: jest.fn(),
  qzDisconnect: jest.fn(() => Promise.resolve(true)),
  qzGetDefaultPrinter: jest.fn(),
  qzGetPrinters: jest.fn(),
  qzPrintImageBase64: jest.fn(),
}));

const readyCertificate = {
  id: "certificate-1",
  name: "Counter One QZ",
  is_active: true,
  certificate_configured: true,
  private_key_configured: true,
};

describe("Front Office current-terminal QZ security", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getCurrentTerminalPrinters.mockResolvedValue({ data: [] });
    getCurrentTerminalQzCertificate.mockResolvedValue({ data: null });
    getCurrentTerminalKitchenOutput.mockResolvedValue({
      data: {
        mode: "kitchen_printer",
        auto_print: true,
        copies: 1,
        capabilities: {
          kitchen_printer: { available: true },
          kds: { available: false, status: "planned" },
        },
        ready: false,
      },
    });
    updateCurrentTerminalKitchenOutput.mockResolvedValue({
      data: { mode: "kitchen_printer", auto_print: true, copies: 1, ready: false },
    });
    saveCurrentTerminalQzCertificate.mockResolvedValue({
      message: "Saved",
      data: readyCertificate,
    });
    deleteCurrentTerminalQzCertificate.mockResolvedValue({ message: "Removed" });
  });

  test("requires QZ security before detecting local printers", async () => {
    render(<CurrentTerminalPrinterModal open onClose={jest.fn()} />);

    await screen.findByText("QZ Tray Security");
    fireEvent.click(screen.getByRole("button", { name: "Detect Local" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Configure an active QZ certificate and private key",
    );
    expect(qzConnect).not.toHaveBeenCalled();
  });

  test("uploads the certificate and matching private key together", async () => {
    render(<CurrentTerminalPrinterModal open onClose={jest.fn()} />);

    await screen.findByText("QZ Tray Security");
    const addCredentials = screen.getByRole("button", { name: "Add Certificate & Key" });
    await waitFor(() => expect(addCredentials).toBeEnabled());
    fireEvent.click(addCredentials);

    const certificate = new File(["certificate"], "digital-certificate.pem", {
      type: "application/x-pem-file",
    });
    const privateKey = new File(["private-key"], "private-key.pem", {
      type: "application/x-pem-file",
    });

    fireEvent.change(screen.getByLabelText("Certificate File"), {
      target: { files: [certificate] },
    });
    fireEvent.change(screen.getByLabelText("Private Key File"), {
      target: { files: [privateKey] },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save Credentials" }));

    await waitFor(() => expect(saveCurrentTerminalQzCertificate).toHaveBeenCalledTimes(1));
    expect(saveCurrentTerminalQzCertificate).toHaveBeenCalledWith(expect.objectContaining({
      certificate,
      private_key: privateKey,
      is_active: true,
    }));
  });

  test("shows only safe credential status and removes the current terminal pair", async () => {
    getCurrentTerminalQzCertificate.mockResolvedValue({ data: readyCertificate });
    render(<CurrentTerminalPrinterModal open onClose={jest.fn()} />);

    expect(await screen.findByText("Counter One QZ")).toBeInTheDocument();
    expect(screen.getByText("Certificate ready")).toBeInTheDocument();
    expect(screen.getByText("Signing key ready")).toBeInTheDocument();
    expect(screen.queryByText(/BEGIN PRIVATE KEY/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Remove" }));
    fireEvent.click(screen.getByRole("button", { name: "Confirm Remove" }));

    await waitFor(() => expect(deleteCurrentTerminalQzCertificate).toHaveBeenCalledTimes(1));
  });

  test("shows kitchen printer output and keeps the planned KDS mode disabled", async () => {
    render(<CurrentTerminalPrinterModal open onClose={jest.fn()} />);

    expect(await screen.findByText("Kitchen order output")).toBeInTheDocument();
    expect(screen.getByRole("radio", { name: "Kitchen printer" })).toBeChecked();
    expect(screen.getByRole("radio", { name: "KDS (coming later)" })).toBeDisabled();

    const saveOutput = screen.getByRole("button", { name: "Save kitchen output" });
    await waitFor(() => expect(saveOutput).toBeEnabled());
    fireEvent.click(saveOutput);

    await waitFor(() => expect(updateCurrentTerminalKitchenOutput).toHaveBeenCalledWith(
      expect.objectContaining({ mode: "kitchen_printer", auto_print: true, copies: 1 }),
    ));
  });

  test("keeps setup controls read-only without Printer Management permission", async () => {
    render(<CurrentTerminalPrinterModal open onClose={jest.fn()} canManagePrinters={false} />);

    expect(await screen.findByText(/Printer status is read-only/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Save kitchen output" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Add Certificate & Key" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Detect Local" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Add Printer" })).toBeDisabled();
    expect(updateCurrentTerminalKitchenOutput).not.toHaveBeenCalled();
  });
});

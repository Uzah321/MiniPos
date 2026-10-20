import api from "../utils/apiBack";
import {
  createCurrentTerminalPrinter,
  deleteCurrentTerminalQzCertificate,
  deleteCurrentTerminalPrinter,
  getCurrentTerminalPrinters,
  getCurrentTerminalKitchenOutput,
  getCurrentTerminalQzCertificate,
  getCurrentTerminalSettings,
  saveCurrentTerminalQzCertificate,
  updateCurrentTerminalPrinter,
  updateCurrentTerminalKitchenOutput,
  updateCurrentTerminalSettings,
} from "./CurrentTerminalService";

jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: {
    get: jest.fn(),
    post: jest.fn(),
    put: jest.fn(),
    patch: jest.fn(),
    delete: jest.fn(),
  },
}));

describe("current physical terminal service", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    [api.get, api.post, api.put, api.patch, api.delete].forEach((method) => {
      method.mockResolvedValue({ data: { data: [] } });
    });
  });

  test("reads and updates only the current terminal using Front Office identity", async () => {
    await getCurrentTerminalSettings();
    await updateCurrentTerminalSettings(" Counter One ");

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/tills/current/settings",
      { salexaWorkspace: "front-office" },
    );
    expect(api.patch).toHaveBeenCalledWith(
      "/api/pos/tills/current/settings",
      { device_label: "Counter One" },
      { salexaWorkspace: "front-office" },
    );
  });

  test("uses only current-terminal printer routes", async () => {
    const payload = { printer_name: "EPSON", target_printing: "receipt" };

    await getCurrentTerminalPrinters();
    await createCurrentTerminalPrinter(payload);
    await updateCurrentTerminalPrinter("printer/id", payload);
    await deleteCurrentTerminalPrinter("printer/id");

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/tills/current/printers",
      { salexaWorkspace: "front-office" },
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/pos/tills/current/printers",
      payload,
      { salexaWorkspace: "front-office" },
    );
    expect(api.put).toHaveBeenCalledWith(
      "/api/pos/tills/current/printers/printer%2Fid",
      payload,
      { salexaWorkspace: "front-office" },
    );
    expect(api.delete).toHaveBeenCalledWith(
      "/api/pos/tills/current/printers/printer%2Fid",
      { salexaWorkspace: "front-office" },
    );
  });

  test("uploads QZ credentials only to the current terminal route", async () => {
    const certificate = new File(["certificate"], "digital-certificate.txt", { type: "text/plain" });
    const privateKey = new File(["key"], "private-key.pem", { type: "text/plain" });

    await getCurrentTerminalQzCertificate();
    await saveCurrentTerminalQzCertificate({
      name: "Counter One",
      certificate,
      private_key: privateKey,
      is_active: true,
      notes: "Local QZ signing",
      branch_id: "must-not-be-sent",
      till_id: "must-not-be-sent",
    });
    await deleteCurrentTerminalQzCertificate();

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/tills/current/qz-certificate",
      { salexaWorkspace: "front-office" },
    );

    const [, body, config] = api.post.mock.calls[0];
    expect(api.post.mock.calls[0][0]).toBe("/api/pos/tills/current/qz-certificate");
    expect(config).toEqual({
      salexaWorkspace: "front-office",
      headers: { "Content-Type": "multipart/form-data" },
    });
    expect(body.get("certificate")).toBe(certificate);
    expect(body.get("private_key")).toBe(privateKey);
    expect(body.get("is_active")).toBe("1");
    expect(body.has("branch_id")).toBe(false);
    expect(body.has("till_id")).toBe(false);

    expect(api.delete).toHaveBeenCalledWith(
      "/api/pos/tills/current/qz-certificate",
      { salexaWorkspace: "front-office" },
    );
  });

  test("reads and safely updates the current till kitchen output", async () => {
    await getCurrentTerminalKitchenOutput();
    await updateCurrentTerminalKitchenOutput({
      mode: "kitchen_printer",
      auto_print: true,
      copies: 99,
      till_id: "must-not-be-sent",
    });

    expect(api.get).toHaveBeenCalledWith(
      "/api/pos/tills/current/kitchen-output",
      { salexaWorkspace: "front-office" },
    );
    expect(api.put).toHaveBeenCalledWith(
      "/api/pos/tills/current/kitchen-output",
      { mode: "kitchen_printer", auto_print: true, copies: 5 },
      { salexaWorkspace: "front-office" },
    );
  });
});

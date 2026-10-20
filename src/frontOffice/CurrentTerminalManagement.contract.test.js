import fs from "fs";
import path from "path";

const read = (file) => fs.readFileSync(path.join(__dirname, file), "utf8");

describe("Front Office current-terminal management contract", () => {
  const layout = read("FrontOfficeLayout.js");
  const terminal = read("CurrentTerminalModal.js");
  const printers = read("CurrentTerminalPrinterModal.js");
  const service = read("../services/CurrentTerminalService.js");
  const qzService = read("../services/QzService.js");

  test("keeps visible Printer Setup and This Terminal tiles on every Front Office", () => {
    expect(layout).toContain('label="Printer Setup"');
    expect(layout).toContain('label="This Terminal"');
    expect(layout).toContain("<CurrentTerminalPrinterModal");
    expect(layout).toContain("<CurrentTerminalModal");
    expect(layout).toContain('window.addEventListener("salexa_till_updated"');
  });

  test("allows only the device label to be edited in terminal settings", () => {
    expect(terminal).toContain("updateCurrentTerminalSettings(deviceLabel)");
    expect(terminal).toContain("Only this label is editable.");
    expect(terminal).not.toContain("updateTill(");
    expect(terminal).not.toContain("branch_id:");
    expect(terminal).not.toContain("company_id:");
  });

  test("uses terminal-scoped printer and QZ security APIs without global till selection", () => {
    expect(service).toContain('salexaWorkspace: "front-office"');
    expect(service).toContain('"/api/pos/tills/current/printers"');
    expect(service).toContain('"/api/pos/tills/current/qz-certificate"');
    expect(service).not.toContain('"/api/pos/printers"');
    expect(printers).not.toContain("TillManagementModal");
    expect(printers).not.toContain("PrinterManagementModal");
    expect(printers).not.toContain("branch_id");
    expect(printers).not.toContain("till_id");
    expect(printers).toContain("getCurrentTerminalQzCertificate");
    expect(printers).toContain("saveCurrentTerminalQzCertificate");
    expect(printers).toContain("deleteCurrentTerminalQzCertificate");
    expect(printers).toContain("private key uploads directly to the local POS server");
    expect(printers).toContain("qzGetPrinters");
    expect(printers).toContain("qzGetDefaultPrinter");
    expect(printers).toContain("qzPrintImageBase64");
  });

  test("QZ signing identifies Front Office with its stored till token rather than asserted ids", () => {
    expect(qzService).toContain("getLocalTillIdentity");
    expect(qzService).toContain('salexaWorkspace: "front-office"');
    expect(qzService).toContain("Do not assert a");
    expect(qzService).toContain('salexaWorkspace: "back-office"');
  });
});

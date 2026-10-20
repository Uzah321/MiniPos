import fs from "fs";
import path from "path";

const read = (relativePath) => fs.readFileSync(path.join(__dirname, relativePath), "utf8");

describe("system modal consistency", () => {
  test("reachable source does not use native browser prompts", () => {
    const files = [
      "backOffice/pages/sales/CreditCustomerManagementModal.js",
      "backOffice/pages/purchases/PurchaseManagementModal.js",
    ];

    files.forEach((file) => {
      const source = read(file);
      expect(source).not.toMatch(/window\.(prompt|confirm|alert)\s*\(/);
      expect(source).toContain("EnterpriseFormModal");
      expect(source).toContain("Reason is required.");
    });
  });

  test("shared and front-office action dialogs use the large modal frame", () => {
    [
      "components/ManagerApprovalModal.js",
      "components/ReportDownloadModal.js",
      "components/ReportOutputChooser.js",
      "frontOffice/CurrentTerminalModal.js",
      "frontOffice/FrontOfficeLayout.js",
    ].forEach((file) => expect(read(file)).toContain("sx-large-modal-window"));
  });

  test("success, error and confirm notices use the compact dialog card", () => {
    [
      "components/ReusableMessageModal.js",
      "components/EnterpriseConfirmModal.js",
      "components/FullScreenModal.js",
    ].forEach((file) => {
      const source = read(file);
      expect(source).toContain("sx-compact-dialog-window");
      expect(source).not.toContain("sx-large-modal-window");
    });
  });

  test("MUI dialogs share the large responsive frame while preserving full-screen dialogs", () => {
    const source = read("backOffice/BackOfficeThemeProvider.js");
    expect(source).toContain("ownerState.fullScreen");
    expect(source).toContain('width: "min(1180px, calc(100vw - 28px))"');
    expect(source).toContain('height: "min(90vh, 820px)"');
  });

  test("operational POS dialogs use the shared large responsive frame", () => {
    const expectedFrames = {
      "backOffice/boh/dayend/DayEndEnterpriseGuardModal.js": 1,
      "backOffice/pages/quotation/QuotationDeskModal.js": 3,
      "backOffice/pages/product/ProductImportModal.js": 1,
      "backOffice/pages/sales/CreateSale.js": 8,
      "backOffice/pages/sales/components/FastFoodLineSplitModal.js": 1,
      "backOffice/pages/sales/components/FastFoodLinePrepMessageModal.js": 1,
      "backOffice/pages/sales/components/FastFoodLineModifierModal.js": 1,
      "backOffice/pages/sales/components/PosCustomerAccountModal.js": 1,
    };

    Object.entries(expectedFrames).forEach(([file, count]) => {
      expect((read(file).match(/sx-large-modal-window/g) || [])).toHaveLength(count);
    });
  });
});

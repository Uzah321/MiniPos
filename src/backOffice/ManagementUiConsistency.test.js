import fs from "fs";
import path from "path";

const read = (relativePath) => fs.readFileSync(path.join(__dirname, relativePath), "utf8");

describe("management UI consistency", () => {
  test("uses the shared large form modal for management add and edit flows", () => {
    const authentication = read("pages/admin/AuthenticationAdministrationModal.js");
    const users = read("pages/admin/UserManagment.js");
    const suppliers = read("pages/purchases/SupplierModal.js");
    const printers = read("pages/intergration/PrinterManagementModal.js");
    const tables = read("boh/hospitality/HospitalityTableServiceModal.js");
    const terminalPrinters = read("../frontOffice/CurrentTerminalPrinterModal.js");
    const manufacturingWorkflow = read("pages/manufacturing/ManufacturingOrderWorkflowModal.js");

    expect(authentication.match(/<EnterpriseFormModal/g)).toHaveLength(2);
    expect(authentication).not.toContain("lg:grid-cols-[380px_minmax(0,1fr)]");
    expect(authentication).not.toContain("xl:grid-cols-[500px_minmax(0,1fr)]");
    expect(users).toContain("<EnterpriseFormModal");
    expect(users).not.toContain('max-w-[980px]');
    expect(suppliers).toContain("<EnterpriseFormModal");
    expect(suppliers).not.toContain('max-w-[720px]');
    expect(printers.match(/<EnterpriseFormModal/g)).toHaveLength(2);
    expect(tables).toContain("<EnterpriseFormModal");
    expect(terminalPrinters).toContain('max-w-[1180px]');
    expect(manufacturingWorkflow).toContain('className="sx-large-modal-window');
    expect(manufacturingWorkflow).not.toContain('className="w-full max-w-lg border bg-white shadow-2xl"');
  });

  test("uses the shared filter structure in common registers", () => {
    expect(read("pages/admin/UserManagment.js")).toContain("<EnterpriseFilterBar");
    expect(read("pages/purchases/SupplierModal.js")).toContain("<EnterpriseFilterBar");
    expect(read("pages/discounts/DiscountManagementModal.js")).toContain("<EnterpriseFilterBar");
  });

  test("keeps row commands behind the shared vertical action menu", () => {
    expect(read("pages/admin/UserManagment.js")).toContain("<EnterpriseRowActionMenu");
    expect(read("pages/purchases/SupplierModal.js")).toContain("<EnterpriseRowActionMenu");
    expect(read("pages/discounts/DiscountManagementModal.js")).toContain("<EnterpriseRowActionMenu");
    expect(read("boh/hospitality/HospitalityTableServiceModal.js")).toContain("<EnterpriseRowActionMenu");
  });

  test("uses branded confirmation instead of browser confirm in core setup screens", () => {
    [
      "pages/admin/HospitalityStructureManagementModal.js",
      "pages/admin/TillManagementModal.js",
      "pages/admin/UserManagment.js",
      "pages/purchases/SupplierModal.js",
      "pages/admin/AuthenticationAdministrationModal.js",
      "pages/discounts/DiscountManagementModal.js",
      "pages/intergration/PrinterManagementModal.js",
    ].forEach((file) => {
      const source = read(file);
      expect(source).toContain("<EnterpriseConfirmModal");
      expect(source).not.toContain("window.confirm");
    });
  });

  test("sends a user's home shop as default_branch_id so it never conflicts with the session branch", () => {
    const users = read("pages/admin/UserManagment.js");
    const buildPayload = users.slice(
      users.indexOf("const buildPayload = () => {"),
      users.indexOf("const validateForm = () => {"),
    );

    expect(buildPayload).toContain("default_branch_id: defaultBranchId");
    expect(buildPayload).not.toMatch(/^\s*branch_id:/m);
  });

  test("keeps native browser confirmation out of every reachable workflow", () => {
    [
      "boh/foodManufacturing/FoodManufacturingPlanningModal.js",
      "boh/foodManufacturing/FoodRecipeBomModal.js",
      "boh/inventory/InventoryManagementModal.js",
      "pages/admin/CurrencyManagementModal.js",
      "pages/admin/DeploymentSyncModal.js",
      "pages/admin/GeneralSettings.js",
      "pages/admin/PaymentTypeManagementModal.js",
      "pages/admin/Roles.js",
      "pages/admin/ShiftConfiguration.js",
      "pages/intergration/ZimraFiscalisationModal.js",
      "pages/manufacturing/ManufacturingOrderWorkflowModal.js",
      "pages/manufacturing/ManufacturingPlanManagementModal.js",
      "pages/manufacturing/ManufacturingPlanRegisterModal.js",
      "pages/manufacturing/ManufacturingRecipeManagementModal.js",
      "pages/manufacturing/ManufacturingRecipeRegisterModal.js",
      "pages/product/PriceLevelModal.js",
      "pages/product/ProductCategoryModal.js",
      "pages/product/ProductManagementFullModal.js",
      "pages/product/StockTakeModal.js",
      "pages/product/StockTransferModal.js",
      "pages/product/TaxProductTaxManagementModal.js",
      "pages/quotation/QuotationDeskModal.js",
      "pages/sales/ReceiptActionsModal.js",
      "../components/SyncPairingForm.js",
    ].forEach((file) => expect(read(file)).not.toContain("window.confirm"));
  });
});

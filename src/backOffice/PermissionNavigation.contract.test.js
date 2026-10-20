import fs from "fs";
import path from "path";

const adminLayout = fs.readFileSync(path.join(__dirname, "AdminLayOut.js"), "utf8");
const paymentTypeModal = fs.readFileSync(
  path.join(__dirname, "pages", "admin", "PaymentTypeManagementModal.js"),
  "utf8"
);

describe("permission navigation contracts", () => {
  test("accepts the canonical quotation-modal permission and its transition alias", () => {
    expect(adminLayout).toContain(
      'permission: ["view_quotation_modal", "view_qoutation_modal"]'
    );
  });

  test("uses granular Payment Type permissions for navigation and actions", () => {
    expect(adminLayout).not.toContain('permission: "manage_payment_types"');
    expect(adminLayout.match(/permission: "view_payment_type"/g)).toHaveLength(1);
    expect(adminLayout).not.toContain('name: "Payment Methods & Gateways"');
    expect(adminLayout).toContain('canView={hasMenuPermission(user, "view_payment_type")}');
    expect(adminLayout).toContain('canCreate={hasMenuPermission(user, "create_payment_type")}');
    expect(adminLayout).toContain('canUpdate={hasMenuPermission(user, "update_payment_type")}');
    expect(adminLayout).toContain('canDelete={hasMenuPermission(user, "delete_payment_type")}');

    expect(paymentTypeModal).toContain("{canCreate && (");
    expect(paymentTypeModal).toContain("{canUpdate && (");
    expect(paymentTypeModal).toContain("{canDelete && (");
  });
});

import fs from "fs";
import path from "path";

const adminLayout = fs.readFileSync(path.join(__dirname, "AdminLayOut.js"), "utf8");

describe("authentication administration navigation", () => {
  test("gates the management workspace by either canonical permission", () => {
    expect(adminLayout).toContain('name: "Authentication & Access"');
    expect(adminLayout).toContain('modal: "authentication-administration"');
    expect(adminLayout).toContain('permission: ["manage_access_assignments", "manage_authentication_policies"]');
    expect(adminLayout).toContain('canManageAssignments={hasMenuPermission(user, "manage_access_assignments")}');
    expect(adminLayout).toContain('canManagePolicies={hasMenuPermission(user, "manage_authentication_policies")}');
  });

  test("lets a platform administrator use server-authorized platform semantics", () => {
    expect(adminLayout).toContain("platformAdminAllowed: true");
    expect(adminLayout).toContain("item.platformAdminAllowed && userIsPlatformAdmin(user)");
    expect(adminLayout).toContain("child?.platformAdminAllowed && userIsPlatformAdmin(user)");
    expect(adminLayout).toContain("isPlatformAdmin={userIsPlatformAdmin(user)}");
  });
});

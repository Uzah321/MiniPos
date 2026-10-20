import {
  canAccessWorkspace,
  isPlatformAdministrator,
  isUnscopedPlatformAdministrator,
  isSuperAdministrator,
  mergePlatformAdministratorIdentity,
} from "./workspaceAccess";

describe("workspace super administrator detection", () => {
  test.each([
    [{ is_super_admin: true }],
    [{ role: { name: "Super Administrator" } }],
    [{ user_type: "SUPER_ADMIN" }],
  ])("recognizes supported server identities", (user) => {
    expect(isSuperAdministrator(user)).toBe(true);
    expect(canAccessWorkspace(user, "front-office")).toBe(true);
    expect(canAccessWorkspace(user, "back-office")).toBe(true);
  });

  test("does not elevate a normal tenant administrator", () => {
    expect(isSuperAdministrator({ role: { name: "admin" } })).toBe(false);
  });

  test.each([
    [{ is_platform_admin: true }],
    [{ platform_admin: "1" }],
  ])("recognizes explicit server platform flags", (user) => {
    expect(isPlatformAdministrator(user)).toBe(true);
  });

  test.each([
    [{ is_super_admin: true }],
    [{ role: { name: "platform_admin" } }],
    [{ role_name: "platform-admin" }],
    [{ user_type: "SUPER_ADMIN" }],
  ])("does not infer platform access from tenant identity", (user) => {
    expect(isPlatformAdministrator(user)).toBe(false);
  });

  test("merges the top-level login platform decision into the stored user", () => {
    expect(mergePlatformAdministratorIdentity(
      { id: "user-1", is_platform_admin: true },
      { platform_admin: false },
    )).toEqual(expect.objectContaining({
      id: "user-1",
      is_platform_admin: false,
      platform_admin: false,
    }));

    expect(mergePlatformAdministratorIdentity(
      { id: "user-2" },
      {
        is_platform_admin: true,
        platform_context_required: true,
        platform_scope: "company-management",
      },
    )).toEqual(expect.objectContaining({
      is_platform_admin: true,
      platform_admin: true,
      platform_context_required: true,
      platform_scope: "company-management",
    }));
  });

  test("recognizes only an explicitly unscoped platform-owner session", () => {
    expect(isUnscopedPlatformAdministrator({
      is_platform_admin: true,
      platform_context_required: true,
      company_id: null,
    })).toBe(true);

    expect(isUnscopedPlatformAdministrator({
      is_platform_admin: true,
      platform_context_required: true,
      selectedCompanyId: "company-1",
    })).toBe(false);

    expect(isUnscopedPlatformAdministrator({
      is_super_admin: true,
      platform_context_required: true,
    })).toBe(false);
  });

  test("allows the explicit platform owner into Back Office only", () => {
    const user = { is_platform_admin: true };

    expect(canAccessWorkspace(user, "back-office")).toBe(true);
    expect(canAccessWorkspace(user, "front-office")).toBe(false);
  });
});

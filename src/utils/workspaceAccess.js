const normaliseIdentity = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, "_");

const asBoolean = (value) =>
  value === true ||
  value === 1 ||
  value === "1" ||
  String(value || "").trim().toLowerCase() === "true" ||
  String(value || "").trim().toLowerCase() === "yes";

export const isPlatformAdministrator = (user) => {
  if (!user) return false;

  return [user.is_platform_admin, user.platform_admin].some(asBoolean);
};

export const isUnscopedPlatformAdministrator = (user) => {
  if (!isPlatformAdministrator(user)) return false;

  const platformContextRequired = [
    user?.platform_context_required,
    user?.platformContextRequired,
  ].some(asBoolean);

  if (!platformContextRequired) return false;

  const companyId =
    user?.selectedCompanyId ||
    user?.selectedCompany?.id ||
    user?.company_id ||
    user?.company?.id ||
    "";

  return !String(companyId || "").trim();
};

export const mergePlatformAdministratorIdentity = (user = {}, response = {}) => {
  const responseOwnsCanonicalFlag = Object.prototype.hasOwnProperty.call(
    response || {},
    "is_platform_admin",
  );
  const responseOwnsAliasFlag = Object.prototype.hasOwnProperty.call(
    response || {},
    "platform_admin",
  );
  const platformFlag = responseOwnsCanonicalFlag
    ? response.is_platform_admin
    : responseOwnsAliasFlag
      ? response.platform_admin
      : user?.is_platform_admin ?? user?.platform_admin ?? false;
  const isPlatformAdmin = asBoolean(platformFlag);
  const platformContextRequired = isPlatformAdmin && asBoolean(
    response?.platform_context_required ??
      response?.platformContextRequired ??
      user?.platform_context_required ??
      user?.platformContextRequired,
  );

  return {
    ...user,
    is_platform_admin: isPlatformAdmin,
    platform_admin: isPlatformAdmin,
    platform_context_required: platformContextRequired,
    platform_scope: platformContextRequired
      ? String(response?.platform_scope || user?.platform_scope || "company-management")
      : null,
  };
};

export const isSuperAdministrator = (user) => {
  if (!user) return false;

  if (
    [
      user.is_super_admin,
      user.isSuperAdmin,
    ].some(asBoolean)
  ) {
    return true;
  }

  const identities = [
    typeof user.role === "string" ? user.role : user?.role?.name,
    user?.role?.slug,
    user?.role?.code,
    user?.role_name,
    user?.user_type,
    user?.type,
  ].map(normaliseIdentity);

  return identities.some((identity) =>
    ["super_admin", "super_administrator"].includes(identity)
  );
};

export const canAccessWorkspace = (user, workspace) => {
  if (!user) return false;
  if (workspace === "back-office" && isPlatformAdministrator(user)) return true;
  if (isSuperAdministrator(user)) return true;

  return workspace === "front-office"
    ? asBoolean(user.can_access_front_office ?? user?.workspace_access?.front_office)
    : asBoolean(user.can_access_back_office ?? user?.workspace_access?.back_office);
};

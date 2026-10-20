import { publicAssetUrl } from "./publicAssetUrl";

export const PLATFORM_FALLBACK_LOGO = "/servenest-static/servenest-logo.svg";

const firstPresent = (...values) => values.find(
  (value) => value !== undefined && value !== null && value !== "",
);

const asBoolean = (value, fallback = true) => {
  if (value === undefined || value === null || value === "") return fallback;
  return value === true
    || value === 1
    || value === "1"
    || String(value).trim().toLowerCase() === "true";
};

const entityAliases = {
  country: ["country"],
  company: ["company", "tenant"],
  brand: ["brand"],
  complex: ["complex", "site", "location"],
  shop: ["shop", "branch", "store", "outlet"],
  till: ["till", "terminal"],
};

const findEntity = (source, type) => {
  const hierarchy = source?.hierarchy
    || source?.configuration?.hierarchy
    || source?.tenant_hierarchy
    || {};
  const identity = source?.identity || source?.workspace_context || {};
  const aliases = entityAliases[type] || [type];

  for (const alias of aliases) {
    const value = firstPresent(
      hierarchy?.[alias],
      identity?.[alias],
      source?.[alias],
    );
    if (value && typeof value === "object") return value;
  }

  return {};
};

export const normalizeHierarchyEntity = (type, source = {}, root = {}) => {
  const entity = source && typeof source === "object" ? source : {};
  const aliases = entityAliases[type] || [type];
  const id = firstPresent(
    entity.id,
    ...aliases.map((alias) => entity[`${alias}_id`]),
    ...aliases.map((alias) => root[`${alias}_id`]),
  );
  const code = firstPresent(
    entity.code,
    ...aliases.map((alias) => entity[`${alias}_code`]),
    ...aliases.map((alias) => root[`${alias}_code`]),
  );
  const name = firstPresent(
    entity.name,
    ...aliases.map((alias) => entity[`${alias}_name`]),
    ...aliases.map((alias) => root[`${alias}_name`]),
  );
  const logo = firstPresent(
    entity.logo_path,
    entity.logo_url,
    entity.logo,
    entity.image_path,
    entity.image_url,
    entity.image,
    ...aliases.flatMap((alias) => [
      root[`${alias}_logo_url`],
      root[`${alias}_logo`],
      root[`${alias}_image_url`],
      root[`${alias}_image`],
    ]),
  );

  if (!id && !code && !name && !logo) return null;

  return {
    ...entity,
    id: id ?? null,
    code: code || "",
    name: name || "",
    logo: publicAssetUrl(logo),
    image: publicAssetUrl(firstPresent(entity.image_path, entity.image_url, entity.image, logo)),
  };
};

export const normalizeLoginMode = (value) => {
  const normalized = String(value || "")
    .trim()
    .toLowerCase()
    .replaceAll("-", "_")
    .replaceAll(" ", "_");

  if (["both", "either", "code_or_password", "code_and_password"].includes(normalized)) {
    return "both";
  }

  return ["code", "pin", "pin_code", "login_code", "code_branch"].includes(normalized)
    ? "code"
    : "password";
};

const menuItemsFrom = (menu = {}) => {
  if (Array.isArray(menu)) return menu;
  return menu.items || menu.menu_items || menu.products || menu.data || [];
};

const menuCategoriesFrom = (menu = {}) => (
  Array.isArray(menu?.categories) ? menu.categories : []
);

const exclusionShopIds = (item) => {
  const exclusions = item?.shop_exclusions
    || item?.excluded_shops
    || item?.visibility?.excluded_shops
    || [];

  return new Set((Array.isArray(exclusions) ? exclusions : []).map((entry) => String(
    typeof entry === "object" ? entry.id ?? entry.shop_id : entry,
  )));
};

export const menuItemIsVisibleAtShop = (item, shopId) => {
  if (!item || item.deleted_at) return false;
  if (!asBoolean(item.is_active, true)) return false;
  if (!asBoolean(item.is_visible, true)) return false;
  if (!asBoolean(item.visible_on_pos, true)) return false;

  const scopedShopId = String(shopId || "");
  if (!scopedShopId) return true;

  const explicitShopId = firstPresent(item.shop_id, item.branch_id);
  if (explicitShopId && String(explicitShopId) !== scopedShopId) return false;
  return !exclusionShopIds(item).has(scopedShopId);
};

export const normalizeHospitalityBootstrap = (source = {}) => {
  const payload = source?.data?.data ?? source?.data ?? source ?? {};
  const configuration = payload.configuration || {};
  const hierarchy = {};

  Object.keys(entityAliases).forEach((type) => {
    hierarchy[type] = normalizeHierarchyEntity(type, findEntity(payload, type), payload);
  });

  // Branch remains a compatibility alias while the API and historical reports
  // migrate to the hospitality term "shop".
  if (!hierarchy.shop) {
    hierarchy.shop = normalizeHierarchyEntity("shop", payload?.branch || {}, payload);
  }

  const shopId = hierarchy.shop?.id;
  const menuAuthoritative = payload.menu !== undefined
    || payload.menu_configuration !== undefined
    || configuration.menu !== undefined
    || configuration.menu_configuration !== undefined;
  const rawMenu = payload.menu
    || payload.menu_configuration
    || configuration.menu
    || configuration.menu_configuration
    || {};
  const menuItems = menuItemsFrom(rawMenu).filter((item) => menuItemIsVisibleAtShop(item, shopId));
  const visibleCategoryIds = new Set(menuItems.map((item) => String(
    item.category_id || item.category?.id || "",
  )).filter(Boolean));
  const rawCategories = menuCategoriesFrom(rawMenu);
  const categories = rawCategories.filter((category) => (
    category?.is_active !== false
    && (!menuAuthoritative || visibleCategoryIds.has(String(category.id)))
  ));
  const authenticationSource = payload.authentication
    || configuration.authentication
    || payload.auth
    || configuration.auth
    || {};
  const brandingSource = payload.branding || configuration.branding || {};
  const tenantLogo = publicAssetUrl(firstPresent(
    hierarchy.brand?.logo,
    brandingSource.logo_path,
    brandingSource.tenant_logo,
    brandingSource.logo_url,
    brandingSource.logo,
    hierarchy.company?.logo,
    hierarchy.country?.logo,
  ));

  return {
    ...payload,
    deployment_mode: payload.deployment_mode
      || configuration.deployment_mode
      || payload.mode
      || configuration.mode
      || "",
    configuration_version: firstPresent(
      payload.configuration_version,
      configuration.configuration_version,
      payload.config_version,
      configuration.config_version,
      payload.version,
      configuration.version,
    ) ?? null,
    hierarchy,
    country: hierarchy.country,
    company: hierarchy.company,
    brand: hierarchy.brand,
    complex: hierarchy.complex,
    shop: hierarchy.shop,
    branch: hierarchy.shop,
    till: hierarchy.till,
    operation: {
      ...(configuration.operation || {}),
      ...(payload.operation || {}),
      business_mode: "hospitality",
    },
    authentication: {
      ...authenticationSource,
      pos_login_mode: normalizeLoginMode(
        authenticationSource.pos_login_mode
          ?? authenticationSource.login_mode
          ?? payload.pos_login_mode,
      ),
    },
    branding: {
      ...brandingSource,
      logo: tenantLogo || PLATFORM_FALLBACK_LOGO,
      tenant_logo: tenantLogo || "",
      platform_fallback_logo: PLATFORM_FALLBACK_LOGO,
    },
    menu: {
      ...rawMenu,
      authoritative: menuAuthoritative,
      items: menuItems,
      categories,
      shop_id: shopId || null,
    },
  };
};

export const mergeWorkspaceBootstrap = (workspacePayload = {}, configurationPayload = null) => {
  const workspace = normalizeHospitalityBootstrap(workspacePayload);
  if (!configurationPayload) return workspace;

  const configuration = normalizeHospitalityBootstrap(configurationPayload);
  const hierarchy = {};
  Object.keys(entityAliases).forEach((type) => {
    hierarchy[type] = configuration.hierarchy?.[type] || workspace.hierarchy?.[type] || null;
  });

  return normalizeHospitalityBootstrap({
    ...workspace,
    ...configuration,
    hierarchy,
    user: workspacePayload?.user || workspace.user || configuration.user || null,
    capabilities: {
      ...(workspace.capabilities || {}),
      ...(configuration.capabilities || {}),
    },
    devices: {
      ...(workspace.devices || {}),
      ...(configuration.devices || {}),
    },
    operation: {
      ...(workspace.operation || {}),
      ...(configuration.operation || {}),
      business_mode: "hospitality",
    },
  });
};

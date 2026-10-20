// src/backOffice/components/GeneralSettings.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import { getAuthorisedCompany } from "../../../services/AuthenticationService";

import {
  getBranchSettings,
  updateBranchSetting,
  resetBranchSetting,
  syncBranchSettings,
} from "../../../services/GeneralSettingsService";

/* ============================================================
   UTILITIES
============================================================ */

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.message) return responseData.message;
  if (responseData?.error) return responseData.error;

  if (source?.errors && typeof source.errors === "object") {
    return Object.values(source.errors).flat().join("\n");
  }

  if (source?.message && !source?.isAxiosError) return source.message;
  if (source?.data?.message) return source.data.message;
  if (source?.data?.error) return source.data.error;

  return fallback;
};

const getBranchName = (branch) => {
  return branch?.shop_name || branch?.branch_name || branch?.name || "Unnamed Shop";
};

const getBranchCode = (branch) => {
  return branch?.branch_code || branch?.code || "";
};

const formatSettingLabel = (key) => {
  return String(key || "")
    .replaceAll("_", " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
};

// Readable names for the settings menu; any other module falls back to its key.
const SECTION_LABELS = {
  cashup: "Cash-up",
  discounts: "Discounts",
  hospitality: "Hospitality",
  inventory: "Inventory",
  product: "Products",
  receipt: "Receipts",
  sales: "Sales",
  shifts: "Shifts",
};

const sectionLabel = (section) => SECTION_LABELS[section] || formatSettingLabel(section);

const normalizeBoolean = (value) => {
  return value === true || value === "true" || value === 1 || value === "1";
};

const getSettingType = (setting) => {
  return setting?.setting_type || setting?.type || "string";
};

const getSettingModule = (setting) => {
  const raw = String(setting?.module || "general").trim().toLowerCase();
  const aliases = {
    restaurant: "hospitality",
    restaurant_pos: "hospitality",
    fast_food: "hospitality",
    fast_food_boh: "hospitality",
    food_industry: "hospitality",
    boh: "hospitality",
  };
  return aliases[raw] || raw || "general";
};

const normalizeBranchesResponse = (response) => {
  const company = response?.company || response?.data?.company || null;

  return (
    response?.company?.branches ||
    response?.data?.branches ||
    response?.data?.company?.branches ||
    company?.branches ||
    []
  );
};

const normalizeSettingsResponse = (response) => {
  const seen = new Set();
  const findSettings = (value) => {
    if (!value || typeof value !== "object" || seen.has(value)) return [];
    seen.add(value);
    if (Array.isArray(value) && value.some((item) => item?.setting_key || item?.key)) return value;
    const preferred = [value.settings, value.data, value.result, value.payload];
    for (const nested of preferred) {
      const found = findSettings(nested);
      if (found.length) return found;
    }
    return [];
  };

  return findSettings(response);
};

const getSettingCurrentValue = (setting) => {
  if (setting?.setting_value !== undefined && setting?.setting_value !== null) {
    return setting.setting_value;
  }

  if (setting?.branch_value !== undefined && setting?.branch_value !== null) {
    return setting.branch_value;
  }

  if (
    setting?.company_default_value !== undefined &&
    setting?.company_default_value !== null
  ) {
    return setting.company_default_value;
  }

  return "";
};

const getBooleanValue = (setting) => {
  const type = getSettingType(setting);

  if (type === "boolean") {
    return normalizeBoolean(getSettingCurrentValue(setting));
  }

  return normalizeBoolean(setting?.is_active);
};

const splitIntoRows = (items, rowSize = 4) => {
  const rows = [];

  for (let index = 0; index < items.length; index += rowSize) {
    rows.push(items.slice(index, index + rowSize));
  }

  return rows;
};

/* ============================================================
   MAIN COMPONENT
============================================================ */

const GeneralSettings = ({ open, onClose }) => {
  const [branches, setBranches] = useState([]);
  const [settings, setSettings] = useState([]);
  const [selectedBranchId, setSelectedBranchId] = useState("");

  const [branchSearch, setBranchSearch] = useState("");
  const [settingSearch, setSettingSearch] = useState("");
  const [selectedMainTab, setSelectedMainTab] = useState("all");
  const [selectedSubTab, setSelectedSubTab] = useState("all");

  const [loadingBranches, setLoadingBranches] = useState(false);
  const [loadingSettings, setLoadingSettings] = useState(false);
  const [savingKey, setSavingKey] = useState(null);
  const [resettingKey, setResettingKey] = useState(null);
  const [syncing, setSyncing] = useState(false);

  const [dirtySettings, setDirtySettings] = useState({});

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  /* ============================================================
     COMPUTED
  ============================================================ */

  const selectedBranch = useMemo(() => {
    return branches.find(
      (branch) => String(branch.id) === String(selectedBranchId)
    );
  }, [branches, selectedBranchId]);

  const filteredBranches = useMemo(() => {
    const q = branchSearch.toLowerCase().trim();

    if (!q) return branches;

    return branches.filter((branch) => {
      const name = getBranchName(branch).toLowerCase();
      const code = getBranchCode(branch).toLowerCase();

      return name.includes(q) || code.includes(q);
    });
  }, [branches, branchSearch]);

  const modules = useMemo(() => {
    return Array.from(
      new Set(settings.map((setting) => getSettingModule(setting)))
    ).sort();
  }, [settings]);

  const mainTabs = useMemo(() => {
    return ["all", ...modules];
  }, [modules]);

  const subTabs = useMemo(() => {
    return ["all"];
  }, []);

  const visibleSettings = useMemo(() => {
    const q = settingSearch.toLowerCase().trim();

    return settings.filter((setting) => {
      const module = getSettingModule(setting);

      const matchesMain =
        selectedMainTab === "all" || module === selectedMainTab;

      const matchesSub =
        selectedSubTab === "all" || module === selectedSubTab;

      const matchesSearch =
        !q ||
        String(setting.setting_key || "").toLowerCase().includes(q) ||
        String(setting.description || "").toLowerCase().includes(q) ||
        formatSettingLabel(setting.setting_key).toLowerCase().includes(q) ||
        module.toLowerCase().includes(q);

      return matchesMain && matchesSub && matchesSearch;
    });
  }, [settings, selectedMainTab, selectedSubTab, settingSearch]);

  const booleanSettings = useMemo(() => {
    return visibleSettings.filter(
      (setting) => getSettingType(setting) === "boolean"
    );
  }, [visibleSettings]);

  const valueSettings = useMemo(() => {
    return visibleSettings.filter(
      (setting) => getSettingType(setting) !== "boolean"
    );
  }, [visibleSettings]);

  const valueSettingRows = useMemo(() => {
    return splitIntoRows(valueSettings, 2);
  }, [valueSettings]);

  const booleanSettingRows = useMemo(() => {
    return splitIntoRows(booleanSettings, 2);
  }, [booleanSettings]);

  const dirtyCount = Object.keys(dirtySettings).length;

  const activeCount = useMemo(() => {
    return settings.filter((setting) => normalizeBoolean(setting.is_active))
      .length;
  }, [settings]);

  const overrideCount = useMemo(() => {
    return settings.filter((setting) => setting.has_branch_override).length;
  }, [settings]);

  /* ============================================================
     MESSAGE HELPERS
  ============================================================ */

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({
      open: true,
      type,
      title,
      message,
    });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({
      ...prev,
      open: false,
    }));
  };

  /* ============================================================
     LOCAL MUTATION HELPERS
  ============================================================ */

  const updateSettingLocally = (settingKey, updates) => {
    setSettings((prev) =>
      prev.map((setting) =>
        setting.setting_key === settingKey
          ? {
              ...setting,
              ...updates,
            }
          : setting
      )
    );
  };

  const markDirty = (settingKey, updates) => {
    setDirtySettings((prev) => ({
      ...prev,
      [settingKey]: {
        ...(prev[settingKey] || {}),
        ...updates,
      },
    }));
  };

  const clearDirty = (settingKey) => {
    setDirtySettings((prev) => {
      const next = { ...prev };
      delete next[settingKey];
      return next;
    });
  };

  const buildSettingPayload = (setting) => {
    const dirty = dirtySettings[setting.setting_key] || {};

    return {
      setting_value:
        dirty.setting_value !== undefined
          ? dirty.setting_value
          : getSettingCurrentValue(setting),
      is_active:
        dirty.is_active !== undefined ? dirty.is_active : setting.is_active,
    };
  };

  /* ============================================================
     API LOADING
  ============================================================ */

  const loadBranches = async () => {
    try {
      setLoadingBranches(true);

      const response = await getAuthorisedCompany();
      const apiBranches = normalizeBranchesResponse(response);

      setBranches(apiBranches);

      if (apiBranches.length > 0 && !selectedBranchId) {
        setSelectedBranchId(apiBranches[0].id);
      }
    } catch (error) {
      showMessage({
        type: "error",
        title: "Shops Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingBranches(false);
    }
  };

  const loadSettings = async (branchId) => {
    if (!branchId) {
      setSettings([]);
      setDirtySettings({});
      return;
    }

    try {
      setLoadingSettings(true);

      const response = await getBranchSettings(branchId);
      const apiSettings = normalizeSettingsResponse(response);

      setSettings(apiSettings);
      setDirtySettings({});
      setSelectedMainTab("all");
      setSelectedSubTab("all");
      setSettingSearch("");
    } catch (error) {
      showMessage({
        type: "error",
        title: "Settings Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingSettings(false);
    }
  };

  /* ============================================================
     EFFECTS
  ============================================================ */

  useEffect(() => {
    if (open) {
      loadBranches();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (open && selectedBranchId) {
      loadSettings(selectedBranchId);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, selectedBranchId]);

  /* ============================================================
     ACTIONS
  ============================================================ */

  const handleBranchChange = async (branchId) => {
    if (String(branchId) === String(selectedBranchId)) return;

    if (dirtyCount > 0) {
      const ok = await requestEnterpriseConfirmation({
        title: "Discard Changes",
        message: "You have unsaved changes. Switching shops will discard those local changes. Continue?",
        confirmLabel: "Switch shop",
      });

      if (!ok) return;
    }

    setSelectedBranchId(branchId);
  };

  const handleMainTabChange = (tab) => {
    setSelectedMainTab(tab);
    setSelectedSubTab("all");
  };

  const handleSettingValueChange = (setting, value) => {
    updateSettingLocally(setting.setting_key, {
      setting_value: value,
    });

    markDirty(setting.setting_key, {
      setting_value: value,
    });
  };

  const handleSettingActiveChange = (setting, checked) => {
    const type = getSettingType(setting);

    if (type === "boolean") {
      const nextValue = checked ? "true" : "false";

      updateSettingLocally(setting.setting_key, {
        setting_value: nextValue,
        is_active: true,
        has_branch_override: true,
      });

      markDirty(setting.setting_key, {
        setting_value: nextValue,
        is_active: true,
      });

      return;
    }

    updateSettingLocally(setting.setting_key, {
      is_active: checked,
      branch_is_active: checked,
      has_branch_override: true,
    });

    markDirty(setting.setting_key, {
      is_active: checked,
    });
  };

  const handleSaveSetting = async (setting) => {
    if (!selectedBranchId || savingKey || syncing) return;

    const previousSetting = { ...setting };

    try {
      setSavingKey(setting.setting_key);

      const payload = buildSettingPayload(setting);

      const response = await updateBranchSetting(
        selectedBranchId,
        setting.setting_key,
        payload
      );

      clearDirty(setting.setting_key);

      updateSettingLocally(setting.setting_key, {
        setting_value: payload.setting_value,
        branch_value: payload.setting_value,
        is_active: payload.is_active,
        branch_is_active: payload.is_active,
        has_branch_override: true,
      });

      window.dispatchEvent(new CustomEvent("salexa_workspace_changed", {
        detail: { branchId: selectedBranchId },
      }));

      showMessage({
        type: "success",
        title: "Setting Saved",
        message: getApiMessage(response, "Setting saved successfully."),
      });
    } catch (error) {
      updateSettingLocally(setting.setting_key, previousSetting);

      showMessage({
        type: "error",
        title: "Save Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingKey(null);
    }
  };

  const handleResetSetting = async (setting) => {
    if (!selectedBranchId || resettingKey || syncing) return;

    const ok = await requestEnterpriseConfirmation({
      title: "Reset Shop Setting",
      message: "Reset this shop setting to company default?",
      confirmLabel: "Reset setting",
    });
    if (!ok) return;

    try {
      setResettingKey(setting.setting_key);

      const response = await resetBranchSetting(
        selectedBranchId,
        setting.setting_key
      );

      clearDirty(setting.setting_key);

      updateSettingLocally(setting.setting_key, {
        branch_setting_id: null,
        branch_value: null,
        branch_is_active: null,
        setting_value: setting.company_default_value,
        is_active: setting.company_is_active,
        has_branch_override: false,
      });

      window.dispatchEvent(new CustomEvent("salexa_workspace_changed", {
        detail: { branchId: selectedBranchId },
      }));

      showMessage({
        type: "success",
        title: "Setting Reset",
        message: getApiMessage(response, "Setting reset successfully."),
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Reset Failed",
        message: getApiMessage(error),
      });
    } finally {
      setResettingKey(null);
    }
  };

  const handleSyncDirtySettings = async ({ closeAfterSave = false } = {}) => {
    if (!selectedBranchId || syncing) return;

    const dirtyKeys = Object.keys(dirtySettings);

    if (dirtyKeys.length === 0) {
      if (closeAfterSave) {
        onClose?.();
        return;
      }

      showMessage({
        type: "success",
        title: "Nothing To Save",
        message: "There are no unsaved setting changes.",
      });

      return;
    }

    const payloadSettings = dirtyKeys
      .map((settingKey) => {
        const setting = settings.find((item) => item.setting_key === settingKey);

        if (!setting) return null;

        const dirty = dirtySettings[settingKey];

        return {
          setting_key: setting.setting_key,
          setting_value:
            dirty.setting_value !== undefined
              ? dirty.setting_value
              : getSettingCurrentValue(setting),
          is_active:
            dirty.is_active !== undefined ? dirty.is_active : setting.is_active,
        };
      })
      .filter(Boolean);

    try {
      setSyncing(true);

      const response = await syncBranchSettings(
        selectedBranchId,
        payloadSettings
      );

      setDirtySettings({});

      setSettings((prev) =>
        prev.map((setting) => {
          const updated = payloadSettings.find(
            (item) => item.setting_key === setting.setting_key
          );

          if (!updated) return setting;

          return {
            ...setting,
            setting_value: updated.setting_value,
            branch_value: updated.setting_value,
            is_active: updated.is_active,
            branch_is_active: updated.is_active,
            has_branch_override: true,
          };
        })
      );

      window.dispatchEvent(new CustomEvent("salexa_workspace_changed", {
        detail: { branchId: selectedBranchId },
      }));

      showMessage({
        type: "success",
        title: "Settings Saved",
        message: getApiMessage(response, "Settings saved successfully."),
      });

      if (closeAfterSave) {
        onClose?.();
      }
    } catch (error) {
      showMessage({
        type: "error",
        title: "Sync Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSyncing(false);
    }
  };

  const handleCancel = async () => {
    if (dirtyCount > 0) {
      const ok = await requestEnterpriseConfirmation({
        title: "Discard Changes",
        message: "You have unsaved changes. Close settings without saving?",
        confirmLabel: "Close settings",
      });

      if (!ok) return;
    }

    onClose?.();
  };

  /* ============================================================
     RENDER HELPERS
  ============================================================ */

  const renderValueInput = (setting) => {
    const type = getSettingType(setting);
    const disabled =
      savingKey === setting.setting_key ||
      resettingKey === setting.setting_key ||
      syncing;

    const value = getSettingCurrentValue(setting);

    if (setting.setting_key === "pos_login_mode") {
      const normalizedLoginMode = String(value || "password").toLowerCase();
      return (
        <select
          disabled={disabled}
          value={normalizedLoginMode === "code" || normalizedLoginMode === "both"
            ? normalizedLoginMode
            : "username_password"}
          onChange={(e) => handleSettingValueChange(setting, e.target.value)}
          className="modern-settings-input"
          aria-label="POS login mode"
        >
          <option value="code">4-digit operator code</option>
          <option value="username_password">Username and password</option>
          <option value="both">Allow either method</option>
        </select>
      );
    }

    if (type === "integer" || type === "decimal" || type === "float") {
      return (
        <input
          type="number"
          disabled={disabled}
          value={value ?? ""}
          onChange={(e) => handleSettingValueChange(setting, e.target.value)}
          className="modern-settings-input"
        />
      );
    }

    if (type === "json") {
      return (
        <textarea
          disabled={disabled}
          rows={2}
          value={value ?? ""}
          onChange={(e) => handleSettingValueChange(setting, e.target.value)}
          className="modern-settings-textarea"
        />
      );
    }

    return (
      <input
        type="text"
        disabled={disabled}
        value={value ?? ""}
        onChange={(e) => handleSettingValueChange(setting, e.target.value)}
        className="modern-settings-input"
      />
    );
  };

  const renderValueSetting = (setting) => {
    const isDirty = !!dirtySettings[setting.setting_key];
    const isSaving = savingKey === setting.setting_key;
    const isResetting = resettingKey === setting.setting_key;

    return (
      <div key={setting.setting_key} className="modern-setting-row">
        <div className="modern-setting-label">
          <span>{formatSettingLabel(setting.setting_key)}</span>
          <small>{setting.description || setting.setting_key}</small>
        </div>

        <div className="modern-setting-control">
          {renderValueInput(setting)}
        </div>

        <div className="modern-setting-actions">
          <span
            className={`modern-source-badge ${
              setting.has_branch_override ? "modern-source-branch" : ""
            }`}
          >
            {setting.has_branch_override ? "Shop" : "Company"}
          </span>

          {isDirty && <span className="modern-dirty-badge">Unsaved</span>}

          <button
            type="button"
            disabled={!isDirty || isSaving || syncing}
            onClick={() => handleSaveSetting(setting)}
            className="modern-mini-btn modern-mini-primary"
          >
            {isSaving ? "Saving" : "Save"}
          </button>

          <button
            type="button"
            disabled={!setting.has_branch_override || isResetting || syncing}
            onClick={() => handleResetSetting(setting)}
            className="modern-mini-btn"
          >
            {isResetting ? "Resetting" : "Reset"}
          </button>
        </div>
      </div>
    );
  };

  const renderBooleanSetting = (setting) => {
    const isDirty = !!dirtySettings[setting.setting_key];

    return (
      <label
        key={setting.setting_key}
        className={`modern-check-row ${isDirty ? "modern-check-row-dirty" : ""}`}
      >
        <input
          type="checkbox"
          checked={getBooleanValue(setting)}
          disabled={savingKey === setting.setting_key || syncing}
          onChange={(e) => handleSettingActiveChange(setting, e.target.checked)}
        />

        <span>
          <strong>{formatSettingLabel(setting.setting_key)}</strong>
          <small>{setting.description || setting.setting_key}</small>
        </span>

        {isDirty && <em>Unsaved</em>}
      </label>
    );
  };

  /* ============================================================
     JSX
  ============================================================ */

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={handleCancel}
        title="GENERAL SETTINGS"
        subtitle="Company defaults, with per-shop overrides"
      >
        <div className="modern-settings-shell">
          <div className="slp-settings-window">
            {/* LEFT NAVIGATION - uses your real setting sections, not the reference-image labels */}
            <aside className="slp-left-nav" aria-label="Settings sections">
              {mainTabs.map((tab) => (
                <button
                  key={tab}
                  type="button"
                  onClick={() => handleMainTabChange(tab)}
                  className={`slp-nav-item ${selectedMainTab === tab ? "active" : ""}`}
                >
                  {tab === "all" ? "All settings" : sectionLabel(tab)}
                </button>
              ))}

              <button
                type="button"
                className="slp-nav-item"
                onClick={() =>
                  showMessage({
                    type: "success",
                    title: "Settings Help",
                    message:
                      "Select a shop, choose a setting section, edit settings, then Apply or Save and Exit.",
                  })
                }
              >
                Help
              </button>

              <div className="slp-nav-spacer" />

              <div className="slp-branch-box">
                <label>Shop</label>
                <select
                  value={selectedBranchId}
                  onChange={(e) => handleBranchChange(e.target.value)}
                  disabled={loadingBranches || branches.length === 0}
                >
                  {branches.length === 0 ? (
                    <option value="">No branches available</option>
                  ) : (
                    branches.map((branch) => (
                      <option key={branch.id} value={branch.id}>
                        {getBranchName(branch)}
                        {getBranchCode(branch) ? ` - ${getBranchCode(branch)}` : ""}
                      </option>
                    ))
                  )}
                </select>
              </div>
            </aside>

            {/* RIGHT CONTENT */}
            <main className="slp-main-panel">
              <div className="slp-top-tools">
                <input
                  type="text"
                  value={branchSearch}
                  onChange={(e) => setBranchSearch(e.target.value)}
                  placeholder="Find shop..."
                />

                {branchSearch && (
                  <select
                    value={selectedBranchId}
                    onChange={(e) => handleBranchChange(e.target.value)}
                    disabled={filteredBranches.length === 0}
                  >
                    {filteredBranches.length === 0 ? (
                      <option value="">No match</option>
                    ) : (
                      filteredBranches.map((branch) => (
                        <option key={branch.id} value={branch.id}>
                          {getBranchName(branch)}
                        </option>
                      ))
                    )}
                  </select>
                )}

                <input
                  type="text"
                  value={settingSearch}
                  onChange={(e) => setSettingSearch(e.target.value)}
                  placeholder="Search setting..."
                />

                <button
                  type="button"
                  onClick={loadBranches}
                  disabled={loadingBranches}
                >
                  {loadingBranches ? "Loading..." : "Reload Shops"}
                </button>

                <button
                  type="button"
                  onClick={() => loadSettings(selectedBranchId)}
                  disabled={!selectedBranchId || loadingSettings}
                >
                  {loadingSettings ? "Refreshing..." : "Refresh"}
                </button>
              </div>

              <section className="slp-settings-box">
                <div className="slp-tabs-row">
                  {subTabs.map((tab) => (
                    <button
                      key={tab}
                      type="button"
                      onClick={() => setSelectedSubTab(tab)}
                      className={selectedSubTab === tab ? "active" : ""}
                    >
                      {tab === "all" ? "All" : sectionLabel(tab)}
                    </button>
                  ))}
                </div>

                <fieldset className="slp-fieldset">
                  <legend>
                    {selectedSubTab !== "all"
                      ? sectionLabel(selectedSubTab)
                      : selectedMainTab !== "all"
                      ? sectionLabel(selectedMainTab)
                      : "Settings"}
                  </legend>

                  {!selectedBranchId ? (
                    <EmptyState message="Select a shop to manage settings." />
                  ) : loadingSettings ? (
                    <EmptyState message="Loading shop settings..." />
                  ) : visibleSettings.length === 0 ? (
                    <EmptyState message="No settings found." />
                  ) : (
                    <div className="slp-settings-scroll">
                      {booleanSettings.length > 0 && (
                        <div className="slp-checkbox-layout">
                          {booleanSettings.map((setting) => renderBooleanSetting(setting))}
                        </div>
                      )}

                      {valueSettings.length > 0 && (
                        <div className="slp-value-panel">
                          <div className="slp-value-title">Value Settings</div>
                          {valueSettings.map((setting) => renderValueSetting(setting))}
                        </div>
                      )}
                    </div>
                  )}
                </fieldset>
              </section>
            </main>

            {/* FOOTER */}
            <footer className="slp-footer">
              <button type="button" onClick={handleCancel}>
                Cancel
              </button>

              <div className="slp-footer-info">
                <strong>
                  {selectedBranch ? getBranchName(selectedBranch) : "No shop selected"}
                </strong>
                <span>
                  {dirtyCount > 0
                    ? `${dirtyCount} unsaved change${dirtyCount === 1 ? "" : "s"}`
                    : "No unsaved changes"}
                </span>
              </div>

              <div className="slp-footer-actions">
                <button
                  type="button"
                  onClick={() => handleSyncDirtySettings()}
                  disabled={!selectedBranchId || syncing || dirtyCount === 0}
                >
                  {syncing ? "Applying..." : "Apply"}
                </button>

                <button
                  type="button"
                  onClick={() =>
                    handleSyncDirtySettings({
                      closeAfterSave: true,
                    })
                  }
                  disabled={!selectedBranchId || syncing}
                  className="primary"
                >
                  {syncing ? "Saving..." : "Save and Exit"}
                </button>
              </div>
            </footer>
          </div>
        </div>
        <ModernGeneralSettingsStyles />
      </FullScreenModal>

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={closeMessageModal}
      />
    </>
  );
};

/* ============================================================
   SMALL UI
============================================================ */

const StatBox = ({ label, value, tone = "default" }) => (
  <div className={`modern-stat-box ${tone === "warning" ? "warning" : ""}`}>
    <span>{label}</span>
    <strong>{value}</strong>
  </div>
);

const EmptyState = ({ message }) => (
  <div className="modern-empty-state">{message}</div>
);

/* ============================================================
   STYLES
============================================================ */

const ModernGeneralSettingsStyles = () => (
  <style>
    {`
      .modern-settings-shell {
        height: calc(100vh - 86px);
        min-height: 0;
        overflow: hidden;
        background: #e9eeee;
        padding: 10px;
        color: #091b1b;
      }

      .slp-settings-window {
        height: 100%;
        min-height: 0;
        display: grid;
        grid-template-columns: 168px minmax(0, 1fr);
        grid-template-rows: minmax(0, 1fr) 58px;
        gap: 0;
        background: #ffffff;
        border: 1px solid #cbd6d6;
        overflow: hidden;
      }

      .slp-left-nav {
        grid-row: 1 / 2;
        background: #f8fafc;
        border-right: 1px solid #cbd6d6;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-height: 0;
      }

      .slp-nav-item {
        height: 40px;
        border: 1px solid #cbd6d6;
        background: #ffffff;
        color: #324444;
        font-size: 12px;
        font-weight: 400;
        text-align: center;
      }

      .slp-nav-item.active {
        border-color: #069495;
        color: #005a5a;
        background: #e6fffb;
      }

      .slp-nav-item.muted {
        color: #637877;
      }

      .slp-nav-spacer {
        flex: 1;
        min-height: 8px;
      }

      .slp-branch-box label {
        display: block;
        margin-bottom: 4px;
        font-size: 10px;
        color: #637877;
      }

      .slp-branch-box select,
      .slp-top-tools input,
      .slp-top-tools select {
        width: 100%;
        height: 30px;
        border: 1px solid #93a6a6;
        background: #ffffff;
        color: #091b1b;
        padding: 0 7px;
        font-size: 11px;
        outline: none;
      }

      .slp-main-panel {
        grid-row: 1 / 2;
        min-width: 0;
        min-height: 0;
        padding: 10px;
        display: grid;
        grid-template-rows: 34px minmax(0, 1fr);
        gap: 8px;
        background: #ffffff;
      }

      .slp-top-tools {
        display: grid;
        grid-template-columns: minmax(150px, 1fr) minmax(140px, 0.8fr) minmax(150px, 1fr) auto auto;
        gap: 6px;
        align-items: center;
        min-width: 0;
      }

      .slp-top-tools button,
      .slp-footer button,
      .modern-mini-btn {
        height: 30px;
        border: 1px solid #93a6a6;
        background: #ffffff;
        color: #091b1b;
        padding: 0 10px;
        font-size: 11px;
        font-weight: 400;
      }

      .slp-tabs-row {
        display: flex;
        align-items: flex-end;
        overflow-x: auto;
        border-bottom: 1px solid #cbd6d6;
      }

      .slp-tabs-row button,
      .slp-sub-tabs-row button {
        height: 28px;
        border: 1px solid #cbd6d6;
        border-bottom: 0;
        background: #f8fafc;
        color: #324444;
        padding: 0 10px;
        margin-right: 2px;
        font-size: 11px;
        white-space: nowrap;
      }

      .slp-tabs-row button.active,
      .slp-sub-tabs-row button.active {
        background: #ffffff;
        color: #005a5a;
        border-color: #069495;
      }

      .slp-settings-box {
        min-height: 0;
        border: 1px solid #cbd6d6;
        background: #ffffff;
        padding: 8px;
        display: grid;
        grid-template-rows: 28px minmax(0, 1fr);
      }

      .slp-sub-tabs-row {
        display: flex;
        align-items: flex-end;
        overflow-x: auto;
      }

      .slp-fieldset {
        min-height: 0;
        border: 1px solid #cbd6d6;
        margin: 0;
        padding: 12px 12px 10px;
        overflow: hidden;
      }

      .slp-fieldset legend {
        padding: 0 5px;
        color: #324444;
        font-size: 12px;
      }

      .slp-settings-scroll {
        height: 100%;
        min-height: 0;
        overflow: auto;
      }

      .slp-checkbox-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        column-gap: 34px;
        row-gap: 8px;
        align-items: start;
      }

      .modern-check-row {
        min-height: 22px;
        border: 0;
        background: transparent;
        padding: 0;
        display: grid;
        grid-template-columns: 18px minmax(0, 1fr) auto;
        gap: 6px;
        align-items: start;
      }

      .modern-check-row input {
        margin: 1px 0 0;
        width: 14px;
        height: 14px;
        accent-color: #069495;
      }

      .modern-check-row strong {
        display: block;
        font-size: 12px;
        line-height: 16px;
        font-weight: 500;
        color: #091b1b;
      }

      .modern-check-row small {
        display: none;
      }

      .modern-check-row em {
        font-style: normal;
        font-size: 9px;
        color: #c2410c;
        background: #ffedd5;
        border: 1px solid #fdba74;
        padding: 2px 5px;
      }

      .modern-check-row-dirty strong {
        color: #c2410c;
      }

      .slp-value-panel {
        margin-top: 14px;
        border-top: 1px solid #e2e9e9;
        padding-top: 10px;
      }

      .slp-value-title {
        margin-bottom: 8px;
        font-size: 12px;
        font-weight: 500;
        color: #324444;
      }

      .modern-setting-row {
        border: 1px solid #e2e9e9;
        background: #ffffff;
        padding: 8px;
        margin-bottom: 8px;
        min-width: 0;
        display: grid;
        grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) auto;
        gap: 8px;
        align-items: center;
      }

      .modern-setting-label span {
        display: block;
        font-size: 12px;
        font-weight: 500;
        color: #091b1b;
      }

      .modern-setting-label small {
        display: block;
        margin-top: 2px;
        font-size: 10px;
        color: #637877;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .modern-setting-control {
        margin: 0;
      }

      .modern-settings-input,
      .modern-settings-textarea {
        width: 100%;
        border: 1px solid #93a6a6;
        background: #ffffff;
        color: #091b1b;
        font-size: 12px;
        outline: none;
      }

      .modern-settings-input {
        height: 28px;
        padding: 0 8px;
      }

      .modern-settings-textarea {
        padding: 6px 8px;
        resize: vertical;
      }

      .modern-setting-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 5px;
      }

      .modern-source-badge,
      .modern-dirty-badge {
        height: 22px;
        border: 1px solid #cbd6d6;
        background: #f8fafc;
        color: #637877;
        padding: 4px 7px;
        font-size: 9px;
        text-transform: uppercase;
      }

      .modern-source-branch {
        background: #e6fffb;
        color: #005a5a;
        border-color: #93f5f4;
      }

      .modern-dirty-badge {
        background: #ffedd5;
        color: #c2410c;
        border-color: #fdba74;
      }

      .modern-mini-btn {
        height: 24px;
        padding: 0 8px;
        font-size: 10px;
      }

      .modern-mini-primary,
      .slp-footer-actions button.primary {
        background: #069495;
        border-color: #057b7c;
        color: #ffffff;
      }

      .modern-mini-primary:hover:not(:disabled),
      .slp-footer-actions button.primary:hover:not(:disabled) {
        background: #057b7c;
      }

      .modern-muted,
      .modern-empty-state {
        height: 100%;
        min-height: 220px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #637877;
        font-size: 12px;
      }

      .slp-footer {
        grid-column: 1 / -1;
        grid-row: 2 / 3;
        border-top: 1px solid #cbd6d6;
        background: #ffffff;
        padding: 10px;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
      }

      .slp-footer-info {
        min-width: 0;
        text-align: left;
      }

      .slp-footer-info strong {
        display: block;
        font-size: 12px;
        font-weight: 500;
        color: #091b1b;
      }

      .slp-footer-info span {
        display: block;
        margin-top: 2px;
        font-size: 10px;
        color: #637877;
      }

      .slp-footer-actions {
        display: flex;
        align-items: center;
        gap: 14px;
      }

      .slp-top-tools button:hover:not(:disabled),
      .slp-footer button:hover:not(:disabled),
      .modern-mini-btn:hover:not(:disabled) {
        background: #e2e9e9;
      }

      button,
      input,
      select,
      textarea,
      div,
      section,
      aside,
      main,
      footer,
      fieldset {
        border-radius: 0 !important;
      }

      button:disabled,
      input:disabled,
      select:disabled,
      textarea:disabled {
        opacity: 0.55;
        cursor: not-allowed;
      }

      @media (max-width: 1100px) {
        .slp-settings-window {
          grid-template-columns: 150px minmax(0, 1fr);
        }

        .slp-top-tools {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .slp-checkbox-layout {
          grid-template-columns: 1fr;
        }

        .modern-setting-row {
          grid-template-columns: 1fr;
          align-items: stretch;
        }

        .modern-setting-actions {
          justify-content: flex-start;
        }
      }

      @media (max-width: 760px) {
        .modern-settings-shell {
          padding: 6px;
        }

        .slp-settings-window {
          grid-template-columns: 1fr;
          grid-template-rows: auto minmax(0, 1fr) auto;
        }

        .slp-left-nav {
          grid-row: 1 / 2;
          border-right: 0;
          border-bottom: 1px solid #cbd6d6;
          flex-direction: row;
          overflow-x: auto;
        }

        .slp-nav-item {
          min-width: 128px;
        }

        .slp-nav-spacer,
        .slp-branch-box {
          display: none;
        }

        .slp-main-panel {
          grid-row: 2 / 3;
          grid-template-rows: auto minmax(0, 1fr);
        }

        .slp-top-tools {
          grid-template-columns: 1fr;
        }

        .slp-footer {
          grid-row: 3 / 4;
          grid-template-columns: 1fr;
          align-items: stretch;
        }

        .slp-footer-actions {
          justify-content: flex-start;
          overflow-x: auto;
        }
      }
    `}
  </style>
);

export default GeneralSettings;

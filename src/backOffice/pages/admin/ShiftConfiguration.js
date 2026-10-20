// src/backOffice/components/ShiftConfiguration.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";

import {
  getAuthorisedCompany,

} from "../../../services/AuthenticationService";

import {
  
  getShiftConfigurations,
  createShiftConfiguration,
  updateShiftConfiguration,
  deleteShiftConfiguration,
  getCurrentShiftConfiguration,
  detectCurrentShift,
} from "../../../services/GeneralSettingsService";

const emptyForm = {
  id: null,
  branch_id: "",

  enable_shifts: true,
  shift_mode: "manual",

  morning_shift_start: "",
  morning_shift_end: "",

  afternoon_shift_start: "",
  afternoon_shift_end: "",

  night_shift_start: "",
  night_shift_end: "",

  auto_shift_logout: false,
  auto_logout_after_minutes: "",

  require_opening_float: true,
  require_closing_cashup: true,
  allow_multiple_open_shifts: false,
  allow_unscheduled_shifts: true,
  early_clock_in_minutes: 15,
  shift_warning_before_end_minutes: 15,
  shift_grace_minutes_after_end: 10,
  allow_continue_unclosed_shift: true,
  require_cashup_before_new_shift: true,
  block_sales_after_shift_end: false,
  allow_supervisor_cashup: true,
  require_on_behalf_reason: true,

  status: "active",
};

const normalizeTimeValue = (value) => String(value || "").slice(0, 5);

const formatTime12 = (value) => {
  const normalized = normalizeTimeValue(value);
  if (!/^\d{2}:\d{2}$/.test(normalized)) return "Not set";

  const [hours, minutes] = normalized.split(":").map(Number);
  const period = hours >= 12 ? "PM" : "AM";
  const hour = hours % 12 || 12;
  return `${String(hour).padStart(2, "0")}:${String(minutes).padStart(2, "0")} ${period}`;
};

const ShiftTimeInput = ({ label, name, value, onChange }) => (
  <div>
    <label className="mb-1 block text-[9px] font-bold uppercase">{label}</label>
    <div className="grid grid-cols-[minmax(0,1fr)_92px]">
      <input
        type="time"
        step="60"
        name={name}
        value={normalizeTimeValue(value)}
        onChange={onChange}
        className="h-9 min-w-0 border border-r-0 border-[#CAD5D5] px-2 text-[11px] outline-none focus:border-[#00BCBC]"
      />
      <div className="flex h-9 items-center justify-center border border-[#8FB8B2] bg-[#E8F7F5] text-[10px] font-bold text-[#006C6C]">
        {formatTime12(value)}
      </div>
    </div>
  </div>
);

const ShiftConfiguration = ({ open, onClose }) => {
  const [branches, setBranches] = useState([]);
  const [configs, setConfigs] = useState([]);

  const [formData, setFormData] = useState(emptyForm);
  const [formOpen, setFormOpen] = useState(false);

  const [branchSearch, setBranchSearch] = useState("");
  const [configSearch, setConfigSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [detecting, setDetecting] = useState(false);

  const [currentShiftResult, setCurrentShiftResult] = useState(null);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;

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

  const getBranchName = (branch) => {
    return branch?.branch_name || branch?.name || "Company Default";
  };

  const getBranchCode = (branch) => {
    return branch?.branch_code || branch?.code || "";
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

  const normalizeConfigsResponse = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;

    return [];
  };

  const selectedBranch = branches.find(
    (branch) => String(branch.id) === String(formData.branch_id)
  );

  const filteredBranches = useMemo(() => {
    const q = branchSearch.toLowerCase();

    return branches.filter((branch) => {
      const name = getBranchName(branch).toLowerCase();
      const code = getBranchCode(branch).toLowerCase();

      return name.includes(q) || code.includes(q);
    });
  }, [branches, branchSearch]);

  const filteredConfigs = useMemo(() => {
    const q = configSearch.toLowerCase();

    return configs.filter((config) => {
      const branchName = config?.branch
        ? getBranchName(config.branch)
        : "company default";

      return (
        branchName.toLowerCase().includes(q) ||
        String(config.shift_mode || "").toLowerCase().includes(q) ||
        String(config.status || "").toLowerCase().includes(q)
      );
    });
  }, [configs, configSearch]);

  const loadBranches = async () => {
    const response = await getAuthorisedCompany();
    const apiBranches = normalizeBranchesResponse(response);

    setBranches(apiBranches);
  };

  const loadConfigs = async () => {
    const response = await getShiftConfigurations({ per_page: 100 });
    setConfigs(normalizeConfigsResponse(response));
  };

  const loadData = async () => {
    try {
      setLoading(true);

      await Promise.all([loadBranches(), loadConfigs()]);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      loadData();
    }
  }, [open]);

  const handleChange = (e) => {
    const { name, value, checked, type } = e.target;

    setFormData((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleClear = () => {
    setFormData(emptyForm);
    setCurrentShiftResult(null);
  };

  const buildPayload = () => {
    return {
      branch_id: formData.branch_id || null,

      enable_shifts: !!formData.enable_shifts,
      shift_mode: formData.shift_mode,

      morning_shift_start: formData.morning_shift_start || null,
      morning_shift_end: formData.morning_shift_end || null,

      afternoon_shift_start: formData.afternoon_shift_start || null,
      afternoon_shift_end: formData.afternoon_shift_end || null,

      night_shift_start: formData.night_shift_start || null,
      night_shift_end: formData.night_shift_end || null,

      auto_shift_logout: !!formData.auto_shift_logout,
      auto_logout_after_minutes: formData.auto_logout_after_minutes
        ? Number(formData.auto_logout_after_minutes)
        : null,

      require_opening_float: !!formData.require_opening_float,
      require_closing_cashup: !!formData.require_closing_cashup,
      allow_multiple_open_shifts: !!formData.allow_multiple_open_shifts,
      allow_unscheduled_shifts: !!formData.allow_unscheduled_shifts,
      early_clock_in_minutes: Number(formData.early_clock_in_minutes || 0),
      shift_warning_before_end_minutes: Number(formData.shift_warning_before_end_minutes || 0),
      shift_grace_minutes_after_end: Number(formData.shift_grace_minutes_after_end || 0),
      allow_continue_unclosed_shift: !!formData.allow_continue_unclosed_shift,
      require_cashup_before_new_shift: !!formData.require_cashup_before_new_shift,
      block_sales_after_shift_end: !!formData.block_sales_after_shift_end,
      allow_supervisor_cashup: !!formData.allow_supervisor_cashup,
      require_on_behalf_reason: !!formData.require_on_behalf_reason,

      status: formData.status,
    };
  };

  const validateForm = () => {
    if (!formData.shift_mode) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Shift mode is required.",
      });

      return false;
    }

    if (
      formData.auto_shift_logout &&
      (!formData.auto_logout_after_minutes ||
        Number(formData.auto_logout_after_minutes) < 1)
    ) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "The automatic sales-lock delay after shift end must be at least 1 minute.",
      });

      return false;
    }

    return true;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) return;

    try {
      setSaving(true);

      const payload = buildPayload();
      let response;

      if (isEditing) {
        response = await updateShiftConfiguration(formData.id, payload);

        showMessage({
          type: "success",
          title: "Configuration Updated",
          message: getApiMessage(response),
        });
      } else {
        response = await createShiftConfiguration(payload);

        showMessage({
          type: "success",
          title: "Configuration Created",
          message: getApiMessage(response),
        });
      }

      handleClear();
      setFormOpen(false);
      await loadConfigs();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
    }
  };

  const handleEdit = (config) => {
    setFormData({
      id: config.id,
      branch_id: config.branch_id || "",

      enable_shifts: config.enable_shifts ?? true,
      shift_mode: config.shift_mode || "manual",

      morning_shift_start: normalizeTimeValue(config.morning_shift_start),
      morning_shift_end: normalizeTimeValue(config.morning_shift_end),

      afternoon_shift_start: normalizeTimeValue(config.afternoon_shift_start),
      afternoon_shift_end: normalizeTimeValue(config.afternoon_shift_end),

      night_shift_start: normalizeTimeValue(config.night_shift_start),
      night_shift_end: normalizeTimeValue(config.night_shift_end),

      auto_shift_logout: config.auto_shift_logout ?? false,
      auto_logout_after_minutes: config.auto_logout_after_minutes || "",

      require_opening_float: config.require_opening_float ?? true,
      require_closing_cashup: config.require_closing_cashup ?? true,
      allow_multiple_open_shifts: config.allow_multiple_open_shifts ?? false,
      allow_unscheduled_shifts: config.allow_unscheduled_shifts ?? true,
      early_clock_in_minutes: config.early_clock_in_minutes ?? 15,
      shift_warning_before_end_minutes: config.shift_warning_before_end_minutes ?? 15,
      shift_grace_minutes_after_end: config.shift_grace_minutes_after_end ?? 10,
      allow_continue_unclosed_shift: config.allow_continue_unclosed_shift ?? true,
      require_cashup_before_new_shift: config.require_cashup_before_new_shift ?? true,
      block_sales_after_shift_end: config.block_sales_after_shift_end ?? false,
      allow_supervisor_cashup: config.allow_supervisor_cashup ?? true,
      require_on_behalf_reason: config.require_on_behalf_reason ?? true,

      status: config.status || "active",
    });

    setCurrentShiftResult(null);
    setFormOpen(true);
  };

  const handleDelete = async (configId) => {
    const ok = await requestEnterpriseConfirmation({
      title: "Delete Shift Configuration",
      message: "Delete this shift configuration?",
      confirmLabel: "Delete configuration",
    });
    if (!ok) return;

    try {
      setDeletingId(configId);

      const response = await deleteShiftConfiguration(configId);

      showMessage({
        type: "success",
        title: "Configuration Deleted",
        message: getApiMessage(response),
      });

      if (formData.id === configId) {
        handleClear();
      }

      await loadConfigs();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
    }
  };

  const handleGetCurrentConfig = async () => {
    try {
      setDetecting(true);

      const response = await getCurrentShiftConfiguration({
        branch_id: formData.branch_id || null,
      });

      setCurrentShiftResult({
        type: "config",
        response,
      });

      showMessage({
        type: "success",
        title: "Current Config",
        message: getApiMessage(response),
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Current Config Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDetecting(false);
    }
  };

  const handleDetectCurrentShift = async () => {
    if (!formData.branch_id) {
      showMessage({
        type: "error",
        title: "Branch Required",
        message: "Select a branch before detecting current shift.",
      });

      return;
    }

    try {
      setDetecting(true);

      const response = await detectCurrentShift({
        branch_id: formData.branch_id,
      });

      setCurrentShiftResult({
        type: "shift",
        response,
      });

      showMessage({
        type: "success",
        title: "Shift Detection",
        message: getApiMessage(response),
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Shift Detection Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDetecting(false);
    }
  };

  const getConfigBranchDisplay = (config) => {
    if (!config.branch_id) return "Company Default";

    if (config.branch) return getBranchName(config.branch);

    const branch = branches.find(
      (item) => String(item.id) === String(config.branch_id)
    );

    return branch ? getBranchName(branch) : "-";
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="SHIFT CONFIGURATION"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-full">
          {/* FORM */}
          <EnterpriseFormModal open={formOpen} title={isEditing ? "Edit Shift Configuration" : "Add Shift Configuration"} onClose={() => { if (!saving) { handleClear(); setFormOpen(false); } }} busy={saving} width="1120px">
          <div className="border border-[#CAD5D5] bg-white flex flex-col max-h-[calc(100vh-90px)]">
            <div className="h-10 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
              <h2 className="text-[12px] font-black tracking-wide text-white uppercase">
                {isEditing ? "Edit Shift Config" : "Create Shift Config"}
              </h2>

              <span className="text-[10px] font-bold text-[#00BCBC]">
                {loading ? "LOADING" : "ACTIVE"}
              </span>
            </div>

            <form
              onSubmit={handleSubmit}
              className="p-4 space-y-4 overflow-y-auto"
            >
              <div className="border-l-4 border-[#00BCBC] bg-[#F0FDFA] px-4 py-3">
                <div className="text-[12px] font-black uppercase text-[#003C3C]">How shifts work</div>
                <div className="mt-1 text-[11px] text-[#465757]">A shift tracks one cashier's working session. Cashup reconciles that shift's sales and collections; a permitted supervisor may close it on the cashier's behalf with a recorded reason.</div>
              </div>
              <div>
                <label className="block text-[10px] font-black uppercase mb-1">
                  Branch
                </label>

                <select
                  name="branch_id"
                  value={formData.branch_id}
                  onChange={handleChange}
                  disabled={isEditing}
                  className="w-full h-8 border border-[#CAD5D5] bg-white px-2 text-[11px] outline-none focus:border-[#00BCBC] disabled:bg-[#F3F6F8]"
                >
                  <option value="">Company Default</option>

                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {getBranchName(branch)}
                      {getBranchCode(branch) ? ` (${getBranchCode(branch)})` : ""}
                    </option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input
                    type="checkbox"
                    name="enable_shifts"
                    checked={formData.enable_shifts}
                    onChange={handleChange}
                    className="w-3.5 h-3.5"
                  />
                  Enable Shifts
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input
                    type="checkbox"
                    name="auto_shift_logout"
                    checked={formData.auto_shift_logout}
                    onChange={handleChange}
                    className="w-3.5 h-3.5"
                  />
                  Automatic Sales Lock After Shift End
                </label>
              </div>

              <div>
                <label className="block text-[10px] font-black uppercase mb-1">
                  Shift Mode
                </label>

                <select
                  name="shift_mode"
                  value={formData.shift_mode}
                  onChange={handleChange}
                  className="w-full h-8 border border-[#CAD5D5] bg-white px-2 text-[11px] outline-none focus:border-[#00BCBC]"
                >
                  <option value="manual">Manual</option>
                  <option value="automatic">Automatic</option>
                  <option value="fixed_time">Fixed Time</option>
                </select>
              </div>

              {formData.auto_shift_logout && (
                <div>
                  <label className="block text-[10px] font-black uppercase mb-1">
                    Sales Lock Delay After Shift End (Minutes)
                  </label>

                  <input
                    type="number"
                    name="auto_logout_after_minutes"
                    value={formData.auto_logout_after_minutes}
                    onChange={handleChange}
                    min="1"
                    className="w-full h-8 border border-[#CAD5D5] px-2 text-[11px] outline-none focus:border-[#00BCBC]"
                  />
                  <div className="mt-1 text-[9px] leading-4 text-[#627474]">
                    Starts at the configured shift end, never at login. The cashier stays signed in so the shift can be cashed up.
                  </div>
                </div>
              )}

              <div className="border border-[#CAD5D5]">
                <div className="h-7 bg-[#F3F6F8] border-b border-[#D7DFDE] px-2 flex items-center">
                  <span className="text-[10px] font-black uppercase">
                    Shift Times
                  </span>
                </div>

                <div className="border-b border-[#D7DFDE] bg-[#FFF9E8] px-3 py-2 text-[10px] text-[#7A5600]">
                  Every time shows an explicit AM/PM preview. Overnight shifts may end on the following day.
                </div>

                <div className="p-2 space-y-2">
                  <div className="grid grid-cols-2 gap-2">
                    <ShiftTimeInput label="Morning Start" name="morning_shift_start" value={formData.morning_shift_start} onChange={handleChange} />
                    <ShiftTimeInput label="Morning End" name="morning_shift_end" value={formData.morning_shift_end} onChange={handleChange} />
                  </div>

                  <div className="grid grid-cols-2 gap-2">
                    <ShiftTimeInput label="Afternoon Start" name="afternoon_shift_start" value={formData.afternoon_shift_start} onChange={handleChange} />
                    <ShiftTimeInput label="Afternoon End" name="afternoon_shift_end" value={formData.afternoon_shift_end} onChange={handleChange} />
                  </div>

                  <div className="grid grid-cols-2 gap-2">
                    <ShiftTimeInput label="Night Start" name="night_shift_start" value={formData.night_shift_start} onChange={handleChange} />
                    <ShiftTimeInput label="Night End" name="night_shift_end" value={formData.night_shift_end} onChange={handleChange} />
                  </div>
                </div>
              </div>

              <div className="border border-[#CAD5D5] p-2 space-y-2">
                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input
                    type="checkbox"
                    name="require_opening_float"
                    checked={formData.require_opening_float}
                    onChange={handleChange}
                    className="w-3.5 h-3.5"
                  />
                  Require Opening Float
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input
                    type="checkbox"
                    name="require_closing_cashup"
                    checked={formData.require_closing_cashup}
                    onChange={handleChange}
                    className="w-3.5 h-3.5"
                  />
                  Require Closing Cashup
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input
                    type="checkbox"
                    name="allow_multiple_open_shifts"
                    checked={formData.allow_multiple_open_shifts}
                    onChange={handleChange}
                    className="w-3.5 h-3.5"
                  />
                  Allow Multiple Open Shifts
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input type="checkbox" name="allow_unscheduled_shifts" checked={formData.allow_unscheduled_shifts} onChange={handleChange} className="w-3.5 h-3.5" />
                  Allow Unscheduled Clock-In
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input type="checkbox" name="allow_continue_unclosed_shift" checked={formData.allow_continue_unclosed_shift} onChange={handleChange} className="w-3.5 h-3.5" />
                  Continue Own Unclosed Shift
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input type="checkbox" name="require_cashup_before_new_shift" checked={formData.require_cashup_before_new_shift} onChange={handleChange} className="w-3.5 h-3.5" />
                  Require Cashup Before New Shift
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input type="checkbox" name="block_sales_after_shift_end" checked={formData.block_sales_after_shift_end} onChange={handleChange} className="w-3.5 h-3.5" />
                  Block Sales After Grace Period
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input type="checkbox" name="allow_supervisor_cashup" checked={formData.allow_supervisor_cashup} onChange={handleChange} className="w-3.5 h-3.5" />
                  Allow Permitted Supervisor Cashup
                </label>

                <label className="flex items-center gap-2 text-[10px] font-bold uppercase">
                  <input type="checkbox" name="require_on_behalf_reason" checked={formData.require_on_behalf_reason} onChange={handleChange} className="w-3.5 h-3.5" />
                  Require Supervisor Reason
                </label>
              </div>

              <div className="grid gap-3 border border-[#CAD5D5] bg-[#F8FAFC] p-3 sm:grid-cols-3">
                <label className="text-[10px] font-black uppercase">Early Clock-In Minutes<input type="number" min="0" max="240" name="early_clock_in_minutes" value={formData.early_clock_in_minutes} onChange={handleChange} className="mt-1 h-9 w-full border border-[#CAD5D5] bg-white px-2 text-[11px]" /></label>
                <label className="text-[10px] font-black uppercase">End Warning Minutes<input type="number" min="0" max="240" name="shift_warning_before_end_minutes" value={formData.shift_warning_before_end_minutes} onChange={handleChange} className="mt-1 h-9 w-full border border-[#CAD5D5] bg-white px-2 text-[11px]" /></label>
                <label className="text-[10px] font-black uppercase">Grace Period Minutes<input type="number" min="0" max="1440" name="shift_grace_minutes_after_end" value={formData.shift_grace_minutes_after_end} onChange={handleChange} className="mt-1 h-9 w-full border border-[#CAD5D5] bg-white px-2 text-[11px]" /></label>
              </div>

              <div>
                <label className="block text-[10px] font-black uppercase mb-1">
                  Status
                </label>

                <select
                  name="status"
                  value={formData.status}
                  onChange={handleChange}
                  className="w-full h-8 border border-[#CAD5D5] bg-white px-2 text-[11px] outline-none focus:border-[#00BCBC]"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>

              <div className="grid grid-cols-2 gap-2 pt-2">
                <button
                  type="submit"
                  disabled={saving}
                  className="h-8 bg-[#069495] hover:bg-[#057B7C] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {saving ? "Saving..." : isEditing ? "Update" : "Save"}
                </button>

                <button
                  type="button"
                  onClick={handleClear}
                  className="h-8 bg-[#E4E8E8] hover:bg-[#D1D6D6] text-[#003636] text-[10px] font-black uppercase border border-[#CAD5D5]"
                >
                  Clear
                </button>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={handleGetCurrentConfig}
                  disabled={detecting}
                  className="h-8 bg-[#004142] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  Current Config
                </button>

                <button
                  type="button"
                  onClick={handleDetectCurrentShift}
                  disabled={detecting}
                  className="h-8 bg-[#FFC107] text-[#5E4300] text-[10px] font-black uppercase disabled:opacity-60"
                >
                  Detect Shift
                </button>
              </div>

              {currentShiftResult && (
                <div className="border border-[#CAD5D5] bg-[#F8FAFC] p-2 text-[10px] text-[#003636]">
                  <div className="font-black uppercase mb-1">
                    Result: {currentShiftResult.type}
                  </div>

                  <pre className="whitespace-pre-wrap break-all text-[9px]">
                    {JSON.stringify(currentShiftResult.response, null, 2)}
                  </pre>
                </div>
              )}
            </form>
          </div>
          </EnterpriseFormModal>

          {/* LIST */}
          <div className="border border-[#CAD5D5] bg-white flex flex-col min-h-[calc(100vh-90px)]">
            <div className="h-10 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
              <h2 className="text-[12px] font-black tracking-wide text-white uppercase">
                Shift Configurations
              </h2>

              <div className="flex items-center gap-2">
                <button type="button" onClick={() => { handleClear(); setFormOpen(true); }} className="h-7 bg-[#069494] px-3 text-[10px] font-bold text-white">Add Configuration</button>
                <button
                  type="button"
                  onClick={loadData}
                  disabled={loading}
                  className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {loading ? "Loading..." : "Refresh"}
                </button>

                <input
                  type="text"
                  value={configSearch}
                  onChange={(e) => setConfigSearch(e.target.value)}
                  placeholder="Search..."
                  className="h-7 w-[180px] border border-white/20 bg-[#003637] px-2 text-[11px] text-white outline-none placeholder:text-white/50"
                />
              </div>
            </div>

            <div className="overflow-auto">
              <table className="w-full border-collapse">
                <thead>
                  <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                    <th className="text-left px-3 py-2 text-[10px] font-black uppercase">
                      Branch
                    </th>
                    <th className="text-left px-3 py-2 text-[10px] font-black uppercase">
                      Mode
                    </th>
                    <th className="text-left px-3 py-2 text-[10px] font-black uppercase">
                      Morning
                    </th>
                    <th className="text-left px-3 py-2 text-[10px] font-black uppercase">
                      Afternoon
                    </th>
                    <th className="text-left px-3 py-2 text-[10px] font-black uppercase">
                      Night
                    </th>
                    <th className="text-left px-3 py-2 text-[10px] font-black uppercase">
                      Status
                    </th>
                    <th className="text-left px-3 py-2 text-[10px] font-black uppercase">
                      Action
                    </th>
                  </tr>
                </thead>

                <tbody>
                  {loading ? (
                    <tr>
                      <td
                        colSpan={7}
                        className="px-3 py-6 text-center text-[12px] text-[#637877]"
                      >
                        Loading shift configurations...
                      </td>
                    </tr>
                  ) : filteredConfigs.length === 0 ? (
                    <tr>
                      <td
                        colSpan={7}
                        className="px-3 py-6 text-center text-[12px] text-[#637877]"
                      >
                        No shift configurations found.
                      </td>
                    </tr>
                  ) : (
                    filteredConfigs.map((config) => (
                      <tr
                        key={config.id}
                        className="border-b border-[#EFF2F2] hover:bg-[#F8FAFC]"
                      >
                        <td className="px-3 py-2 text-[11px] font-semibold">
                          {getConfigBranchDisplay(config)}
                        </td>

                        <td className="px-3 py-2 text-[11px] uppercase">
                          {config.shift_mode}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {formatTime12(config.morning_shift_start)} – {formatTime12(config.morning_shift_end)}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {formatTime12(config.afternoon_shift_start)} – {formatTime12(config.afternoon_shift_end)}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {formatTime12(config.night_shift_start)} – {formatTime12(config.night_shift_end)}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          <span
                            className={`px-2 py-1 text-[10px] font-bold uppercase ${
                              config.status === "active"
                                ? "bg-[#DCFCE7] text-[#166534]"
                                : "bg-red-100 text-red-700"
                            }`}
                          >
                            {config.status}
                          </span>
                        </td>

                        <td className="px-3 py-2">
                          <div className="flex gap-2">
                            <button
                              type="button"
                              onClick={() => handleEdit(config)}
                              className="h-7 px-3 bg-[#004142] hover:bg-[#003637] text-white text-[10px] font-bold uppercase"
                            >
                              Edit
                            </button>

                            <button
                              type="button"
                              disabled={deletingId === config.id}
                              onClick={() => handleDelete(config.id)}
                              className="h-7 px-3 bg-red-700 hover:bg-red-800 text-white text-[10px] font-bold uppercase disabled:opacity-60"
                            >
                              {deletingId === config.id
                                ? "Deleting..."
                                : "Delete"}
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
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

export default ShiftConfiguration;

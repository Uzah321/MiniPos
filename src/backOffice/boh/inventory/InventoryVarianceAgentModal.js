import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  Activity,
  AlertTriangle,
  ArrowDownRight,
  ArrowUpRight,
  BrainCircuit,
  Building2,
  CheckCircle2,
  ChevronRight,
  Clock3,
  FileSearch,
  RefreshCw,
  Search,
  Settings2,
  ShieldCheck,
  Sparkles,
  X,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import {
  getInventoryVariance,
  getInventoryVarianceOverview,
  getInventoryVariances,
  runInventoryVarianceScan,
  updateInventoryVarianceAgentSettings,
} from "../../../services/EnterpriseInventoryService";

const DEFAULT_SETTINGS = {
  enabled: false,
  quantity_threshold: 0.0001,
  value_threshold: 0,
  scan_interval_minutes: 5,
  last_scan_at: null,
};

const DEFAULT_FILTERS = {
  branch_id: "",
  status: "open",
  direction: "",
  severity: "",
  q: "",
};

const asBoolean = (value) =>
  value === true || value === 1 || value === "1" || String(value || "").toLowerCase() === "true";

const asNumber = (value, fallback = 0) => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const quantity = (value) => asNumber(value).toLocaleString(undefined, {
  minimumFractionDigits: 0,
  maximumFractionDigits: 6,
});

const money = (value, currency = "") => `${currency ? `${currency} ` : ""}${asNumber(value).toLocaleString(undefined, {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})}`;

const dateTime = (value) => {
  if (!value) return "Not available";
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return String(value);
  return parsed.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
};

const branchLabel = (branch) => {
  const name = branch?.branch_name || branch?.name || "Unnamed branch";
  const code = branch?.branch_code || branch?.code || "";
  return code ? `${name} (${code})` : name;
};

const normalizeBranches = (response) => {
  const company = response?.company || response?.data?.company || response?.data || {};
  const rows = response?.company?.branches || response?.data?.branches || company?.branches || [];
  return Array.isArray(rows) ? rows : [];
};

const normalizeSettings = (payload = {}) => ({
  ...DEFAULT_SETTINGS,
  ...payload,
  enabled: asBoolean(payload?.enabled),
  quantity_threshold: asNumber(payload?.quantity_threshold),
  value_threshold: asNumber(payload?.value_threshold),
  scan_interval_minutes: Math.max(1, asNumber(payload?.scan_interval_minutes, 5)),
});

const normalizeVariancePage = (payload) => {
  const paginator = payload?.data && !Array.isArray(payload.data) ? payload.data : payload || {};
  const rows = Array.isArray(payload) ? payload : Array.isArray(paginator?.data) ? paginator.data : Array.isArray(payload?.variances) ? payload.variances : [];
  return {
    rows,
    currentPage: asNumber(paginator?.current_page || payload?.current_page, 1),
    lastPage: Math.max(1, asNumber(paginator?.last_page || payload?.last_page, 1)),
    total: asNumber(paginator?.total || payload?.total, rows.length),
  };
};

const apiMessage = (error, fallback) =>
  error?.response?.data?.message || error?.response?.data?.error || error?.message || fallback;

const permissionNames = (user) => {
  const values = [
    ...(user?.role?.permissions || []),
    ...(user?.permissions || []),
    ...(user?.role_permissions || []),
  ];
  return new Set(values.map((item) => String(item?.name || item?.permission_name || item?.permission?.name || item || "").trim().toLowerCase()));
};

const canManageAgent = (user) => {
  if (!user) return false;
  const role = String(user?.role?.name || user?.role_name || user?.user_type || "").toLowerCase().replace(/[ -]+/g, "_");
  if (user?.is_super_admin || ["super_admin", "super_administrator"].includes(role)) return true;
  const granted = permissionNames(user);
  return granted.has("manage_inventory_variance_agent") || granted.has("inventory_management");
};

const severityStyle = (severity) => {
  switch (String(severity || "").toLowerCase()) {
    case "critical": return "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]";
    case "high": return "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]";
    case "medium": return "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]";
    default: return "border-[#CFDDDD] bg-[#F8F9FC] text-[#005555]";
  }
};

const statusStyle = (status) => String(status || "open").toLowerCase() === "resolved"
  ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
  : "border-[#FEC84B] bg-[#FFFAEB] text-[#B54708]";

const rowDirection = (row) => row?.direction || (asNumber(row?.variance_quantity ?? row?.quantity_variance) < 0 ? "shortage" : "surplus");
const rowVariance = (row) => row?.variance_quantity ?? row?.quantity_variance ?? row?.difference_quantity ?? 0;
const rowExpected = (row) => row?.expected_quantity ?? row?.system_quantity ?? row?.baseline_quantity ?? 0;
const rowObserved = (row) => row?.actual_quantity ?? row?.observed_quantity ?? row?.counted_quantity ?? row?.current_quantity ?? 0;
const rowExposure = (row) => row?.exposure_value ?? row?.variance_value ?? row?.absolute_value ?? 0;
const rowCurrency = (row) => row?.currency_code || row?.currency || "";
const rowProductName = (row) => row?.product_name || row?.product?.name || "Unknown product";
const rowProductCode = (row) => row?.product_code || row?.sku || row?.product?.code || row?.product?.sku || "";
const rowStartedAt = (row) => row?.started_at || row?.opened_at || row?.detected_at || row?.created_at;
const eventSummary = (event) => {
  const payload = event?.payload || {};
  if (payload.reason) return `${String(payload.reason).replaceAll("_", " ")}${payload.baseline_quantity != null ? ` · Baseline ${quantity(payload.baseline_quantity)}` : ""}`;
  if (payload.expected_quantity != null) return `Expected ${quantity(payload.expected_quantity)} · Observed ${quantity(payload.actual_quantity)} · Difference ${quantity(payload.variance_quantity)}`;
  if (event?.source_type) return `Source: ${String(event.source_type).replaceAll("_", " ")}`;
  return "Recorded in the product audit history.";
};

const Pill = ({ children, className = "" }) => (
  <span className={`inline-flex items-center border px-2 py-1 text-[10px] font-bold uppercase tracking-wide ${className}`}>{children}</span>
);

const SummaryCard = ({ label, value, meta, tone = "blue", icon: Icon }) => {
  const tones = {
    blue: ["border-[#A4E5E4]", "bg-[#F1F8F8]", "text-[#057B7B]"],
    red: ["border-[#FDA29B]", "bg-[#FEF3F2]", "text-[#B42318]"],
    amber: ["border-[#FEDF89]", "bg-[#FFFAEB]", "text-[#B54708]"],
    green: ["border-[#ABEFC6]", "bg-[#ECFDF3]", "text-[#067647]"],
  };
  const selected = tones[tone] || tones.blue;
  return (
    <div className={`border bg-white p-3 ${selected[0]}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="text-[10px] font-bold uppercase tracking-[0.08em] text-[#627474]">{label}</div>
        {Icon && <div className={`flex h-7 w-7 items-center justify-center ${selected[1]} ${selected[2]}`}><Icon className="h-4 w-4" /></div>}
      </div>
      <div className="mt-1 text-[22px] font-black tabular-nums text-[#0C1B1B]">{value}</div>
      {meta && <div className="mt-1 text-[10px] leading-4 text-[#627474]">{meta}</div>}
    </div>
  );
};

const EmptyState = ({ children }) => (
  <div className="flex min-h-[260px] flex-col items-center justify-center px-6 text-center">
    <div className="flex h-12 w-12 items-center justify-center border border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]"><FileSearch className="h-6 w-6" /></div>
    <div className="mt-3 max-w-md text-[12px] leading-5 text-[#627474]">{children}</div>
  </div>
);

export default function InventoryVarianceAgentModal({
  open,
  onClose,
  currentUser,
  initialBranchId = "",
}) {
  const [activeView, setActiveView] = useState("monitor");
  const [branches, setBranches] = useState([]);
  const [companyName, setCompanyName] = useState("");
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [draft, setDraft] = useState(DEFAULT_SETTINGS);
  const [settingsReady, setSettingsReady] = useState(false);
  const [validatedScopeKey, setValidatedScopeKey] = useState("");
  const [overview, setOverview] = useState({});
  const [variances, setVariances] = useState([]);
  const [filters, setFilters] = useState({ ...DEFAULT_FILTERS, branch_id: initialBranchId ? String(initialBranchId) : "" });
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ currentPage: 1, lastPage: 1, total: 0 });
  const [selectedDetail, setSelectedDetail] = useState(null);
  const [loading, setLoading] = useState(false);
  const [loadingData, setLoadingData] = useState(false);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [scanning, setScanning] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const scopeKey = `${currentUser?.company?.id || currentUser?.company_id || currentUser?.company_code || ""}|${initialBranchId || ""}`;
  const canManage = canManageAgent(currentUser);
  const summary = overview?.summary || {};
  const exposureGroups = Array.isArray(summary.exposure_by_currency) ? summary.exposure_by_currency : [];
  const exposureLabel = summary.mixed_currency
    ? `${exposureGroups.length} currencies`
    : exposureGroups.length === 1
      ? money(exposureGroups[0].exposure_value, exposureGroups[0].currency_code === "UNSPECIFIED" ? "" : exposureGroups[0].currency_code)
      : money(summary.exposure_value);
  const exposureDetails = exposureGroups.length
    ? exposureGroups.map((group) => money(group.exposure_value, group.currency_code === "UNSPECIFIED" ? "Unspecified" : group.currency_code)).join(" · ")
    : "No valued cases";
  const selectedBranch = branches.find((branch) => String(branch.id) === String(filters.branch_id));
  const branchFilterIsValid = !filters.branch_id || branches.some((branch) => String(branch.id) === String(filters.branch_id));

  const loadAgentData = useCallback(async (targetPage = page) => {
    if (!open || !settingsReady || validatedScopeKey !== scopeKey || !branchFilterIsValid || !settings.enabled) return;
    try {
      setLoadingData(true);
      setError("");
      const params = {
        page: targetPage,
        per_page: 25,
        ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
        status: filters.status || "all",
        ...(filters.direction ? { direction: filters.direction } : {}),
        ...(filters.severity ? { severity: filters.severity } : {}),
        ...(filters.q.trim() ? { q: filters.q.trim() } : {}),
      };
      const [overviewResult, listResult] = await Promise.all([
        getInventoryVarianceOverview(filters.branch_id ? { branch_id: filters.branch_id } : {}),
        getInventoryVariances(params),
      ]);
      const normalizedPage = normalizeVariancePage(listResult);
      setOverview(overviewResult || {});
      setVariances(normalizedPage.rows);
      setPagination(normalizedPage);
      setPage(normalizedPage.currentPage);
    } catch (requestError) {
      setError(apiMessage(requestError, "Variance intelligence data is unavailable."));
    } finally {
      setLoadingData(false);
    }
  }, [branchFilterIsValid, filters, open, page, scopeKey, settings.enabled, settingsReady, validatedScopeKey]);

  useEffect(() => {
    if (!open) return;
    let active = true;
    setLoading(true);
    setError("");
    setNotice("");
    setSelectedDetail(null);
    setActiveView("monitor");
    setBranches([]);
    setOverview({});
    setVariances([]);
    setPage(1);
    setFilters({ ...DEFAULT_FILTERS });
    setSettingsReady(false);
    setValidatedScopeKey("");
    Promise.all([getAuthorisedCompany(), getInventoryVarianceOverview()])
      .then(([companyResult, overviewResult]) => {
        if (!active) return;
        const normalized = normalizeSettings(overviewResult?.settings);
        const companyBranches = normalizeBranches(companyResult);
        const preferredBranchId = initialBranchId ? String(initialBranchId) : "";
        const validBranchId = companyBranches.some((branch) => String(branch.id) === preferredBranchId) ? preferredBranchId : "";
        setBranches(companyBranches);
        setFilters({ ...DEFAULT_FILTERS, branch_id: validBranchId });
        setCompanyName(companyResult?.company?.name || companyResult?.data?.company?.name || companyResult?.company?.company_name || companyResult?.data?.company?.company_name || "Current company");
        setOverview(overviewResult || {});
        setSettings(normalized);
        setDraft(normalized);
        setValidatedScopeKey(scopeKey);
        setSettingsReady(true);
      })
      .catch((requestError) => { if (active) setError(apiMessage(requestError, "Unable to initialise the variance agent.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [initialBranchId, open, scopeKey]);

  useEffect(() => {
    if (!open || !settingsReady || validatedScopeKey !== scopeKey || !branchFilterIsValid || !settings.enabled) return undefined;
    const timer = window.setTimeout(() => loadAgentData(page), filters.q ? 350 : 0);
    return () => window.clearTimeout(timer);
  }, [branchFilterIsValid, filters.branch_id, filters.status, filters.direction, filters.severity, filters.q, open, page, scopeKey, settings.enabled, settingsReady, validatedScopeKey, loadAgentData]);

  const updateFilter = (key, value) => {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
    setSelectedDetail(null);
  };

  const openDetail = async (row) => {
    const varianceId = row?.id || row?.variance_id;
    if (!varianceId) return;
    setSelectedDetail({ variance: row, events: [], evidence: [], recent_movements: [] });
    try {
      setLoadingDetail(true);
      setError("");
      const detail = await getInventoryVariance(varianceId);
      setSelectedDetail(detail || { variance: row });
    } catch (requestError) {
      setError(apiMessage(requestError, "Unable to load variance evidence."));
    } finally {
      setLoadingDetail(false);
    }
  };

  const saveSettings = async () => {
    if (!canManage) return;
    try {
      setSaving(true);
      setError("");
      setNotice("");
      const payload = {
        enabled: Boolean(draft.enabled),
        quantity_threshold: Math.max(0.000001, asNumber(draft.quantity_threshold, 0.0001)),
        value_threshold: Math.max(0, asNumber(draft.value_threshold)),
        scan_interval_minutes: Math.min(1440, Math.max(1, Math.round(asNumber(draft.scan_interval_minutes, 5)))),
      };
      const result = normalizeSettings(await updateInventoryVarianceAgentSettings(payload));
      setSettings(result);
      setDraft(result);
      setSettingsReady(true);
      if (!result.enabled) {
        setOverview({});
        setVariances([]);
      }
      setNotice(result.enabled ? "Variance Intelligence Agent enabled. Baselines are active and monitoring has started." : "Variance Intelligence Agent disabled. Existing audit history is preserved.");
      window.dispatchEvent(new CustomEvent("salexa:variance-agent-settings-changed", { detail: result }));
      setActiveView(result.enabled ? "monitor" : "configuration");
    } catch (requestError) {
      setError(apiMessage(requestError, "Unable to save variance agent settings."));
    } finally {
      setSaving(false);
    }
  };

  const runScan = async () => {
    if (!canManage || !settings.enabled || validatedScopeKey !== scopeKey || !branchFilterIsValid) return;
    try {
      setScanning(true);
      setError("");
      setNotice("");
      await runInventoryVarianceScan(filters.branch_id ? { branch_id: filters.branch_id } : {});
      await loadAgentData(1);
      setNotice(`Variance scan queued for ${selectedBranch ? branchLabel(selectedBranch) : "all authorised branches"}. Results update after the worker processes it.`);
      window.dispatchEvent(new CustomEvent("salexa:variance-agent-refreshed"));
    } catch (requestError) {
      setError(apiMessage(requestError, "Unable to run a variance scan."));
    } finally {
      setScanning(false);
    }
  };

  const recentRows = useMemo(() => {
    const rows = overview?.variances;
    return Array.isArray(rows) ? rows : [];
  }, [overview]);

  const detailVariance = selectedDetail?.variance || selectedDetail || {};
  const originEvidence = detailVariance?.evidence && typeof detailVariance.evidence === "object" ? detailVariance.evidence : {};
  const detectionWindow = originEvidence?.detection_window || {};
  const firstCandidate = originEvidence?.earliest_candidate_movement || null;
  const detailEvents = Array.isArray(selectedDetail?.events) ? selectedDetail.events : [];
  const detailMovements = Array.isArray(selectedDetail?.recent_movements)
    ? selectedDetail.recent_movements
    : Array.isArray(detailVariance?.evidence?.recent_movements)
      ? detailVariance.evidence.recent_movements
      : [];
  const detailEvidence = Array.isArray(selectedDetail?.evidence)
    ? selectedDetail.evidence
    : selectedDetail?.evidence && typeof selectedDetail.evidence === "object"
      ? Object.entries(selectedDetail.evidence).map(([label, value]) => ({ label, value }))
      : [];

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="Variance Intelligence Agent"
      subtitle="Continuous hospitality inventory exception detection"
    >
      <div className="flex h-full min-h-0 flex-col bg-[#F6F8FA] text-[#0C1B1B]">
        <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-[#CFD6D6] bg-[#004142] px-4 py-3 text-white">
          <div className="flex min-w-0 items-center gap-3">
            <div className="relative flex h-10 w-10 shrink-0 items-center justify-center border border-[#00C8C8]/40 bg-[#004C4C] text-[#6DDBDB]">
              <BrainCircuit className="h-5 w-5" />
              {settings.enabled && <span className="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full border-2 border-[#004141] bg-[#12B76A]" />}
            </div>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-[13px] font-bold">Inventory control intelligence</span>
                <Pill className={settings.enabled ? "border-[#6CE9A6] bg-[#053321] text-[#6CE9A6]" : "border-white/30 bg-white/10 text-white/70"}>{settings.enabled ? "Enabled" : "Disabled"}</Pill>
              </div>
              <div className="mt-0.5 truncate text-[10px] text-white/65">Auditable quantity and value variance detection across company branches</div>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button type="button" onClick={() => setActiveView("monitor")} className={`h-9 border px-4 text-[11px] font-bold ${activeView === "monitor" ? "border-[#6DDBDB] bg-[#069494] text-white" : "border-white/25 bg-transparent text-white/80 hover:bg-white/10"}`}><Activity className="mr-1.5 inline h-4 w-4" />Monitor</button>
            <button type="button" onClick={() => setActiveView("configuration")} className={`h-9 border px-4 text-[11px] font-bold ${activeView === "configuration" ? "border-[#6DDBDB] bg-[#069494] text-white" : "border-white/25 bg-transparent text-white/80 hover:bg-white/10"}`}><Settings2 className="mr-1.5 inline h-4 w-4" />Configure</button>
            {settings.enabled && <button type="button" onClick={() => loadAgentData(1)} disabled={loadingData} className="h-9 border border-white/25 bg-transparent px-3 text-[11px] font-bold text-white hover:bg-white/10 disabled:opacity-50">Refresh results</button>}
            {settings.enabled && <button type="button" onClick={runScan} disabled={!canManage || scanning} className="h-9 border border-[#6CE9A6] bg-[#053321] px-4 text-[11px] font-bold text-[#6CE9A6] disabled:cursor-not-allowed disabled:opacity-50"><RefreshCw className={`mr-1.5 inline h-4 w-4 ${scanning ? "animate-spin" : ""}`} />{scanning ? "Queueing" : "Queue scan"}</button>}
          </div>
        </div>

        {(error || notice) && <div className={`shrink-0 border-b px-4 py-2 text-[11px] font-semibold ${error ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"}`}>{error || notice}</div>}

        {loading ? (
          <div className="flex flex-1 items-center justify-center"><RefreshCw className="h-6 w-6 animate-spin text-[#069494]" /><span className="ml-3 text-[12px] text-[#627474]">Initialising variance intelligence...</span></div>
        ) : activeView === "configuration" ? (
          <div className="min-h-0 flex-1 overflow-auto p-4 sm:p-6">
            <div className="mx-auto grid max-w-[1120px] gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
              <section className="border border-[#CFD6D6] bg-white">
                <div className="flex items-start justify-between gap-4 border-b border-[#E9EDED] px-5 py-4">
                  <div>
                    <div className="flex items-center gap-2 text-[14px] font-bold text-[#0C1B1B]"><Sparkles className="h-4 w-4 text-[#008A8A]" />Agent configuration</div>
                    <p className="mt-1 text-[11px] leading-5 text-[#627474]">Company-level controls apply to all authorised branches. Branch selectors in Monitor narrow visibility and scans; they do not weaken the company audit trail.</p>
                  </div>
                  <label className={`flex shrink-0 items-center gap-3 border px-3 py-2 ${draft.enabled ? "border-[#ABEFC6] bg-[#ECFDF3]" : "border-[#CFD6D6] bg-[#F9FAFB]"}`}>
                    <span className={`text-[11px] font-bold ${draft.enabled ? "text-[#067647]" : "text-[#627474]"}`}>{draft.enabled ? "Enabled" : "Disabled"}</span>
                    <input type="checkbox" checked={Boolean(draft.enabled)} onChange={(event) => setDraft((current) => ({ ...current, enabled: event.target.checked }))} disabled={!canManage} className="h-4 w-4 accent-[#12B76A]" aria-label="Enable variance intelligence agent" />
                  </label>
                </div>

                <div className="grid gap-4 p-5 sm:grid-cols-3">
                  <label className="block">
                    <span className="text-[10px] font-bold uppercase tracking-wide text-[#465757]">Quantity threshold</span>
                    <input type="number" min="0.000001" step="0.000001" value={draft.quantity_threshold} onChange={(event) => setDraft((current) => ({ ...current, quantity_threshold: event.target.value }))} disabled={!canManage} className="mt-1 h-10 w-full border border-[#96A5A4] bg-white px-3 text-[12px] outline-none focus:border-[#008A8A] disabled:bg-[#F2F4F7]" />
                    <span className="mt-1 block text-[10px] leading-4 text-[#627474]">Ignore absolute quantity differences below this amount.</span>
                  </label>
                  <label className="block">
                    <span className="text-[10px] font-bold uppercase tracking-wide text-[#465757]">Value threshold</span>
                    <input type="number" min="0" step="0.01" value={draft.value_threshold} onChange={(event) => setDraft((current) => ({ ...current, value_threshold: event.target.value }))} disabled={!canManage} className="mt-1 h-10 w-full border border-[#96A5A4] bg-white px-3 text-[12px] outline-none focus:border-[#008A8A] disabled:bg-[#F2F4F7]" />
                    <span className="mt-1 block text-[10px] leading-4 text-[#627474]">Ignore value exposure below this base-currency amount.</span>
                  </label>
                  <label className="block">
                    <span className="text-[10px] font-bold uppercase tracking-wide text-[#465757]">Scan interval</span>
                    <div className="relative mt-1"><input type="number" min="1" max="1440" step="1" value={draft.scan_interval_minutes} onChange={(event) => setDraft((current) => ({ ...current, scan_interval_minutes: event.target.value }))} disabled={!canManage} className="h-10 w-full border border-[#96A5A4] bg-white px-3 pr-16 text-[12px] outline-none focus:border-[#008A8A] disabled:bg-[#F2F4F7]" /><span className="absolute right-3 top-3 text-[10px] text-[#627474]">minutes</span></div>
                    <span className="mt-1 block text-[10px] leading-4 text-[#627474]">Minimum time between automatic scans when the overview is opened.</span>
                  </label>
                </div>

                <div className="mx-5 mb-5 border border-[#A4E5E4] bg-[#F1F8F8] p-4">
                  <div className="flex gap-3">
                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-[#057B7B]" />
                    <div>
                      <div className="text-[11px] font-bold text-[#005F5F]">Audit-safe resolution policy</div>
                      <p className="mt-1 text-[11px] leading-5 text-[#324443]">The agent never hides or manually dismisses a variance. A product variance is resolved only when an authorised stock adjustment is posted for that product, or when a completed stocktake establishes its new baseline. Resolution evidence and history remain available.</p>
                    </div>
                  </div>
                </div>

                <div className="flex items-center justify-between gap-3 border-t border-[#E9EDED] bg-[#F9FAFB] px-5 py-3">
                  <div className="text-[10px] text-[#627474]">Last scan: {dateTime(overview?.last_scan?.completed_at)}</div>
                  <button type="button" onClick={saveSettings} disabled={!canManage || saving} className="h-9 bg-[#008A8A] px-5 text-[11px] font-bold text-white hover:bg-[#057B7B] disabled:cursor-not-allowed disabled:opacity-50">{saving ? "Saving..." : "Save agent settings"}</button>
                </div>
              </section>

              <aside className="space-y-4">
                <div className="border border-[#CFD6D6] bg-white p-4">
                  <div className="flex items-center gap-2 text-[12px] font-bold"><BrainCircuit className="h-4 w-4 text-[#008A8A]" />How the agent works</div>
                  <ol className="mt-3 space-y-3 text-[11px] leading-5 text-[#465757]">
                    <li className="flex gap-2"><span className="flex h-5 w-5 shrink-0 items-center justify-center bg-[#F1F8F8] font-bold text-[#057B7B]">1</span><span>Builds a traceable baseline per product and branch when enabled or reset.</span></li>
                    <li className="flex gap-2"><span className="flex h-5 w-5 shrink-0 items-center justify-center bg-[#F1F8F8] font-bold text-[#057B7B]">2</span><span>Compares expected stock movement with observed stock and applies your thresholds.</span></li>
                    <li className="flex gap-2"><span className="flex h-5 w-5 shrink-0 items-center justify-center bg-[#F1F8F8] font-bold text-[#057B7B]">3</span><span>Prioritises exceptions by direction, severity, duration, and estimated cost exposure.</span></li>
                    <li className="flex gap-2"><span className="flex h-5 w-5 shrink-0 items-center justify-center bg-[#F1F8F8] font-bold text-[#057B7B]">4</span><span>Retains evidence until a posted adjustment or completed stocktake resets the product.</span></li>
                  </ol>
                </div>
                <div className={`border p-4 ${canManage ? "border-[#ABEFC6] bg-[#ECFDF3]" : "border-[#FEDF89] bg-[#FFFAEB]"}`}>
                  <div className={`text-[11px] font-bold ${canManage ? "text-[#067647]" : "text-[#B54708]"}`}>{canManage ? "Configuration access confirmed" : "View-only access"}</div>
                  <p className="mt-1 text-[10px] leading-4 text-[#465757]">{canManage ? "Your role can configure the agent and run on-demand scans." : "The manage_inventory_variance_agent permission is required to change settings or run scans."}</p>
                </div>
              </aside>
            </div>
          </div>
        ) : !settings.enabled ? (
          <div className="flex flex-1 items-center justify-center p-6">
            <div className="w-full max-w-[720px] border border-[#CFD6D6] bg-white p-7 text-center shadow-sm">
              <div className="mx-auto flex h-14 w-14 items-center justify-center border border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]"><BrainCircuit className="h-7 w-7" /></div>
              <h2 className="mt-4 text-[18px] font-bold text-[#0C1B1B]">Variance Intelligence Agent is ready to activate</h2>
              <p className="mx-auto mt-2 max-w-xl text-[12px] leading-6 text-[#627474]">Enable it to establish product baselines and surface shortages, overages, duration, evidence, and financial exposure by company branch.</p>
              <button type="button" onClick={() => setActiveView("configuration")} className="mt-5 h-10 bg-[#008A8A] px-6 text-[11px] font-bold text-white">Open configuration</button>
            </div>
          </div>
        ) : (
          <div className="flex min-h-0 flex-1 flex-col">
            <div className="grid shrink-0 gap-2 border-b border-[#CFD6D6] bg-white p-3 sm:grid-cols-2 xl:grid-cols-5">
              <SummaryCard label="Open variances" value={asNumber(summary.open).toLocaleString()} meta="Active investigation cases" tone="red" icon={AlertTriangle} />
              <SummaryCard label="Shortages" value={asNumber(summary.shortages).toLocaleString()} meta="Products below expected" tone="amber" icon={ArrowDownRight} />
              <SummaryCard label="Surpluses" value={asNumber(summary.surpluses).toLocaleString()} meta="Products above expected" tone="blue" icon={ArrowUpRight} />
              <SummaryCard label="High priority" value={asNumber(summary.high).toLocaleString()} meta={`${asNumber(summary.medium)} medium · ${asNumber(summary.low)} low`} tone="red" icon={Activity} />
              <SummaryCard label="Cost exposure" value={exposureLabel} meta={exposureDetails} tone="red" icon={ShieldCheck} />
            </div>

            <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
              <aside className="w-full shrink-0 border-b border-[#CFD6D6] bg-[#F9FAFB] p-3 lg:w-[250px] lg:border-b-0 lg:border-r">
                <div className="flex items-center justify-between"><span className="text-[11px] font-bold uppercase tracking-wide text-[#324443]">Scope & filters</span><button type="button" onClick={() => setFilters({ ...DEFAULT_FILTERS })} className="text-[10px] font-bold text-[#057B7B]">Reset</button></div>
                <label className="mt-3 block"><span className="text-[9px] font-bold uppercase text-[#627474]">Company scope</span><div className="mt-1 flex h-9 items-center gap-2 border border-[#CFD6D6] bg-[#F2F4F7] px-2 text-[10px] text-[#465757]"><Building2 className="h-3.5 w-3.5" />{companyName}</div></label>
                <label className="mt-3 block"><span className="text-[9px] font-bold uppercase text-[#627474]">Branch</span><select value={filters.branch_id} onChange={(event) => updateFilter("branch_id", event.target.value)} className="mt-1 h-9 w-full border border-[#96A5A4] bg-white px-2 text-[11px]"><option value="">All authorised branches</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label>
                <label className="mt-3 block"><span className="text-[9px] font-bold uppercase text-[#627474]">Status</span><select value={filters.status} onChange={(event) => updateFilter("status", event.target.value)} className="mt-1 h-9 w-full border border-[#96A5A4] bg-white px-2 text-[11px]"><option value="open">Open</option><option value="resolved">Resolved</option><option value="">All statuses</option></select></label>
                <label className="mt-3 block"><span className="text-[9px] font-bold uppercase text-[#627474]">Direction</span><select value={filters.direction} onChange={(event) => updateFilter("direction", event.target.value)} className="mt-1 h-9 w-full border border-[#96A5A4] bg-white px-2 text-[11px]"><option value="">All directions</option><option value="shortage">Shortage</option><option value="surplus">Surplus</option></select></label>
                <label className="mt-3 block"><span className="text-[9px] font-bold uppercase text-[#627474]">Severity</span><select value={filters.severity} onChange={(event) => updateFilter("severity", event.target.value)} className="mt-1 h-9 w-full border border-[#96A5A4] bg-white px-2 text-[11px]"><option value="">All severities</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></label>
                <div className="mt-4 border border-[#A4E5E4] bg-[#F1F8F8] p-3 text-[10px] leading-4 text-[#324443]"><strong className="block text-[#057B7B]">Product reset rule</strong>A posted stock adjustment or completed stocktake for the affected product resolves its open variance and starts a new baseline.</div>
                <div className="mt-3 text-[9px] leading-4 text-[#627474]">Last scan<br /><strong className="text-[#324443]">{dateTime(overview?.last_scan?.completed_at)}</strong></div>
              </aside>

              <section className="flex min-h-0 min-w-0 flex-1 flex-col bg-white">
                <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-[#E9EDED] px-3 py-2">
                  <label className="flex h-9 min-w-[260px] flex-1 items-center border border-[#96A5A4] bg-white px-3 sm:max-w-[440px]"><Search className="h-4 w-4 text-[#627474]" /><input value={filters.q} onChange={(event) => updateFilter("q", event.target.value)} placeholder="Search product name, code or SKU" className="min-w-0 flex-1 px-2 text-[11px] outline-none" /></label>
                  <div className="text-[10px] text-[#627474]">{pagination.total.toLocaleString()} records · {selectedBranch ? branchLabel(selectedBranch) : "Company-wide"}</div>
                </div>

                <div className="min-h-0 flex-1 overflow-auto">
                  {loadingData && !variances.length ? <EmptyState><RefreshCw className="mx-auto mb-2 h-5 w-5 animate-spin" />Analysing inventory evidence...</EmptyState> : variances.length ? (
                    <table className="sx-enterprise-table min-w-[1220px]">
                      <thead><tr><th>Product</th><th>Branch</th><th className="text-right">Expected</th><th className="text-right">Observed</th><th className="text-right">Variance</th><th className="text-right">Exposure</th><th>Severity</th><th>Status</th><th>Started</th><th className="w-20 text-center">Evidence</th></tr></thead>
                      <tbody>{variances.map((row) => {
                        const direction = rowDirection(row);
                        const varianceValue = rowVariance(row);
                        return <tr key={row.id || row.variance_id} className="cursor-pointer hover:bg-[#F6FAFA]" onClick={() => openDetail(row)}>
                          <td><strong className="block text-[#0C1B1B]">{rowProductName(row)}</strong><span className="text-[9px] text-[#627474]">{rowProductCode(row) || "No product code"}</span></td>
                          <td><span className="block">{row.branch_name || row.branch?.branch_name || row.branch?.name || "Company"}</span><span className="text-[9px] capitalize text-[#627474]">{String(row.stock_area || "stock").replaceAll("_", " ")}</span></td>
                          <td className="text-right tabular-nums">{quantity(rowExpected(row))}</td>
                          <td className="text-right tabular-nums">{quantity(rowObserved(row))}</td>
                        <td className={`text-right font-bold tabular-nums ${direction === "shortage" ? "text-[#B42318]" : "text-[#057B7B]"}`}>{varianceValue > 0 ? "+" : ""}{quantity(varianceValue)}<span className="ml-1 text-[9px] font-normal uppercase">{direction}</span></td>
                          <td className="text-right font-semibold tabular-nums">{money(rowExposure(row), rowCurrency(row))}</td>
                          <td><Pill className={severityStyle(row.severity)}>{row.severity || "low"}</Pill></td>
                          <td><Pill className={statusStyle(row.status)}>{row.status || "open"}</Pill></td>
                          <td><span className="block">{dateTime(rowStartedAt(row))}</span>{row.age_hours != null && <span className="text-[9px] text-[#627474]">{quantity(row.age_hours)} hours open</span>}</td>
                          <td className="text-center"><button type="button" onClick={(event) => { event.stopPropagation(); openDetail(row); }} className="inline-flex h-8 w-8 items-center justify-center border border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]" aria-label={`View evidence for ${rowProductName(row)}`}><ChevronRight className="h-4 w-4" /></button></td>
                        </tr>;
                      })}</tbody>
                    </table>
                  ) : <EmptyState>{filters.status === "open" ? "No open product variances match this company and branch scope. The agent will surface new exceptions as they are detected." : "No variance history matches the selected filters."}{recentRows.length > 0 && <span className="mt-2 block">Recent agent activity is available in the overview summary.</span>}</EmptyState>}
                </div>

                <div className="flex shrink-0 items-center justify-between border-t border-[#E9EDED] bg-[#F9FAFB] px-3 py-2 text-[10px] text-[#627474]">
                  <span>Page {pagination.currentPage} of {pagination.lastPage}</span>
                  <div className="flex gap-1"><button type="button" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page <= 1 || loadingData} className="h-8 border border-[#CFD6D6] bg-white px-3 font-bold disabled:opacity-40">Previous</button><button type="button" onClick={() => setPage((current) => Math.min(pagination.lastPage, current + 1))} disabled={page >= pagination.lastPage || loadingData} className="h-8 border border-[#CFD6D6] bg-white px-3 font-bold disabled:opacity-40">Next</button></div>
                </div>
              </section>
            </div>
          </div>
        )}

        {selectedDetail && (
          <div className="absolute inset-0 z-20 flex justify-end bg-[#003434]/45" onClick={() => setSelectedDetail(null)}>
            <aside className="flex h-full w-full max-w-[560px] flex-col border-l border-[#96A5A4] bg-white shadow-2xl" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" aria-label="Variance evidence detail">
              <div className="flex shrink-0 items-start justify-between gap-3 border-b border-[#CFD6D6] bg-[#004142] px-4 py-3 text-white">
                <div><div className="flex items-center gap-2 text-[13px] font-bold"><FileSearch className="h-4 w-4 text-[#6DDBDB]" />Variance evidence</div><div className="mt-1 text-[10px] text-white/65">Audit trail, movements and resolution state</div></div>
                <button type="button" onClick={() => setSelectedDetail(null)} className="flex h-8 w-8 items-center justify-center border border-white/25 hover:bg-white/10" aria-label="Close evidence"><X className="h-4 w-4" /></button>
              </div>
              {loadingDetail ? <div className="flex flex-1 items-center justify-center"><RefreshCw className="h-5 w-5 animate-spin text-[#008A8A]" /></div> : <div className="min-h-0 flex-1 overflow-auto p-4">
                <div className="border border-[#CFD6D6] bg-[#F9FAFB] p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2"><div><div className="text-[15px] font-bold">{rowProductName(detailVariance)}</div><div className="mt-1 text-[10px] text-[#627474]">{rowProductCode(detailVariance) || "No product code"}</div></div><div className="flex gap-1"><Pill className={severityStyle(detailVariance.severity)}>{detailVariance.severity || "low"}</Pill><Pill className={statusStyle(detailVariance.status)}>{detailVariance.status || "open"}</Pill></div></div>
                  <div className="mt-4 grid grid-cols-2 gap-2 text-[10px] sm:grid-cols-3"><div><span className="block text-[#627474]">Expected</span><strong className="mt-1 block text-[13px]">{quantity(rowExpected(detailVariance))}</strong></div><div><span className="block text-[#627474]">Observed</span><strong className="mt-1 block text-[13px]">{quantity(rowObserved(detailVariance))}</strong></div><div><span className="block text-[#627474]">Variance</span><strong className="mt-1 block text-[13px]">{quantity(rowVariance(detailVariance))}</strong></div><div><span className="block text-[#627474]">Cost exposure</span><strong className="mt-1 block text-[13px]">{money(rowExposure(detailVariance), rowCurrency(detailVariance))}</strong></div><div><span className="block text-[#627474]">Started</span><strong className="mt-1 block">{dateTime(rowStartedAt(detailVariance))}</strong></div><div><span className="block text-[#627474]">Scope</span><strong className="mt-1 block">{detailVariance.branch_name || detailVariance.branch?.name || "Company"} · {String(detailVariance.stock_area || "stock").replaceAll("_", " ")}</strong></div></div>
                </div>

                <div className="mt-3 border border-[#FEDF89] bg-[#FFFAEB] p-3">
                  <div className="flex items-center gap-2 text-[11px] font-bold text-[#B54708]"><Clock3 className="h-4 w-4" />Where the difference began</div>
                  <p className="mt-1 text-[10px] leading-5 text-[#465757]">The balance was last known consistent at {dateTime(detectionWindow.last_consistent_at || originEvidence.baseline_at)}. The difference was first detected at {dateTime(detectionWindow.first_detected_at || rowStartedAt(detailVariance))}.</p>
                  {firstCandidate && <div className="mt-2 border-l-2 border-[#F79009] bg-white px-3 py-2 text-[10px] leading-5 text-[#465757]"><strong className="block text-[#324443]">Earliest movement in the detection window</strong>{String(firstCandidate.movement_type || "Stock movement").replaceAll("_", " ")} · {dateTime(firstCandidate.created_at)}{firstCandidate.reference_type && ` · ${String(firstCandidate.reference_type).replaceAll("_", " ")}`}. This is a lead for investigation, not a confirmed cause.</div>}
                </div>
                <div className="mt-3 border border-[#A4E5E4] bg-[#F1F8F8] p-3 text-[10px] leading-5 text-[#324443]"><ShieldCheck className="mr-2 inline h-4 w-4 text-[#057B7B]" /><strong>Resolution:</strong> post a stock adjustment for this product or complete a stocktake containing it. The agent will resolve this exception automatically and retain the evidence below.</div>

                <DetailSection title="Detection evidence" icon={BrainCircuit} empty="No structured evidence was returned for this variance.">
                  {detailEvidence.map((item, index) => <div key={item.id || item.label || index} className="grid grid-cols-[130px_1fr] gap-3 border-b border-[#E9EDED] px-3 py-2 text-[10px]"><span className="font-bold uppercase text-[#627474]">{String(item.label || item.type || item.key || `Evidence ${index + 1}`).replaceAll("_", " ")}</span><span className="break-words text-[#324443]">{typeof (item.value ?? item.detail ?? item.description) === "object" ? JSON.stringify(item.value ?? item.detail ?? item.description) : String(item.value ?? item.detail ?? item.description ?? "-")}</span></div>)}
                </DetailSection>

                <DetailSection title="Agent event timeline" icon={Clock3} empty="No agent events were returned.">
                  {detailEvents.map((event, index) => <div key={event.id || index} className="flex gap-3 border-b border-[#E9EDED] px-3 py-2"><div className="mt-1 h-2 w-2 shrink-0 rounded-full bg-[#008A8A]" /><div className="min-w-0"><div className="text-[10px] font-bold text-[#324443]">{String(event.event_type || event.type || event.action || "Agent event").replaceAll("_", " ")}</div><div className="mt-0.5 text-[9px] leading-4 text-[#627474]">{event.description || event.message || event.notes || eventSummary(event)}</div><div className="mt-0.5 text-[9px] text-[#96A5A4]">{dateTime(event.occurred_at || event.created_at)}</div></div></div>)}
                </DetailSection>

                <DetailSection title="Recent stock movements" icon={Activity} empty="No recent stock movements were returned.">
                  {detailMovements.map((movement, index) => {
                    const movementDelta = asNumber(movement.quantity_in) - asNumber(movement.quantity_out);
                    return <div key={movement.id || index} className="grid grid-cols-[1fr_auto] gap-3 border-b border-[#E9EDED] px-3 py-2 text-[10px]"><div><strong className="block text-[#324443]">{String(movement.movement_type || movement.type || movement.reference_type || "Stock movement").replaceAll("_", " ")}</strong><span className="mt-0.5 block text-[9px] text-[#627474]">{dateTime(movement.created_at)}{movement.reference_type ? ` · ${String(movement.reference_type).replaceAll("_", " ")}` : ""}</span></div><strong className="text-right tabular-nums">{movementDelta > 0 ? "+" : ""}{quantity(movementDelta)}</strong></div>;
                  })}
                </DetailSection>

                {String(detailVariance.status || "").toLowerCase() === "resolved" && <div className="mt-3 flex gap-3 border border-[#ABEFC6] bg-[#ECFDF3] p-3"><CheckCircle2 className="h-5 w-5 shrink-0 text-[#067647]" /><div><div className="text-[11px] font-bold text-[#067647]">Variance resolved</div><div className="mt-1 text-[10px] leading-4 text-[#465757]">{detailVariance.resolution_type ? String(detailVariance.resolution_type).replaceAll("_", " ") : "Stock was reset by an authorised inventory action"} · {dateTime(detailVariance.resolved_at)}</div></div></div>}
              </div>}
            </aside>
          </div>
        )}
      </div>
    </FullScreenModal>
  );
}

const DetailSection = ({ title, icon: Icon, empty, children }) => {
  const childRows = React.Children.toArray(children);
  return <section className="mt-3 border border-[#CFD6D6] bg-white"><div className="flex items-center gap-2 border-b border-[#E9EDED] bg-[#F9FAFB] px-3 py-2 text-[10px] font-bold uppercase tracking-wide text-[#324443]">{Icon && <Icon className="h-3.5 w-3.5 text-[#008A8A]" />}{title}</div>{childRows.length ? childRows : <div className="px-3 py-5 text-center text-[10px] text-[#627474]">{empty}</div>}</section>;
};

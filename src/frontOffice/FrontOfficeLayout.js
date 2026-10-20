import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  AlertTriangle,
  Banknote,
  CalendarCheck2,
  CheckCircle2,
  FileText,
  LogOut,
  ReceiptText,
  RefreshCw,
  ShoppingCart,
  Cpu,
  Clock3,
  MonitorCog,
  Printer,
  UserRound,
  X,
} from "lucide-react";
import CreateSaleModal from "../backOffice/pages/sales/CreateSale";
import ReceiptRegisterModal from "../backOffice/pages/sales/ReceiptRegisterModal";
import EnterpriseCashupModal from "../components/EnterpriseCashupModal";
import SyncStatusBadge from "../components/SyncStatusBadge";
import QuotationDeskModal from "../backOffice/pages/quotation/QuotationDeskModal";
import FiscalDevicePlaceholderModal from "./FiscalDevicePlaceholderModal";
import CurrentTerminalModal from "./CurrentTerminalModal";
import CurrentTerminalPrinterModal from "./CurrentTerminalPrinterModal";
import { useFrontAuth } from "../context/FrontAuthContext";
import { useWorkspace } from "../context/WorkspaceContext";
import { getLocalTillDisplay } from "../services/TillService";
import { PLATFORM_FALLBACK_LOGO } from "../utils/hospitalityHierarchy";
import {
  getCurrentTradingDay,
  getDayEndPreflight,
  getTradingDayEndProgress,
  catchUpTradingDayEnd,
  runTradingDayEnd,
} from "../services/TradingDayEndEnterpriseService";

const PosTile = ({ icon: Icon, label, onClick, disabled = false }) => (
  <button type="button" onClick={onClick} disabled={disabled} className="group flex min-h-[148px] w-full flex-col items-center justify-center rounded-lg border border-[#006161] bg-gradient-to-br from-[#067C7C] to-[#006565] px-4 py-5 text-center text-white shadow-[0_5px_14px_rgba(0,78,78,0.18)] transition-all duration-200 hover:-translate-y-1 hover:from-[#008888] hover:to-[#007070] hover:shadow-[0_10px_24px_rgba(0,78,78,0.25)] focus:outline-none focus:ring-2 focus:ring-[#004D4D] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-45 disabled:hover:translate-y-0 disabled:hover:from-[#067C7C] disabled:hover:to-[#006565]">
    <span className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-white/10 transition-transform duration-200 group-hover:scale-105"><Icon className="h-10 w-10" strokeWidth={1.5} /></span>
    <span className="text-[16px] font-medium leading-[1.12] drop-shadow-[0_1px_1px_rgba(0,0,0,0.16)]">{label}</span>
  </button>
);

const resolveTradingDate = (payload) =>
  payload?.trading_date ||
  payload?.business_date ||
  payload?.active_business_date ||
  payload?.open_trading_day?.trading_date ||
  null;

const resolveCatchUp = (payload) => {
  const contract = payload?.catch_up || {};
  return {
    available: Boolean(contract.available ?? payload?.catch_up_available),
    lagDays: Number(contract.lag_days ?? payload?.lag_days ?? 0),
    sourceTradingDate:
      contract.source_trading_date || payload?.source_trading_date || resolveTradingDate(payload),
    targetSystemDate:
      contract.target_system_date || payload?.target_system_date || payload?.system_date || null,
    skippedDateFrom: contract.skipped_date_from || payload?.skipped_date_from || null,
    skippedDateTo: contract.skipped_date_to || payload?.skipped_date_to || null,
    skippedDayCount: Number(contract.skipped_day_count ?? payload?.skipped_day_count ?? 0),
    requiresReason: Boolean(contract.requires_reason ?? payload?.catch_up_requires_reason ?? true),
  };
};

const localIsoDate = (date = new Date()) => {
  const offset = date.getTimezoneOffset() * 60000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 10);
};

const readableDate = (value) => {
  if (!value) return "Not open";
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString(undefined, {
    weekday: "short",
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
};

const newRunKey = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `foh-day-end-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
};

const authoritativeRunKey = (payload, fallbackRunKey) => {
  const responseRunKey = String(payload?.run_key || "").trim();
  if (responseRunKey) return responseRunKey;

  const progressUrl = String(payload?.progress_url || "").trim();
  if (!progressUrl) return fallbackRunKey;
  const path = progressUrl.split("?")[0].replace(/\/+$/, "");
  const encodedRunKey = path.slice(path.lastIndexOf("/") + 1);
  if (!encodedRunKey) return fallbackRunKey;
  try {
    return decodeURIComponent(encodedRunKey);
  } catch {
    return encodedRunKey;
  }
};

const fallbackStage = (percent) => {
  if (percent >= 82) return "Finalising reports and opening the next eligible trading day";
  if (percent >= 62) return "Closing the active trading day";
  if (percent >= 42) return "Reconciling sales, cash and stock";
  if (percent >= 22) return "Locking the trading day against new sales";
  return "Validating Day End readiness";
};

const initialDayEndRun = () => ({
  status: "confirm",
  percent: 0,
  stageLabel: "Ready to run Day End",
  message: "",
  result: null,
});

const normalizePermissionName = (value) => String(value || "")
  .trim()
  .toLowerCase()
  .replaceAll("-", "_")
  .replaceAll(" ", "_");

const addPermissionBucket = (permissionNames, bucket) => {
  if (Array.isArray(bucket)) {
    bucket.forEach((entry) => {
      const value = typeof entry === "string"
        ? entry
        : entry?.name || entry?.permission_name || entry?.key || entry?.slug;
      if (value) permissionNames.add(normalizePermissionName(value));
    });
    return;
  }

  if (typeof bucket === "object" && bucket) {
    Object.entries(bucket).forEach(([key, allowed]) => {
      if (allowed === true || allowed === 1 || allowed === "1") {
        permissionNames.add(normalizePermissionName(key));
      }
    });
  }
};

const canAccessDayEnd = (user, capabilities = {}) => {
  const explicitCapabilities = [
    capabilities?.general_procedure,
    capabilities?.can_run_day_end,
    capabilities?.run_day_end,
    capabilities?.day_end,
  ];
  if (explicitCapabilities.some((allowed) => allowed === false)) return false;
  if (explicitCapabilities.some((allowed) => allowed === true)) return true;

  const roleName = normalizePermissionName(
    (typeof user?.role === "string" ? user.role : user?.role?.name) ||
      user?.role_name ||
      user?.user_type,
  );
  if (user?.is_super_admin || ["super_admin", "super_administrator"].includes(roleName)) return true;

  const buckets = [
    user?.permissions,
    user?.role?.permissions,
    user?.permission_names,
    user?.role_permissions,
    capabilities?.permissions,
    capabilities?.permission_names,
    capabilities?.role_permissions,
  ].filter((bucket) => bucket !== undefined && bucket !== null);
  if (buckets.length === 0) return true;

  const permissionNames = new Set();
  buckets.forEach((bucket) => addPermissionBucket(permissionNames, bucket));

  return ["general_procedure", "run_day_end", "day_end"].some((permission) =>
    [...permissionNames].some((name) =>
      name === permission || name.endsWith(`:${permission}`) || name.endsWith(`.${permission}`),
    ),
  );
};

const canManageTerminalPrinters = (user, capabilities = {}) => {
  const roleName = normalizePermissionName(
    (typeof user?.role === "string" ? user.role : user?.role?.name) ||
      user?.role_name ||
      user?.user_type,
  );
  if (user?.is_super_admin || ["super_admin", "super_administrator"].includes(roleName)) return true;

  const permissionNames = new Set();
  [
    user?.permissions,
    user?.role?.permissions,
    user?.permission_names,
    user?.role_permissions,
    capabilities?.permissions,
    capabilities?.permission_names,
    capabilities?.role_permissions,
  ].forEach((bucket) => addPermissionBucket(permissionNames, bucket));

  return [...permissionNames].some((name) =>
    name === "printer_management" ||
    name.endsWith(":printer_management") ||
    name.endsWith(".printer_management")
  );
};

export default function FrontOfficeLayout() {
  const { user, logout } = useFrontAuth();
  const {
    branch,
    country,
    company,
    brand,
    complex,
    shop,
    branding,
    user: workspaceUser,
    operation,
    capabilities,
    devices,
    loading: workspaceLoading,
    error,
    refresh,
  } = useWorkspace();
  const [selling, setSelling] = useState(false);
  const [receiptsOpen, setReceiptsOpen] = useState(false);
  const [cashupOpen, setCashupOpen] = useState(false);
  const [quotationDeskOpen, setQuotationDeskOpen] = useState(false);
  const [fiscalDeviceOpen, setFiscalDeviceOpen] = useState(false);
  const [terminalOpen, setTerminalOpen] = useState(false);
  const [printerSetupOpen, setPrinterSetupOpen] = useState(false);
  const [dayEndOpen, setDayEndOpen] = useState(false);
  const [dayEndRun, setDayEndRun] = useState(initialDayEndRun);
  const [dayEndPreflight, setDayEndPreflight] = useState(null);
  const [dayEndPreflightLoading, setDayEndPreflightLoading] = useState(false);
  const [dayEndPreflightError, setDayEndPreflightError] = useState("");
  const [tradingDay, setTradingDay] = useState(null);
  const [tradingDayLoading, setTradingDayLoading] = useState(false);
  const [tradingDayError, setTradingDayError] = useState("");
  const [now, setNow] = useState(() => new Date());
  const [till, setTill] = useState(() => getLocalTillDisplay());
  const dayEndPermissionUser = useMemo(() => ({
    ...(user || {}),
    ...(workspaceUser || {}),
    permissions: workspaceUser?.permissions ?? user?.permissions,
    permission_names: workspaceUser?.permission_names ?? user?.permission_names,
    role_permissions: workspaceUser?.role_permissions ?? user?.role_permissions,
    role: typeof workspaceUser?.role === "object"
      ? {
        ...(typeof user?.role === "object" ? user.role : {}),
        ...workspaceUser.role,
        permissions: workspaceUser.role?.permissions ?? user?.role?.permissions,
      }
      : user?.role,
    role_name:
      workspaceUser?.role_name ||
      (typeof workspaceUser?.role === "string" ? workspaceUser.role : null) ||
      user?.role_name,
  }), [user, workspaceUser]);
  const dayEndAllowed = useMemo(
    () => !workspaceLoading && canAccessDayEnd(dayEndPermissionUser, capabilities),
    [capabilities, dayEndPermissionUser, workspaceLoading],
  );
  const printerManagementAllowed = useMemo(
    () => !workspaceLoading && canManageTerminalPrinters(dayEndPermissionUser, capabilities),
    [capabilities, dayEndPermissionUser, workspaceLoading],
  );
  const dayEndPollTimer = useRef(null);
  const dayEndFallbackTimer = useRef(null);
  const dayEndRunToken = useRef(0);
  const backendProgressSeen = useRef(false);
  const dayEndProgressKey = useRef(null);
  const tradingDayRequestIdRef = useRef(0);

  const stopDayEndTimers = useCallback(() => {
    if (dayEndPollTimer.current) window.clearInterval(dayEndPollTimer.current);
    if (dayEndFallbackTimer.current) window.clearInterval(dayEndFallbackTimer.current);
    dayEndPollTimer.current = null;
    dayEndFallbackTimer.current = null;
    dayEndProgressKey.current = null;
  }, []);

  const loadTradingDay = useCallback(async () => {
    // A configuration-sync event can arrive while an older /current request is
    // still in flight. Only the newest request may publish UI state, otherwise
    // the slower pre-sync response can put the till back on the closed date.
    const requestId = tradingDayRequestIdRef.current + 1;
    tradingDayRequestIdRef.current = requestId;

    try {
      setTradingDayLoading(true);
      const payload = await getCurrentTradingDay(
        branch?.id ? { branch_id: branch.id } : {},
      );
      if (requestId !== tradingDayRequestIdRef.current) return payload;
      setTradingDay(payload || null);
      setTradingDayError("");
      return payload;
    } catch (requestError) {
      if (requestId !== tradingDayRequestIdRef.current) return null;
      setTradingDay(null);
      setTradingDayError(
        requestError?.response?.data?.message ||
          requestError?.message ||
          "Trading date is unavailable.",
      );
      return null;
    } finally {
      if (requestId === tradingDayRequestIdRef.current) {
        setTradingDayLoading(false);
      }
    }
  }, [branch?.id]);

  const loadDayEndPreflight = useCallback(async () => {
    try {
      setDayEndPreflightLoading(true);
      setDayEndPreflightError("");
      const payload = await getDayEndPreflight(
        branch?.id ? { branch_id: branch.id } : {},
      );
      setDayEndPreflight(payload || null);
      return payload;
    } catch (requestError) {
      const responsePayload =
        requestError?.response?.data?.data || requestError?.response?.data || null;
      if (responsePayload && typeof responsePayload === "object") {
        setDayEndPreflight(responsePayload);
      } else {
        setDayEndPreflight(null);
      }
      setDayEndPreflightError(
        requestError?.response?.data?.message ||
          requestError?.response?.data?.error ||
          requestError?.message ||
          "Day End readiness could not be checked.",
      );
      return responsePayload;
    } finally {
      setDayEndPreflightLoading(false);
    }
  }, [branch?.id]);

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 30000);
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    const refreshTillDisplay = () => setTill(getLocalTillDisplay());
    window.addEventListener("salexa_till_updated", refreshTillDisplay);
    return () => window.removeEventListener("salexa_till_updated", refreshTillDisplay);
  }, []);

  // The workspace also reloads in the background whenever the shop server
  // receives a Back Office configuration change. Only a change of shop may
  // close the sale screen; a settings refresh must never eject a cashier
  // mid-sale.
  const sellingShopIdRef = useRef(null);
  const workspaceShopId = String(shop?.id || branch?.id || "");
  useEffect(() => {
    if (workspaceLoading) return;
    if (sellingShopIdRef.current !== null && sellingShopIdRef.current !== workspaceShopId) {
      setSelling(false);
    }
    sellingShopIdRef.current = workspaceShopId;
  }, [workspaceLoading, workspaceShopId]);

  useEffect(() => {
    loadTradingDay();
    const timer = window.setInterval(loadTradingDay, 60000);
    const refreshWhenVisible = () => {
      if (document.visibilityState === "visible") loadTradingDay();
    };
    const refreshWhenAvailable = () => loadTradingDay();
    window.addEventListener("focus", refreshWhenAvailable);
    window.addEventListener("online", refreshWhenAvailable);
    document.addEventListener("visibilitychange", refreshWhenVisible);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("focus", refreshWhenAvailable);
      window.removeEventListener("online", refreshWhenAvailable);
      document.removeEventListener("visibilitychange", refreshWhenVisible);
    };
  }, [loadTradingDay]);

  useEffect(() => {
    // A cloud-forced Day End arrives through the background configuration
    // snapshot. Refresh the authoritative local trading day as soon as that
    // snapshot is committed instead of leaving the header stale until the
    // next minute-based poll.
    const refreshSyncedTradingDay = () => loadTradingDay();
    window.addEventListener("salexa_workspace_changed", refreshSyncedTradingDay);
    return () => window.removeEventListener("salexa_workspace_changed", refreshSyncedTradingDay);
  }, [loadTradingDay]);

  useEffect(() => () => {
    dayEndRunToken.current += 1;
    stopDayEndTimers();
  }, [stopDayEndTimers]);

  const openDayEnd = () => {
    if (!dayEndAllowed) return;
    setDayEndRun(initialDayEndRun());
    setDayEndPreflight(null);
    setDayEndPreflightError("");
    setDayEndOpen(true);
    loadTradingDay();
    loadDayEndPreflight();
  };

  const closeDayEnd = () => {
    if (dayEndRun.status === "running") return;
    dayEndRunToken.current += 1;
    stopDayEndTimers();
    setDayEndOpen(false);
  };

  const startDayEndProgress = (runKey, token) => {
    backendProgressSeen.current = false;
    dayEndProgressKey.current = runKey;
    return new Promise((resolve, reject) => {
      let settled = false;
      const settle = (callback, value) => {
        if (settled) return;
        settled = true;
        callback(value);
      };

      const poll = async () => {
        try {
          const progress = await getTradingDayEndProgress(
            runKey,
            branch?.id ? { branch_id: branch.id } : {},
          );
          if (
            dayEndRunToken.current !== token ||
            dayEndProgressKey.current !== runKey ||
            !progress
          ) return;
          backendProgressSeen.current = true;
          const progressStatus = String(progress.status || "running").toLowerCase();
          const isFailure = ["failed", "blocked", "error"].includes(progressStatus);
          setDayEndRun((current) => ({
            ...current,
            status: isFailure ? "failed" : progressStatus === "complete" ? "complete" : "running",
            percent: Math.max(
              current.percent,
              Math.min(100, Number(progress.percent || (progressStatus === "complete" ? 100 : 0))),
            ),
            stageLabel:
              progress.stage_label ||
              (isFailure ? "Day End stopped" : current.stageLabel),
            message: isFailure
              ? progress.message || progress.error || "Day End could not be completed."
              : current.message,
            result: progressStatus === "complete" ? progress : current.result,
          }));
          if (isFailure) {
            stopDayEndTimers();
            const progressError = new Error(
              progress.message || progress.error || "Day End could not be completed.",
            );
            progressError.progress = progress;
            settle(reject, progressError);
          } else if (progressStatus === "complete") {
            stopDayEndTimers();
            settle(resolve, progress);
          }
        } catch {
          // A progress record can appear shortly after the idempotent Day End request begins.
        }
      };

      poll();
      dayEndPollTimer.current = window.setInterval(poll, 750);
      dayEndFallbackTimer.current = window.setInterval(() => {
        if (
          backendProgressSeen.current ||
          dayEndRunToken.current !== token ||
          dayEndProgressKey.current !== runKey
        ) return;
        setDayEndRun((current) => {
          if (current.status !== "running") return current;
          const nextPercent = Math.min(88, current.percent + 6);
          return {
            ...current,
            percent: nextPercent,
            stageLabel: fallbackStage(nextPercent),
          };
        });
      }, 800);
    });
  };

  const runDayEndOperation = async ({ catchUp = false, reason = "" } = {}) => {
    const readinessPayload = dayEndPreflight || tradingDay;
    const activeTradingDate = resolveTradingDate(readinessPayload);
    const operationalReadiness = readinessPayload?.operational_readiness;
    const catchUpContract = resolveCatchUp(readinessPayload);
    const frontStockBlockers = Array.isArray(readinessPayload?.blocking_front_stock_closings)
      ? readinessPayload.blocking_front_stock_closings
      : [];
    if (
      tradingDayLoading ||
      dayEndPreflightLoading ||
      !activeTradingDate ||
      operationalReadiness?.can_run_day_end === false ||
      frontStockBlockers.length > 0 ||
      (catchUp
        ? !catchUpContract.available || !catchUpContract.targetSystemDate || !String(reason).trim()
        : readinessPayload?.can_run_day_end === false)
    ) {
      return;
    }

    stopDayEndTimers();
    const token = dayEndRunToken.current + 1;
    dayEndRunToken.current = token;
    const runKey = newRunKey();
    setDayEndRun({
      status: "running",
      percent: 5,
      stageLabel: fallbackStage(5),
      message: "",
      result: null,
      runKey,
      operationType: catchUp ? "catch_up" : "day_end",
    });

    const request = catchUp
      ? catchUpTradingDayEnd({
          ...(branch?.id ? { branch_id: branch.id } : {}),
          run_key: runKey,
          expected_trading_date: activeTradingDate,
          expected_system_date: catchUpContract.targetSystemDate,
          reason: String(reason).trim(),
          confirmation_token: "CATCH_UP_TO_SYSTEM_DATE",
        })
      : runTradingDayEnd({
          ...(branch?.id ? { branch_id: branch.id } : {}),
          run_key: runKey,
          expected_trading_date: activeTradingDate,
        });
    const settleProgress = (promise) => promise.then(
      (progress) => ({ status: "complete", progress }),
      (progressError) => ({ status: "failed", error: progressError }),
    );
    let progressPromise = settleProgress(startDayEndProgress(runKey, token));

    try {
      const requestResult = await request;
      if (dayEndRunToken.current !== token) return;
      const requestStatus = String(requestResult?.status || "complete").toLowerCase();
      const activeRunKey = authoritativeRunKey(requestResult, runKey);
      if (["failed", "blocked", "error"].includes(requestStatus)) {
        const requestFailure = new Error(
          requestResult?.message || requestResult?.error || "Day End could not be completed.",
        );
        requestFailure.progress = requestResult;
        throw requestFailure;
      }

      let result = requestResult;
      if (["pending", "running"].includes(requestStatus)) {
        if (activeRunKey !== runKey) {
          stopDayEndTimers();
          setDayEndRun((current) => ({
            ...current,
            runKey: activeRunKey,
            stageLabel: "Resuming the active Day End run",
          }));
          progressPromise = settleProgress(startDayEndProgress(activeRunKey, token));
        }
        const progressOutcome = await progressPromise;
        if (dayEndRunToken.current !== token) return;
        if (progressOutcome.status === "failed") throw progressOutcome.error;
        result = {
          ...(requestResult || {}),
          ...(progressOutcome.progress || {}),
        };
      }

      dayEndRunToken.current = token + 1;
      stopDayEndTimers();
      setDayEndRun({
        status: "complete",
        percent: 100,
        stageLabel: catchUp ? "Trading date catch-up completed" : "Day End completed",
        message: catchUp
          ? "The shop was caught up to the current system date. Historical sales stayed on their authoritative trading date."
          : "The trading day was closed successfully.",
        result: result || null,
        runKey: activeRunKey,
        operationType: catchUp ? "catch_up" : "day_end",
      });
      await Promise.allSettled([loadTradingDay(), refresh()]);
    } catch (requestError) {
      if (dayEndRunToken.current !== token) return;
      dayEndRunToken.current = token + 1;
      stopDayEndTimers();
      const responseData =
        requestError?.progress ||
        requestError?.response?.data?.data ||
        requestError?.response?.data ||
        {};
      setDayEndRun((current) => ({
        ...current,
        status: "failed",
        stageLabel: responseData?.stage_label || "Day End failed",
        message:
          responseData?.message ||
          responseData?.error ||
          requestError?.message ||
          "Day End could not be completed.",
        result: responseData || null,
      }));
      if (responseData && typeof responseData === "object") {
        setDayEndPreflight((current) => ({ ...(current || {}), ...responseData }));
      }
      await loadTradingDay();
    }
  };

  const runDayEnd = () => runDayEndOperation();
  const runDayEndCatchUp = (reason) => runDayEndOperation({ catchUp: true, reason });

  const leaveFrontOffice = () => {
    logout();
    window.location.replace("/");
  };

  const companyName = company?.name || "ServeNest";
  const brandName = brand?.name || companyName;
  const complexName = complex?.name || "";
  const branchName = shop?.name || branch?.name || "Selected shop";
  const countryName = country?.name || "";
  const brandLogo = branding?.tenant_logo
    || branding?.logo
    || brand?.logo
    || brand?.image
    || PLATFORM_FALLBACK_LOGO;
  const hierarchyLabel = [countryName, companyName, brandName, complexName, branchName]
    .filter(Boolean)
    .join(" · ");
  const operatorName = user?.name || user?.username || "Operator";
  const activeShift = user?.active_shift || user?.active_shift_session || null;
  const activeShiftName = String(activeShift?.shift_name || "").trim();
  const activeShiftLabel = activeShiftName
    ? `${activeShiftName.charAt(0).toUpperCase()}${activeShiftName.slice(1)} shift`
    : "No open shift";
  const configuredSalesLayout = operation?.sales_screen_layout || user?.sales_screen_layout || null;
  const dayLabel = now.toLocaleDateString(undefined, { weekday: "short", day: "2-digit", month: "short", year: "numeric" });
  const timeLabel = now.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
  const activeTradingDate = resolveTradingDate(tradingDay);
  const systemDate = tradingDay?.system_date || localIsoDate(now);
  const dayEndReadiness = dayEndPreflight || tradingDay;
  const dayEndTradingDate = resolveTradingDate(dayEndReadiness);
  const dayEndOperationalReadiness = dayEndReadiness?.operational_readiness;
  const canRunDayEnd = Boolean(
    dayEndAllowed &&
      !tradingDayLoading &&
      !dayEndPreflightLoading &&
      !dayEndPreflightError &&
      dayEndTradingDate &&
      dayEndReadiness?.can_run_day_end !== false &&
      dayEndOperationalReadiness?.can_run_day_end !== false,
  );
  const hospitalitySaleAllowed = tradingDay?.sale_allowed ?? tradingDay?.can_sell;
  const tradingDateStatus = tradingDayLoading
    ? "Loading authoritative date…"
    : tradingDayError
      ? tradingDayError
      : activeTradingDate
        ? readableDate(activeTradingDate)
        : tradingDay?.status === "awaiting_next_system_date"
          ? "Awaiting next system date"
          : "No open trading date";
  const canStartSale = Boolean(
    !tradingDayLoading &&
      activeTradingDate &&
      hospitalitySaleAllowed !== false &&
      dayEndRun.status !== "running",
  );
  const hospitalitySaleBlockReason = dayEndRun.status === "running"
    ? "Day End is running. New orders remain locked until it completes."
    : tradingDayLoading
      ? "Checking the authoritative trading date before opening orders."
      : tradingDayError
        ? tradingDayError
        : tradingDay?.block_reason ||
          tradingDay?.sale_block_reason ||
          (tradingDay?.status === "awaiting_next_system_date"
            ? "Today is already closed. New orders will open on the next system date."
            : "No active Hospitality trading date is available for new orders.");
  const tradingDateAvailability = canStartSale
      ? {
          label: "Orders ready",
          title: `Trading date ${readableDate(activeTradingDate)} is active. New orders are available.`,
          iconClass: "text-[#117556]",
          dotClass: "bg-[#32A36D]",
          pulse: false,
        }
      : tradingDayLoading
        ? {
            label: "Checking trading date",
            title: hospitalitySaleBlockReason,
            iconClass: "text-[#9A6700]",
            dotClass: "bg-[#D5A12F]",
            pulse: true,
          }
        : dayEndRun.status === "running"
          ? {
              label: "Day End running",
              title: hospitalitySaleBlockReason,
              iconClass: "text-[#9A6700]",
              dotClass: "bg-[#D5A12F]",
              pulse: true,
            }
          : {
              label: "Orders locked",
              title: hospitalitySaleBlockReason,
              iconClass: "text-[#B42318]",
              dotClass: "bg-[#D92D20]",
              pulse: true,
            };

  return <div className="flex h-screen min-h-[560px] flex-col overflow-hidden bg-[#F4F4F4] font-[Georgia,serif] text-[#4B4B4B]">
    <header className="flex h-9 shrink-0 items-center border-b border-[#B9B9B9] bg-gradient-to-b from-white to-[#E7E7E7] px-3 shadow-sm">
      <img
        src={brandLogo}
        alt={`${brandName} logo`}
        className="mr-2 h-7 max-w-[132px] object-contain object-left"
        onError={(event) => {
          if (!event.currentTarget.src.endsWith("/servenest-static/servenest-logo.svg")) {
            event.currentTarget.src = PLATFORM_FALLBACK_LOGO;
          }
        }}
      />
      <span className="text-[12px] font-semibold text-[#3F3F3F]">{brandName}</span>
      <span className="mx-2 text-[#A3A3A3]">|</span>
      <span className="min-w-0 truncate text-[11px]" title={hierarchyLabel}>{branchName}{complexName ? ` · ${complexName}` : ""}</span>
      <span className="ml-auto hidden h-8 shrink-0 items-center gap-2 border-l border-[#B9B9B9] px-3 font-sans md:flex" aria-label="Hospitality trading and system dates">
          <CalendarCheck2 className="h-4 w-4 text-[#007474]" />
          <span className="leading-tight">
            <span className="block text-[9px] font-bold uppercase tracking-wide text-[#546565]">Trading Date</span>
            <span className="block text-[10px] font-semibold text-[#054747]">{tradingDateStatus}</span>
          </span>
          <span className="leading-tight">
            <span className="block text-[9px] font-bold uppercase tracking-wide text-[#546565]">System Date</span>
            <span className="block text-[10px] font-semibold text-[#054747]">{readableDate(systemDate)}</span>
          </span>
      </span>
      <span className="ml-3 flex h-7 shrink-0 items-center gap-1.5 border-l border-[#B9B9B9] px-3 font-sans text-[10px] font-semibold text-[#384B4A]" title={`Logged in as ${operatorName}`}><UserRound className="h-3.5 w-3.5 text-[#007474]" />{operatorName}</span>
      <span className={`flex h-7 shrink-0 items-center gap-1.5 border-l border-[#B9B9B9] px-3 font-sans text-[10px] font-semibold ${activeShift ? "text-[#087F5B]" : "text-[#B42318]"}`} title={activeShift ? `Current shift: ${activeShiftLabel}` : "No open shift session"}><Clock3 className="h-3.5 w-3.5" />{activeShiftLabel}</span>
      <button type="button" onClick={leaveFrontOffice} className="ml-3 flex h-7 items-center gap-1.5 border-l border-[#B9B9B9] px-3 font-sans text-[10px] text-[#555] hover:bg-[#E2E2E2]"><LogOut className="h-3.5 w-3.5" /> Sign out</button>
    </header>

    <main className="min-h-0 flex-1 overflow-auto border-b border-[#CBD5D5] bg-gradient-to-br from-white via-[#F6F9F9] to-[#EBF1F1] px-4 py-6 sm:px-8 sm:py-9">
      <div className="mx-auto max-w-[1100px]">
      <section aria-label="Current Hospitality dates" className="mb-4 grid gap-px overflow-hidden rounded-md border border-[#B5CAC9] bg-[#B5CAC9] font-sans sm:grid-cols-2">
          <div className="bg-[#EDF5F5] px-4 py-3">
            <div className="text-[9px] font-bold uppercase tracking-[0.12em] text-[#546867]">Authoritative Trading / Business Date</div>
            <div className="mt-1 text-[14px] font-semibold text-[#006565]">{tradingDateStatus}</div>
          </div>
          <div className="bg-white px-4 py-3">
            <div className="text-[9px] font-bold uppercase tracking-[0.12em] text-[#546867]">System / Current Date</div>
            <div className="mt-1 text-[14px] font-semibold text-[#054747]">{readableDate(systemDate)}</div>
          </div>
      </section>
      <div className="mb-5 font-sans"><h1 className="text-[20px] font-semibold text-[#054747]">Front Office</h1><p className="mt-1 text-[11px] text-[#6F7E7D]">Choose a function to continue</p></div>
      <section className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4" aria-label="Point of sale functions">
        <PosTile icon={ShoppingCart} label="New Order" onClick={() => setSelling(true)} disabled={!canStartSale} />
        <PosTile icon={ReceiptText} label="Receipts" onClick={() => setReceiptsOpen(true)} />
        <PosTile icon={FileText} label="Quotation Desk" onClick={() => setQuotationDeskOpen(true)} />
        <PosTile icon={Banknote} label="Cash & Shift" onClick={() => setCashupOpen(true)} />
        {dayEndAllowed && <PosTile icon={CalendarCheck2} label="Day End" onClick={openDayEnd} />}
        <PosTile icon={Printer} label="Printer Setup" onClick={() => setPrinterSetupOpen(true)} />
        <PosTile icon={MonitorCog} label="This Terminal" onClick={() => setTerminalOpen(true)} />
        <PosTile icon={Cpu} label="Fiscal Device" onClick={() => setFiscalDeviceOpen(true)} />
        <PosTile icon={RefreshCw} label="Refresh" onClick={() => {
          refresh().catch(() => {});
          loadTradingDay();
        }} />
      </section>
      </div>
    </main>

    <footer className="flex h-7 shrink-0 items-center border-t border-white bg-gradient-to-b from-[#EFEFEF] to-[#D6D6D6] font-sans text-[10px] text-[#535353] shadow-[0_-1px_2px_rgba(0,0,0,0.12)]">
      <span className="flex h-full items-center border-r border-[#B9B9B9] px-3"><i className={`mr-2 h-2 w-2 rounded-full ${devices?.ready ? "bg-[#67B567]" : "bg-[#D5A12F]"}`} />{devices?.ready ? "Devices ready" : "Device attention required"}</span>
      <span className="hidden h-full items-center border-r border-[#B9B9B9] px-3 sm:flex">{brandName} · {branchName}</span>
      <span className="hidden h-full items-center border-r border-[#B9B9B9] px-3 lg:flex">{till.label} · {devices?.printers_active ?? 0} printers</span>
      <span className="hidden h-full items-center border-r border-[#B9B9B9] px-3 md:flex">{operatorName} · {activeShiftLabel}</span>
      {tradingDateAvailability && (
        <span
          className={`flex h-full min-w-0 items-center gap-1.5 border-r border-[#B9B9B9] px-3 font-semibold ${tradingDateAvailability.iconClass}`}
          role="status"
          aria-live="polite"
          aria-label={`Trading date availability: ${tradingDateAvailability.label}. ${tradingDateAvailability.title}`}
          title={tradingDateAvailability.title}
        >
          <CalendarCheck2 className={`h-3.5 w-3.5 shrink-0 ${tradingDateAvailability.pulse ? "animate-pulse" : ""}`} aria-hidden="true" />
          <i className={`h-2 w-2 shrink-0 rounded-full ${tradingDateAvailability.dotClass} ${tradingDateAvailability.pulse ? "animate-pulse" : ""}`} aria-hidden="true" />
          <span className="hidden truncate sm:inline">{tradingDateAvailability.label}</span>
        </span>
      )}
      {error && (
        <button
          type="button"
          onClick={() => refresh().catch(() => {})}
          className="flex h-full min-w-0 items-center gap-1.5 border-r border-[#B9B9B9] px-3 font-semibold text-[#B42318] hover:bg-[#F3DAD8]"
          role="status"
          aria-live="polite"
          aria-label={`Front Office attention required. ${error}. Select to retry.`}
          title={`${error} Select to retry.`}
        >
          <AlertTriangle className="h-3.5 w-3.5 shrink-0 animate-pulse" aria-hidden="true" />
          <i className="h-2 w-2 shrink-0 animate-pulse rounded-full bg-[#D92D20]" aria-hidden="true" />
          <span className="hidden truncate sm:inline">Attention</span>
        </button>
      )}
      <span className="hidden h-full items-center border-r border-[#B9B9B9] px-3 md:flex"><SyncStatusBadge /></span>
      <span className="ml-auto flex h-full items-center border-l border-[#B9B9B9] px-3">{dayLabel}&nbsp;&nbsp; {timeLabel}</span>
    </footer>
    <CreateSaleModal open={selling} onClose={() => setSelling(false)} configuredLayout={configuredSalesLayout} />
    <ReceiptRegisterModal open={receiptsOpen} onClose={() => setReceiptsOpen(false)} />
    <QuotationDeskModal open={quotationDeskOpen} onClose={() => setQuotationDeskOpen(false)} />
    <CurrentTerminalPrinterModal
      open={printerSetupOpen}
      onClose={() => setPrinterSetupOpen(false)}
      canManagePrinters={printerManagementAllowed}
    />
    <CurrentTerminalModal open={terminalOpen} onClose={() => setTerminalOpen(false)} company={company} />
    <FiscalDevicePlaceholderModal open={fiscalDeviceOpen} onClose={() => setFiscalDeviceOpen(false)} />
    <EnterpriseCashupModal open={cashupOpen} onClose={() => setCashupOpen(false)} closeShiftDefault />
    {dayEndAllowed && (
      <DayEndRunModal
        open={dayEndOpen}
        tradingDay={dayEndReadiness}
        activeTradingDate={dayEndTradingDate}
        systemDate={dayEndReadiness?.system_date || systemDate}
        branchName={branchName}
        branchId={branch?.id || dayEndReadiness?.branch_id}
        canRun={canRunDayEnd}
        loadingDate={tradingDayLoading || dayEndPreflightLoading}
        dateError={dayEndPreflightError || tradingDayError}
        run={dayEndRun}
        onCancel={closeDayEnd}
        onConfirm={runDayEnd}
        onConfirmCatchUp={runDayEndCatchUp}
      />
    )}
  </div>;
}

const DayEndRunModal = ({
  open,
  tradingDay,
  activeTradingDate,
  systemDate,
  branchName,
  branchId,
  canRun,
  loadingDate,
  dateError,
  run,
  onCancel,
  onConfirm,
  onConfirmCatchUp,
}) => {
  const [catchUpConfirmOpen, setCatchUpConfirmOpen] = useState(false);
  const [catchUpReason, setCatchUpReason] = useState("");
  const [catchUpConfirmation, setCatchUpConfirmation] = useState("");
  if (!open) return null;

  const isRunning = run.status === "running";
  const isComplete = run.status === "complete";
  const isFailed = run.status === "failed";
  const isCatchUpOperation =
    run.operationType === "catch_up" || run.result?.operation_type === "catch_up";
  const closedTradingDate =
    run.result?.closed_trading_day?.trading_date ||
    run.result?.closed_trading_day ||
    activeTradingDate;
  const nextTradingDate =
    run.result?.next_trading_day?.trading_date ||
    run.result?.next_trading_day ||
    null;
  const operationalReadiness = tradingDay?.operational_readiness || {};
  const frontStockBlockers = Array.isArray(tradingDay?.blocking_front_stock_closings)
    ? tradingDay.blocking_front_stock_closings
    : [];
  const openShiftBlockers = Array.isArray(
    operationalReadiness?.blocking_open_shifts || tradingDay?.blocking_open_shifts,
  )
    ? operationalReadiness?.blocking_open_shifts || tradingDay?.blocking_open_shifts
    : [];
  const uncashedSaleCount = Number(
    operationalReadiness?.uncashed_sale_count ?? tradingDay?.uncashed_sale_count ?? 0,
  );
  const readinessReasons = Array.isArray(operationalReadiness?.blocking_reasons)
    ? operationalReadiness.blocking_reasons.filter(Boolean)
    : [];
  const catchUp = resolveCatchUp(tradingDay);
  const canCatchUp = Boolean(
    catchUp.available &&
      !loadingDate &&
      !dateError &&
      frontStockBlockers.length === 0 &&
      openShiftBlockers.length === 0 &&
      uncashedSaleCount === 0 &&
      operationalReadiness?.can_run_day_end !== false,
  );
  const blockedMessage =
    dateError ||
    readinessReasons[0] ||
    tradingDay?.enterprise_message ||
    tradingDay?.block_reason ||
    tradingDay?.sale_block_reason ||
    (tradingDay?.status === "awaiting_next_system_date"
      ? "Today is already closed. The next trading day will open when the next system date arrives."
      : "Day End is not available for this shop right now.");

  return (
    <div
      className="fixed inset-0 z-[100000] flex items-center justify-center bg-[#003637]/65 p-4 font-sans"
      onMouseDown={() => !isRunning && onCancel()}
    >
      {catchUpConfirmOpen && (
        <div className="absolute inset-0 z-10 flex items-center justify-center bg-[#003637]/70 p-4">
          <section
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="foh-catch-up-title"
            className="sx-large-modal-window border border-[#B86A12] bg-white shadow-2xl"
            onMouseDown={(event) => event.stopPropagation()}
          >
            <header className="border-b border-[#E8C897] bg-[#FFF7E8] px-5 py-4">
              <h3 id="foh-catch-up-title" className="text-[16px] font-semibold text-[#6F4208]">
                Catch this shop up to today?
              </h3>
              <p className="mt-1 text-[11px] text-[#846231]">
                Audited recovery for {branchName || branchId || "the selected shop"} only
              </p>
            </header>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5 text-[12px] leading-5 text-[#255858]">
              <div className="flex gap-2 rounded border border-[#E9B96F] bg-[#FFF8EA] p-3 text-[#704C0D]">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                  This will open the current system date ({readableDate(catchUp.targetSystemDate)}). Accumulated sales will stay recorded on their authoritative lagging trading date ({readableDate(catchUp.sourceTradingDate)}); they will not be moved or rewritten.
                </span>
              </div>
              <div className="grid gap-2 sm:grid-cols-2">
                <DateCard label="Lagging Trading Date" value={readableDate(catchUp.sourceTradingDate)} compact />
                <DateCard label="Target System Date" value={readableDate(catchUp.targetSystemDate)} compact />
              </div>
              <label className="block">
                <span className="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#566868]">
                  Audit reason {catchUp.requiresReason ? "(required)" : ""}
                </span>
                <textarea
                  value={catchUpReason}
                  onChange={(event) => setCatchUpReason(event.target.value)}
                  rows={3}
                  placeholder="Explain why this shop must catch up to the current system date."
                  className="w-full resize-none rounded border border-[#B2C1C0] px-3 py-2 text-[12px] outline-none focus:border-[#067C7C] focus:ring-1 focus:ring-[#067C7C]"
                />
              </label>
              <label className="block">
                <span className="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#566868]">
                  Type CATCH UP to confirm
                </span>
                <input
                  type="text"
                  value={catchUpConfirmation}
                  onChange={(event) => setCatchUpConfirmation(event.target.value)}
                  autoComplete="off"
                  placeholder="CATCH UP"
                  className="h-10 w-full rounded border border-[#B2C1C0] px-3 text-[12px] font-semibold uppercase outline-none focus:border-[#067C7C] focus:ring-1 focus:ring-[#067C7C]"
                />
              </label>
            </div>
            <footer className="flex justify-end gap-2 border-t border-[#D7E0E0] bg-[#F8FAFC] px-5 py-4">
              <button
                type="button"
                onClick={() => setCatchUpConfirmOpen(false)}
                className="h-9 rounded border border-[#A1B0AF] bg-white px-4 text-[11px] font-semibold text-[#3F4E4D]"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={
                  !String(catchUpReason).trim() ||
                  String(catchUpConfirmation).trim().toUpperCase() !== "CATCH UP"
                }
                onClick={() => {
                  const reason = String(catchUpReason).trim();
                  if (!reason) return;
                  setCatchUpConfirmOpen(false);
                  onConfirmCatchUp(reason);
                }}
                className="h-9 rounded border border-[#9B5709] bg-[#B86A12] px-5 text-[11px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-45"
              >
                Confirm Catch Up
              </button>
            </footer>
          </section>
        </div>
      )}
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="foh-day-end-title"
        className="sx-large-modal-window border border-[#006161] bg-white shadow-2xl"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="flex items-start gap-3 border-b border-[#CAD8D7] bg-gradient-to-r from-[#067C7C] to-[#006565] px-5 py-4 text-white">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/15">
            {isComplete ? (
              <CheckCircle2 className="h-6 w-6" />
            ) : isFailed ? (
              <AlertTriangle className="h-6 w-6" />
            ) : (
              <CalendarCheck2 className="h-6 w-6" />
            )}
          </span>
          <div className="min-w-0 flex-1">
            <h2 id="foh-day-end-title" className="text-[17px] font-semibold">
              {isRunning
                ? isCatchUpOperation
                  ? "Trading date catch-up in progress"
                  : "Hospitality Day End in progress"
                : isComplete
                  ? isCatchUpOperation
                    ? "Trading date catch-up completed"
                    : "Hospitality Day End completed"
                  : isFailed
                    ? isCatchUpOperation
                      ? "Trading date catch-up failed"
                      : "Hospitality Day End failed"
                    : "Confirm Hospitality Day End"}
            </h2>
            <p className="mt-1 text-[11px] text-white/80">
              This operation is shop-scoped and does not require a registered till.
            </p>
          </div>
          <button
            type="button"
            aria-label="Close Day End"
            onClick={onCancel}
            disabled={isRunning}
            className="flex h-8 w-8 items-center justify-center rounded border border-white/30 bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <X className="h-4 w-4" />
          </button>
        </header>

        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
          <div className="grid gap-3 sm:grid-cols-3">
            <DateCard label="Shop" value={branchName || branchId || "Selected shop"} />
            <DateCard label="Authoritative Trading Date" value={readableDate(activeTradingDate)} />
            <DateCard label="Current System Date" value={readableDate(systemDate)} />
          </div>

          {run.status === "confirm" && (
            <>
              <div className="rounded border border-[#BEDAD9] bg-[#F1F7F7] p-4 text-[12px] leading-5 text-[#1A5151]">
                Day End will validate readiness, prevent new sales while it runs, reconcile the current Hospitality trading day, close it, and open the next eligible trading day.
              </div>
              {loadingDate ? (
                <div className="flex items-center gap-3 rounded border border-[#CCD6D6] bg-[#F8FAFC] p-4 text-[12px] font-semibold text-[#566868]">
                  <RefreshCw className="h-4 w-4 animate-spin text-[#067C7C]" />
                  Checking this shop&apos;s shifts, cashups and front-stock closing…
                </div>
              ) : (
                <section aria-label="Shop Day End readiness" className="space-y-3">
                  <div className="grid gap-2 sm:grid-cols-3">
                    <ReadinessCard
                      label="Front Stock"
                      value={frontStockBlockers.length === 0 ? "Ready" : `${frontStockBlockers.length} day(s) open`}
                      blocked={frontStockBlockers.length > 0}
                    />
                    <ReadinessCard
                      label="Open Shifts"
                      value={openShiftBlockers.length === 0 ? "Ready" : `${openShiftBlockers.length} shift(s)`}
                      blocked={openShiftBlockers.length > 0}
                    />
                    <ReadinessCard
                      label="Uncashed Sales"
                      value={uncashedSaleCount === 0 ? "Ready" : `${uncashedSaleCount} transaction(s)`}
                      blocked={uncashedSaleCount > 0}
                    />
                  </div>

                  {frontStockBlockers.length > 0 && (
                    <ReadinessNotice title="Front-stock closing required">
                      Close the outstanding front-stock day(s) before Day End: {frontStockBlockers
                        .map((row) => row?.date || row?.trading_date)
                        .filter(Boolean)
                        .join(", ") || "see the Back Office Day End guard"}.
                    </ReadinessNotice>
                  )}

                  {openShiftBlockers.length > 0 && (
                    <ReadinessNotice title="Close shop shifts">
                      {openShiftBlockers.map((shift, index) => (
                        <span key={shift?.id || shift?.shift_session_id || index} className="block">
                          {shift?.shift_name || shift?.name || `Shift ${shift?.id || index + 1}`}
                          {shift?.user_name || shift?.operator_name
                            ? ` · ${shift?.user_name || shift?.operator_name}`
                            : ""}
                        </span>
                      ))}
                    </ReadinessNotice>
                  )}

                  {uncashedSaleCount > 0 && (
                    <ReadinessNotice title="Cashup required">
                      {uncashedSaleCount} completed transaction(s) for this shop and trading date are not covered by a completed cashup.
                    </ReadinessNotice>
                  )}

                  {readinessReasons.length > 0 &&
                    frontStockBlockers.length === 0 &&
                    openShiftBlockers.length === 0 &&
                    uncashedSaleCount === 0 && (
                      <ReadinessNotice title="Day End controls">
                        {readinessReasons.join(" ")}
                      </ReadinessNotice>
                    )}
                </section>
              )}
              {!canRun && !loadingDate && (
                <div className="flex gap-2 rounded border border-[#F5C2C0] bg-[#FFF2F1] p-3 text-[12px] text-[#9F241E]">
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                  <span>{blockedMessage}</span>
                </div>
              )}
            </>
          )}

          {run.status !== "confirm" && (
            <div className="rounded border border-[#BBC8C7] bg-[#F7FAFC] p-4">
              <div className="mb-2 flex items-center justify-between gap-3 text-[11px] font-semibold text-[#255858]">
                <span>{run.stageLabel}</span>
                <span>{Math.round(run.percent)}%</span>
              </div>
              <div
                role="progressbar"
                aria-label="Day End progress"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow={Math.round(run.percent)}
                className="relative h-4 overflow-hidden rounded-sm border border-[#93A7A7] bg-white"
              >
                <div
                  className={`absolute inset-y-0 right-0 transition-[width] duration-500 ${
                    isFailed ? "bg-[#D6453D]" : isComplete ? "bg-[#168A62]" : "bg-[#067C7C]"
                  }`}
                  style={{ width: `${Math.max(0, Math.min(100, run.percent))}%` }}
                />
              </div>
              <div className="mt-3 min-h-[20px] text-[12px] leading-5 text-[#566868]">
                {run.message || (isRunning ? "Please keep this window open while Day End is processed." : "")}
              </div>
              {isComplete && (
                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                  <DateCard label="Closed Trading Date" value={readableDate(closedTradingDate)} compact />
                  <DateCard
                    label="Next Trading Date"
                    value={nextTradingDate ? readableDate(nextTradingDate) : "Opens on the next system date"}
                    compact
                  />
                </div>
              )}
            </div>
          )}
        </div>

        <footer className="flex items-center justify-end gap-2 border-t border-[#D7E0E0] bg-[#F8FAFC] px-5 py-4">
          {run.status === "confirm" ? (
            <>
              <button type="button" onClick={onCancel} className="h-9 rounded border border-[#A1B0AF] bg-white px-4 text-[11px] font-semibold text-[#3F4E4D]">
                Cancel
              </button>
              {catchUp.available && (
                <button
                  type="button"
                  onClick={() => {
                    setCatchUpReason("");
                    setCatchUpConfirmation("");
                    setCatchUpConfirmOpen(true);
                  }}
                  disabled={!canCatchUp}
                  className="h-9 rounded border border-[#B86A12] bg-white px-4 text-[11px] font-semibold text-[#8A4D08] disabled:cursor-not-allowed disabled:opacity-45"
                >
                  Catch Up to Today{catchUp.lagDays > 0 ? ` (${catchUp.lagDays} days)` : ""}
                </button>
              )}
              <button
                type="button"
                onClick={onConfirm}
                disabled={!canRun || loadingDate}
                className="h-9 rounded border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-45"
              >
                {loadingDate ? "Checking date…" : "Run Day End"}
              </button>
            </>
          ) : isRunning ? (
            <span className="text-[11px] font-semibold text-[#566868]">
              {isCatchUpOperation ? "Trading date catch-up is processing…" : "Day End is processing…"}
            </span>
          ) : (
            <>
              {isFailed && canRun && !isCatchUpOperation && (
                <button type="button" onClick={onConfirm} className="h-9 rounded border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white">
                  Try Again
                </button>
              )}
              <button type="button" onClick={onCancel} className="h-9 rounded border border-[#A1B0AF] bg-white px-5 text-[11px] font-semibold text-[#3F4E4D]">
                Close
              </button>
            </>
          )}
        </footer>
      </section>
    </div>
  );
};

const DateCard = ({ label, value, compact = false }) => (
  <div className={`rounded border border-[#CCD6D6] bg-white ${compact ? "p-2.5" : "p-3"}`}>
    <div className="text-[9px] font-bold uppercase tracking-wide text-[#6F7E7D]">{label}</div>
    <div className={`${compact ? "mt-0.5 text-[11px]" : "mt-1 text-[13px]"} font-semibold text-[#054747]`}>{value}</div>
  </div>
);

const ReadinessCard = ({ label, value, blocked = false }) => (
  <div className={`rounded border p-3 ${blocked ? "border-[#E9AAA6] bg-[#FFF4F3]" : "border-[#A9D8C5] bg-[#F0FAF6]"}`}>
    <div className="text-[9px] font-bold uppercase tracking-wide text-[#647574]">{label}</div>
    <div className={`mt-1 text-[12px] font-semibold ${blocked ? "text-[#A52B24]" : "text-[#117556]"}`}>{value}</div>
  </div>
);

const ReadinessNotice = ({ title, children }) => (
  <div className="flex gap-2 rounded border border-[#F0BE78] bg-[#FFF8EA] p-3 text-[11px] leading-5 text-[#704C0D]">
    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
    <div>
      <div className="font-semibold">{title}</div>
      <div>{children}</div>
    </div>
  </div>
);

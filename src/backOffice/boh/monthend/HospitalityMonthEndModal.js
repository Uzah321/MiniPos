// src/backOffice/boh/monthend/HospitalityMonthEndModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import {
  downloadHospitalityMonthEndReport,
  getHospitalityMonthEndLivePreview,
  getHospitalityMonthEndPreflight,
  getHospitalityMonthEndReport,
  getHospitalityMonthEndReports,
  getHospitalityMonthEndProgress,
  runHospitalityMonthEnd,
} from "../../../services/HospitalityMonthEndService";

const today = () => new Date().toISOString().slice(0, 10);
const lastDayOfMonth = (date) => {
  const value = date instanceof Date ? date : new Date(`${String(date || today()).slice(0, 10)}T00:00:00`);
  return new Date(value.getFullYear(), value.getMonth() + 1, 0).toISOString().slice(0, 10);
};

const defaultMonthEnd = () => {
  const now = new Date();
  if (now.getDate() === 1) {
    return new Date(now.getFullYear(), now.getMonth(), 0).toISOString().slice(0, 10);
  }
  return lastDayOfMonth(now);
};

const normalizeMonthEndInput = (value) => {
  const picked = new Date(`${String(value || today()).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(picked.getTime())) return defaultMonthEnd();
  if (picked.getDate() === 1) {
    return new Date(picked.getFullYear(), picked.getMonth(), 0).toISOString().slice(0, 10);
  }
  return lastDayOfMonth(picked);
};


const createRunKey = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `month-end-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const formatDate = (value) => {
  if (!value) return "-";
  const raw = String(value).slice(0, 10);
  const parsed = new Date(`${raw}T00:00:00`);
  if (Number.isNaN(parsed.getTime())) return raw;
  return parsed.toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
};

const formatNumber = (value, decimals = 2) => {
  const number = Number(value || 0);
  if (!Number.isFinite(number)) return "0";
  return number.toLocaleString("en-GB", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
};

const parseJson = (value, fallback = value) => {
  if (typeof value !== "string") return value;
  try {
    return JSON.parse(value);
  } catch {
    return fallback;
  }
};

const normaliseArray = (...candidates) => {
  for (const item of candidates) {
    const parsed = parseJson(item, item);
    if (Array.isArray(parsed)) return parsed;
  }
  return [];
};

const unwrapData = (payload) => {
  const data = payload?.data || payload || {};
  return parseJson(data, data);
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.code === "TRADING_DATE_NOT_TODAY") {
    return responseData.message || "Month End is blocked. Run Day End first until the trading date is today.";
  }

  if (responseData?.message) return responseData.message;
  if (responseData?.data?.message) return responseData.data.message;
  if (responseData?.data?.enterprise_message) return responseData.data.enterprise_message;
  if (source?.message) return source.message;

  return fallback;
};

const HospitalityMonthEndModal = ({ open, onClose, currentUser = null }) => {
  const [monthEndDate, setMonthEndDate] = useState(defaultMonthEnd());
  const [preflight, setPreflight] = useState(null);
  const [preview, setPreview] = useState(null);
  const [reports, setReports] = useState([]);
  const [selectedReport, setSelectedReport] = useState(null);
  const [activeTab, setActiveTab] = useState("preflight");
  const [notes, setNotes] = useState("");
  const [loadingPreflight, setLoadingPreflight] = useState(false);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [loadingReports, setLoadingReports] = useState(false);
  const [running, setRunning] = useState(false);
  const [runStageIndex, setRunStageIndex] = useState(0);
  const [runStages, setRunStages] = useState([]);
  const [liveProgress, setLiveProgress] = useState(null);
  const [confirmRunOpen, setConfirmRunOpen] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const showMessage = ({ type = "success", title = "", message = "" }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const loadPreflight = async () => {
    try {
      setLoadingPreflight(true);
      const response = await getHospitalityMonthEndPreflight({ month_end_date: monthEndDate });
      setPreflight(unwrapData(response));
    } catch (error) {
      const payload = error?.response?.data?.data || error?.response?.data || null;
      setPreflight(payload);
      showMessage({
        type: "error",
        title: "Preflight Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingPreflight(false);
    }
  };

  const loadPreview = async () => {
    try {
      setLoadingPreview(true);
      const response = await getHospitalityMonthEndLivePreview({ month_end_date: monthEndDate });
      setPreview(unwrapData(response));
    } catch (error) {
      setPreview(null);
      showMessage({
        type: "error",
        title: "Preview Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingPreview(false);
    }
  };

  const loadReports = async () => {
    try {
      setLoadingReports(true);
      const response = await getHospitalityMonthEndReports({ per_page: 25 });
      const data = unwrapData(response);
      const rows = normaliseArray(data, data.data, data.rows, response?.data?.data, response?.data?.rows);
      setReports(rows);
    } catch (error) {
      setReports([]);
      showMessage({
        type: "error",
        title: "Reports Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingReports(false);
    }
  };

  const refreshAll = async () => {
    await Promise.allSettled([loadPreflight(), loadPreview(), loadReports()]);
  };

  useEffect(() => {
    if (!open) return;
    setMonthEndDate(defaultMonthEnd());
    setNotes("");
    setActiveTab("preflight");
    setPreflight(null);
    setPreview(null);
    setSelectedReport(null);
  }, [open]);

  useEffect(() => {
    if (!open || !monthEndDate) return;
    refreshAll();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, monthEndDate]);


  useEffect(() => {
    if (!running || runStages.length === 0) return undefined;

    setRunStageIndex(0);
    const timer = window.setInterval(() => {
      setRunStageIndex((prev) => Math.min(prev + 1, runStages.length - 1));
    }, 900);

    return () => window.clearInterval(timer);
  }, [running, runStages]);

  const payload = useMemo(() => {
    const raw = selectedReport?.report_payload || preview || {};
    return parseJson(raw, raw) || {};
  }, [selectedReport, preview]);

  const currencySummary = useMemo(() => {
    return normaliseArray(
      payload?.sales_currency_summary,
      payload?.sales?.currency_summary,
      payload?.currency_summary,
      selectedReport?.sales_currency_summary,
      preflight?.sales_currency_summary
    );
  }, [payload, selectedReport, preflight]);

  const frontClosings = useMemo(() => {
    return normaliseArray(
      payload?.front_stock_closings,
      payload?.daily_front_stock_closings,
      payload?.front_stock?.daily_closings,
      payload?.front_stock_variances,
      preflight?.front_stock_closing_calendar,
      preflight?.front_stock_closings
    );
  }, [payload, preflight]);

  const backStockLines = useMemo(() => {
    return normaliseArray(
      payload?.back_stock_count?.lines,
      payload?.back_stock_variances,
      payload?.back_stock?.variance_lines,
      payload?.back_stock?.lines,
      selectedReport?.back_stock_variances
    );
  }, [payload, selectedReport]);

  const missingFrontClosings = useMemo(() => {
    return normaliseArray(
      preflight?.missing_front_stock_closings,
      preflight?.blocking_front_stock_closings,
      preflight?.front_stock_missing
    );
  }, [preflight]);

  const checks = useMemo(() => {
    const p = preflight || {};
    const tradingDate = p.active_trading_date || p.trading_date || p.open_trading_day?.trading_date || p.open_trading_day?.business_date;
    const systemDate = p.system_date || p.today || today();
    const tradingDateOk = String(tradingDate || systemDate).slice(0, 10) === String(systemDate).slice(0, 10);

    return [
      {
        label: "Trading Date",
        ok: p.trading_date_is_today ?? p.can_run_month_end_due_to_trading_date ?? tradingDateOk,
        detail: `${formatDate(tradingDate)} / ${formatDate(systemDate)}`,
      },
      {
        label: "Front Closings",
        ok: p.front_stock_closings_complete ?? p.can_run_front_stock ?? missingFrontClosings.length === 0,
        detail: missingFrontClosings.length ? `${missingFrontClosings.length} missing` : "No missing closings",
      },
      {
        label: "Back Count",
        ok: p.back_stock_count_complete ?? p.has_back_stock_month_end_count ?? Boolean(p.back_stock_month_end_count?.id || p.back_stock_count?.id || p.back_stock_month_end_count_id || p.back_stock_count_id),
        detail: p.back_stock_month_end_count?.status || p.back_stock_count?.status || p.back_stock_status || "Required before close",
      },
      {
        label: "Trading Days",
        ok: p.trading_days_closed ?? p.month_trading_days_closed ?? p.can_close_month_trading_days ?? true,
        detail: p.open_trading_days_count ? `${p.open_trading_days_count} open` : "Checked",
      },
    ];
  }, [preflight, missingFrontClosings]);

  const backendStages = useMemo(() => {
    const stages = preflight?.progress_stages || preflight?.month_end_progress_stages || preview?.progress_stages || [];
    return Array.isArray(stages) && stages.length ? stages : [
      { key: 'preflight', label: 'Checking controls' },
      { key: 'sales', label: 'Calculating sales' },
      { key: 'front_stock', label: 'Reading front stock variances' },
      { key: 'back_stock', label: 'Reading back stock count' },
      { key: 'summary', label: 'Building summary' },
      { key: 'save', label: 'Saving report' },
      { key: 'complete', label: 'Completed' },
    ];
  }, [preflight, preview]);

  const canRun = useMemo(() => {
    if (!preflight) return false;

    // Use the same rows that the user sees in the Readiness panel.
    // Previously the button also re-read backend blocking_items, so the UI could show
    // OK / OK / OK / OK while the Close Month button stayed dimmed.
    return checks.every((item) => Boolean(item.ok));
  }, [preflight, checks]);

  const closeButtonDisabled = running || loadingPreflight || !preflight;
  const closeButtonTitle = !preflight
    ? "Refresh readiness first."
    : loadingPreflight
      ? "Checking readiness."
      : canRun
        ? "Ready to close month."
        : "Readiness has a hold, but you can still click after refresh and the backend will show the exact hold.";

  const totals = useMemo(() => {
    const p = payload || {};
    const salesTotal =
      p.sales_total ??
      p.sales?.total ??
      p.sales_summary?.total_amount ??
      selectedReport?.sales_total ??
      currencySummary.reduce((sum, row) => sum + Number(row.total || row.amount || row.sales_total || row.base_total || 0), 0);

    const frontVariance =
      p.front_stock_variance_total_value ??
      p.front_stock?.variance_total_value ??
      selectedReport?.front_stock_variance_total_value ??
      frontClosings.reduce((sum, row) => sum + Number(row.variance_total_value || row.variance_value || 0), 0);

    const backVariance =
      p.back_stock_variance_total_value ??
      p.back_stock?.variance_total_value ??
      selectedReport?.back_stock_variance_total_value ??
      backStockLines.reduce((sum, row) => sum + Number(row.variance_value || row.variance_total_value || 0), 0);

    return { salesTotal, frontVariance, backVariance };
  }, [payload, selectedReport, currencySummary, frontClosings, backStockLines]);

  const pollMonthEndProgress = async (runKey) => {
    try {
      const response = await getHospitalityMonthEndProgress(runKey);
      const data = unwrapData(response);
      if (data?.run_key) {
        setLiveProgress(data);
        if (Array.isArray(data.stages)) setRunStages(data.stages);
      }
      return data;
    } catch {
      return null;
    }
  };

  const handleRunMonthEnd = () => {
    setConfirmRunOpen(true);
  };

  const executeRunMonthEnd = async () => {
    setConfirmRunOpen(false);
    let progressTimer = null;
    try {
      const runKey = createRunKey();
      setRunStages(backendStages);
      setRunStageIndex(0);
      setLiveProgress({ run_key: runKey, status: "running", stage_key: "queued", stage_label: "Preparing Month End close", percent: 3, stages: backendStages });
      setRunning(true);

      progressTimer = window.setInterval(() => {
        pollMonthEndProgress(runKey);
      }, 550);

      const response = await runHospitalityMonthEnd({
        month_end_date: monthEndDate,
        notes: notes || null,
        run_key: runKey,
      });

      if (progressTimer) window.clearInterval(progressTimer);
      await pollMonthEndProgress(runKey);
      const data = unwrapData(response);
      if (Array.isArray(data?.progress_stages)) {
        setRunStages(data.progress_stages);
        setRunStageIndex(Math.max(data.progress_stages.length - 1, 0));
      }
      showMessage({
        type: "success",
        title: "Month End Completed",
        message: response?.message || "Hospitality Month End report generated successfully.",
      });
      setSelectedReport(data?.id ? { ...data, report_payload: data.report || data.report_payload || {} } : (data?.report || data || null));
      setActiveTab("report");
      await refreshAll();
    } catch (error) {
      if (progressTimer) window.clearInterval(progressTimer);
      setLiveProgress((prev) => prev ? { ...prev, status: "error", stage_label: "Month End hold", percent: 100 } : prev);
      showMessage({
        type: "error",
        title: "Month End HOLD",
        message: getApiMessage(error),
      });
      await loadPreflight();
    } finally {
      setRunning(false);
      window.setTimeout(() => setLiveProgress(null), 900);
    }
  };

  const openReport = async (report) => {
    if (!report?.id) {
      setSelectedReport(report);
      setActiveTab("report");
      return;
    }

    try {
      setLoadingReports(true);
      const response = await getHospitalityMonthEndReport(report.id);
      setSelectedReport(unwrapData(response));
      setActiveTab("report");
    } catch (error) {
      showMessage({
        type: "error",
        title: "Report Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingReports(false);
    }
  };

  const handleDownload = async () => {
    const id = selectedReport?.id || preview?.id;
    if (!id) {
      showMessage({
        type: "error",
        title: "Report Not Saved",
        message: "Close Month or open a saved Month End report before downloading.",
      });
      return;
    }

    try {
      setDownloading(true);
      await downloadHospitalityMonthEndReport(id, "csv");
    } catch (error) {
      showMessage({
        type: "error",
        title: "Download Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDownloading(false);
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="MONTH END"
        subtitle="Month-end close"
      >
        <div className="hme-shell relative flex h-full min-h-0 flex-col bg-white">
          {running && <OperationProgress stages={runStages} activeIndex={runStageIndex} progress={liveProgress} />}
          <div className="hme-topbar flex h-14 shrink-0 items-center justify-between px-4">
            <div className="flex items-center gap-2">
              <button type="button" onClick={refreshAll} disabled={loadingPreflight || loadingPreview} className="hme-btn-secondary">
                {loadingPreflight || loadingPreview ? "Checking" : "Refresh"}
              </button>
              <button
                type="button"
                onClick={handleRunMonthEnd}
                disabled={closeButtonDisabled}
                title={closeButtonTitle}
                className="hme-btn-primary"
              >
                {running ? "Running" : loadingPreflight ? "Checking" : "Close Month"}
              </button>
              <button type="button" onClick={handleDownload} disabled={downloading || !(selectedReport?.id || preview?.id)} className="hme-btn-secondary">
                {downloading ? "Downloading" : "CSV"}
              </button>
            </div>

            <div className="flex items-center gap-2">
              <label className="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-[#324444]">
                Date
                <input type="date" value={monthEndDate} onChange={(event) => setMonthEndDate(normalizeMonthEndInput(event.target.value))} className="hme-input w-[150px]" />
              </label>
            </div>
          </div>

          <div className="grid min-h-0 flex-1 grid-cols-[220px_minmax(0,1fr)_280px] bg-[#F4F7F7]">
            <aside className="min-h-0 border-r border-[#CAD5D5] bg-white">
              <div className="hme-side-head px-4 py-3">
                <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#324444]">Control</div>
              </div>

              <div className="p-3">
                <TabButton active={activeTab === "preflight"} onClick={() => setActiveTab("preflight")} label="Checks" badge={canRun ? "Ready" : "HOLD"} />
                <TabButton active={activeTab === "preview"} onClick={() => setActiveTab("preview")} label="Preview" />
                <TabButton active={activeTab === "report"} onClick={() => setActiveTab("report")} label="Report" />
                <TabButton active={activeTab === "history"} onClick={() => setActiveTab("history")} label="Archive" badge={reports.length} />

                <label className="mt-4 block">
                  <div className="mb-1 text-[11px] font-medium uppercase tracking-wide text-[#324444]">Notes</div>
                  <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={5} className="hme-textarea" />
                </label>
              </div>
            </aside>

            <div className="min-h-0 overflow-auto p-4">
              {activeTab === "preflight" && (
                <PreflightPanel checks={checks} preflight={preflight} missingFrontClosings={missingFrontClosings} loading={loadingPreflight} />
              )}

              {activeTab === "preview" && (
                <ReportPanel
                  loading={loadingPreview}
                  title="Preview"
                  totals={totals}
                  currencySummary={currencySummary}
                  frontClosings={frontClosings}
                  backStockLines={backStockLines}
                />
              )}

              {activeTab === "report" && (
                <ReportPanel
                  loading={loadingReports}
                  title={selectedReport?.id ? "Saved Report" : "Report"}
                  totals={totals}
                  currencySummary={currencySummary}
                  frontClosings={frontClosings}
                  backStockLines={backStockLines}
                />
              )}

              {activeTab === "history" && (
                <HistoryPanel reports={reports} loading={loadingReports} onOpen={openReport} />
              )}
            </div>

            <aside className="min-h-0 border-l border-[#CAD5D5] bg-white">
              <div className="hme-side-head px-4 py-3">
                <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#324444]">Summary</div>
              </div>
              <div className="space-y-2 p-3">
                <InfoTile label="Date" value={formatDate(monthEndDate)} />
                <InfoTile label="Sales" value={formatNumber(totals.salesTotal, 2)} />
                <InfoTile label="Front Var" value={formatNumber(totals.frontVariance, 2)} />
                <InfoTile label="Back Var" value={formatNumber(totals.backVariance, 2)} />
                <InfoTile label="Currencies" value={formatNumber(currencySummary.length, 0)} />
                <InfoTile label="Front Days" value={formatNumber(frontClosings.length, 0)} />
                <InfoTile label="Back Lines" value={formatNumber(backStockLines.length, 0)} />
              </div>
            </aside>
          </div>
        </div>

        <EnterpriseInlineStyles />
      </FullScreenModal>

      <MonthEndConfirmModal
        open={confirmRunOpen}
        monthEndDate={monthEndDate}
        canRun={canRun}
        onCancel={() => setConfirmRunOpen(false)}
        onConfirm={executeRunMonthEnd}
      />

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={closeMessageModal} />
    </>
  );
};

const OperationProgress = ({ stages = [], activeIndex = 0, progress = null }) => {
  const safeStages = Array.isArray(progress?.stages) && progress.stages.length
    ? progress.stages
    : (Array.isArray(stages) && stages.length ? stages : [{ label: "Processing", percent: 10 }]);

  const stageKey = progress?.stage_key;
  const stageFromKey = stageKey ? safeStages.findIndex((stage) => stage.key === stageKey) : -1;
  const resolvedIndex = stageFromKey >= 0 ? stageFromKey : Math.min(activeIndex, safeStages.length - 1);
  const active = safeStages[Math.max(0, resolvedIndex)] || safeStages[0];
  const percent = Number.isFinite(Number(progress?.percent))
    ? Number(progress.percent)
    : Math.round(((Math.max(0, resolvedIndex) + 1) / safeStages.length) * 100);
  const status = progress?.status || "running";
  const label = progress?.stage_label || active.label || active.key || "Processing";

  return (
    <div className="hme-progress-panel" aria-live="polite">
      <div className="hme-progress-line">
        <div className="hme-progress-fill" style={{ width: `${Math.max(0, Math.min(100, percent))}%` }} />
      </div>
      <div className="hme-progress-copy">
        <span>{label}</span>
        <span>{status === "complete" ? "Complete" : `${Math.round(percent)}%`}</span>
      </div>
      <div className="hme-stage-strip">
        {safeStages.map((stage, index) => {
          const done = index < resolvedIndex || status === "complete";
          const activeStage = index === resolvedIndex && status !== "complete";
          return (
            <div key={stage.key || stage.label || index} className={`hme-stage-pill ${done ? "hme-stage-done" : ""} ${activeStage ? "hme-stage-active" : ""}`}>
              {stage.label || stage.key || `Stage ${index + 1}`}
            </div>
          );
        })}
      </div>
    </div>
  );
};

const MonthEndConfirmModal = ({ open, monthEndDate, canRun, onCancel, onConfirm }) => {
  if (!open) return null;

  return (
    <div className="hme-confirm-backdrop">
      <div className="hme-confirm-card">
        <div className="hme-confirm-head">
          <div>
            <div className="hme-confirm-kicker">Month End</div>
            <div className="hme-confirm-title">Close month?</div>
          </div>
          <div className="hme-confirm-date">{formatDate(monthEndDate)}</div>
        </div>
        <div className="hme-confirm-body">
          This will generate the hospitality Month End report using sales, daily front stock closings, and the final BOH back-stock count.
        </div>
        {!canRun && (
          <div className="hme-confirm-note">
            Some checks are showing HOLD. You can still continue; the backend will stop the close and show the exact reason if it is not ready.
          </div>
        )}
        <div className="hme-confirm-actions">
          <button type="button" onClick={onCancel} className="hme-btn-secondary">Cancel</button>
          <button type="button" onClick={onConfirm} className="hme-btn-primary">Run Close</button>
        </div>
      </div>
    </div>
  );
};

const TabButton = ({ active, onClick, label, badge = null }) => (
  <button
    type="button"
    onClick={onClick}
    className={`hme-tab ${active ? "hme-tab-active" : ""}`}
  >
    <span>{label}</span>
    {badge !== null && <span className="bg-white px-1.5 py-0.5 text-[9px] text-[#637877]">{badge}</span>}
  </button>
);

const InfoTile = ({ label, value }) => (
  <div className="hme-tile">
    <div className="hme-tile-label">{label}</div>
    <div className="hme-tile-value" title={value}>{value}</div>
  </div>
);

const StatusBadge = ({ ok }) => (
  <span className={`hme-status ${ok ? "hme-status-ok" : "hme-status-bad"}`}>
    {ok ? "OK" : "HOLD"}
  </span>
);

const PreflightPanel = ({ checks, preflight, missingFrontClosings, loading }) => (
  <div className="space-y-3">
    <div className="hme-card bg-white">
      <div className="hme-card-head">
        Readiness
      </div>
      {loading ? (
        <div className="px-3 py-10 text-center text-[12px] text-[#637877]">Checking...</div>
      ) : (
        checks.map((check) => (
          <div key={check.label} className="hme-check-row grid grid-cols-[1fr_120px_70px]">
            <div className="font-semibold text-[#091B1B]">{check.label}</div>
            <div className="text-[#637877]">{check.detail}</div>
            <div className="text-right"><StatusBadge ok={Boolean(check.ok)} /></div>
          </div>
        ))
      )}
    </div>

    {missingFrontClosings.length > 0 && (
      <div className="border border-[#FEDF89] bg-white">
        <div className="hme-card-head hme-card-warn">
          Missing Front Closings
        </div>
        {missingFrontClosings.slice(0, 80).map((row, index) => (
          <div key={row.date || row.closing_date || index} className="grid grid-cols-[150px_1fr_100px] border-b border-[#FFF0C2] px-3 py-2 text-[11px] last:border-b-0">
            <div>{formatDate(row.date || row.closing_date || row.trading_date)}</div>
            <div>{row.message || row.issue || "Daily front stock closing is missing."}</div>
            <div className="text-right text-[#B42318]">Missing</div>
          </div>
        ))}
      </div>
    )}

    {preflight?.message && (
      <div className="hme-card bg-white p-3 text-[12px] font-semibold text-[#324444]">{preflight.message}</div>
    )}
  </div>
);

const ReportPanel = ({ loading, title, totals, currencySummary, frontClosings, backStockLines }) => (
  <div className="space-y-3">
    <div className="grid grid-cols-3 gap-3">
      <InfoTile label="Sales" value={formatNumber(totals.salesTotal, 2)} />
      <InfoTile label="Front Stock Variance" value={formatNumber(totals.frontVariance, 2)} />
      <InfoTile label="Back Stock Variance" value={formatNumber(totals.backVariance, 2)} />
    </div>

    <DataBlock title={`${title} - Currency Sales`} loading={loading} empty="No currency rows.">
      {currencySummary.length > 0 && (
        <>
          <TableHeader columns={["Currency", "Sales", "Payments", "Receipts"]} />
          {currencySummary.slice(0, 80).map((row, index) => (
            <div key={row.currency_code || row.currency || index} className="hme-row sx-enterprise-grid-row grid grid-cols-4 px-3 py-2">
              <div className="sx-enterprise-grid-cell font-medium">{row.currency_code || row.currency || row.money_code || "-"}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.total || row.amount || row.sales_total || row.base_total || 0, 2)}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.payment_total || row.payments || row.money_received || 0, 2)}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.receipt_count || row.transactions || row.count || 0, 0)}</div>
            </div>
          ))}
        </>
      )}
    </DataBlock>

    <DataBlock title="Front Variance" loading={loading} empty="No front rows.">
      {frontClosings.length > 0 && (
        <>
          <TableHeader columns={["Trading Date", "Status", "Lines", "Variance Value"]} />
          {frontClosings.slice(0, 120).map((row, index) => (
            <div key={row.id || row.closing_id || row.date || index} className="hme-row sx-enterprise-grid-row grid grid-cols-4 px-3 py-2">
              <div className="sx-enterprise-grid-cell">{formatDate(row.date || row.closing_date || row.trading_date)}</div>
              <div className="sx-enterprise-grid-cell">{row.status || (row.is_closed ? "closed" : "missing")}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.total_lines || row.lines_count || row.line_count || 0, 0)}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.variance_total_value || row.variance_value || 0, 2)}</div>
            </div>
          ))}
        </>
      )}
    </DataBlock>

    <DataBlock title="Back Variance" loading={loading} empty="No back rows.">
      {backStockLines.length > 0 && (
        <>
          <TableHeader columns={["Product", "Expected", "Counted", "Variance", "Value"]} grid="grid-cols-[minmax(240px,1fr)_110px_110px_110px_110px]" />
          {backStockLines.slice(0, 160).map((row, index) => (
            <div key={row.id || row.product_id || index} className="hme-row sx-enterprise-grid-row grid grid-cols-[minmax(240px,1fr)_110px_110px_110px_110px] px-3 py-2">
              <div className="sx-enterprise-grid-cell truncate font-medium" title={row.product_name || row.name || "Product"}>{row.product_name || row.name || "Product"}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.expected_quantity || 0, 3)}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.counted_quantity || 0, 3)}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.variance_quantity || 0, 3)}</div>
              <div className="sx-enterprise-grid-cell text-right">{formatNumber(row.variance_value || 0, 2)}</div>
            </div>
          ))}
        </>
      )}
    </DataBlock>
  </div>
);

const DataBlock = ({ title, loading, empty, children }) => {
  const hasChildren = Boolean(children);
  return (
    <div className="hme-card bg-white">
      <div className="hme-card-head">{title}</div>
      {loading ? (
        <div className="px-3 py-10 text-center text-[12px] text-[#637877]">Loading...</div>
      ) : hasChildren ? (
        children
      ) : (
        <div className="px-3 py-10 text-center text-[12px] text-[#637877]">{empty}</div>
      )}
    </div>
  );
};

const TableHeader = ({ columns, grid = "grid-cols-4" }) => (
  <div className={`hme-table-head sx-enterprise-grid-head grid ${grid} px-3 py-2`}>
    {columns.map((column, index) => (
      <div key={column} className={`sx-enterprise-grid-heading ${index > 0 ? "text-right" : ""}`}>{column}</div>
    ))}
  </div>
);

const HistoryPanel = ({ reports, loading, onOpen }) => (
  <div className="hme-card bg-white">
    <div className="hme-card-head">Archive</div>
    {loading ? (
      <div className="px-3 py-10 text-center text-[12px] text-[#637877]">Loading...</div>
    ) : reports.length === 0 ? (
      <div className="px-3 py-10 text-center text-[12px] text-[#637877]">No reports found.</div>
    ) : (
      reports.map((report) => (
        <button key={report.id || report.month_end_date} type="button" onClick={() => onOpen(report)} className="hme-row sx-enterprise-grid-row grid w-full grid-cols-[130px_1fr_120px_90px] px-3 py-2 text-left">
          <div className="sx-enterprise-grid-cell font-medium text-[#324444]">{formatDate(report.month_end_date)}</div>
          <div className="sx-enterprise-grid-cell">{formatDate(report.date_from)} - {formatDate(report.date_to)}</div>
          <div className="sx-enterprise-grid-cell text-right">{formatNumber(report.sales_total || 0, 2)}</div>
          <div className="sx-enterprise-grid-cell text-right uppercase text-[#637877]">{report.status || "closed"}</div>
        </button>
      ))
    )}
  </div>
);

const EnterpriseInlineStyles = () => (
  <style>
    {`
      .hme-progress-panel {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        z-index: 40;
        background: rgba(255, 255, 255, 0.96);
        border-bottom: 1px solid #D7DFDF;
      }

      .hme-progress-line {
        height: 4px;
        background: #E5EBEB;
        overflow: hidden;
      }

      .hme-progress-fill {
        height: 100%;
        background: #AFBEBE;
        transition: width 420ms ease;
      }

      .hme-progress-copy {
        display: flex;
        justify-content: space-between;
        padding: 6px 12px;
        font-size: 10px;
        font-weight: 500;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #465858;
      }

      .hme-shell {
        border: 1px solid #D3DBDB;
        box-shadow: inset 0 3px 0 #D7DFDF;
      }

      .hme-topbar {
        background: #F8FAFC;
        border-bottom: 1px solid #D7DFDF;
        color: #324444;
      }

      .hme-side-head {
        background: #F8FAFC;
        border-bottom: 1px solid #D7DFDF;
      }

      .hme-card {
        border: 1px solid #D3DBDB;
        box-shadow: 0 12px 28px rgba(9, 27, 27, 0.06);
      }

      .hme-card-head {
        background: #F8FAFC;
        border-bottom: 1px solid #D7DFDF;
        color: #324444;
        padding: 8px 12px;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.1em;
      }

      .hme-card-warn {
        background: #FFF8E6;
        border-bottom-color: #F4C542;
        color: #8A5A00;
      }

      .hme-table-head {
        background: #F8FAFC;
        border-bottom: 1px solid #D7DFDF;
        color: #637877;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .hme-row,
      .hme-check-row {
        border-bottom: 1px solid #EFF2F2;
        font-size: 11px;
        color: #1E2B2B;
      }

      .hme-row:hover,
      .hme-check-row:hover {
        background: #F8FAFC;
      }

      .hme-tab {
        margin-bottom: 8px;
        display: flex;
        height: 40px;
        width: 100%;
        align-items: center;
        justify-content: space-between;
        border: 1px solid #D3DBDB;
        background: #ffffff;
        padding: 0 12px;
        text-align: left;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #324444;
      }

      .hme-tab-active {
        background: #F1F5F5;
        border-color: #AFBEBE;
        color: #324444;
        box-shadow: inset 3px 0 0 #AFBEBE;
      }

      .hme-status {
        display: inline-block;
        min-width: 48px;
        padding: 4px 8px;
        text-align: center;
        font-size: 9px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .hme-status-ok {
        background: #F1F5F5;
        color: #465858;
      }

      .hme-status-bad {
        background: #FEF3F2;
        color: #B42318;
      }

      .hme-tile {
        background: #ffffff;
        border: 1px solid #D3DBDB;
        border-left: 3px solid #C2CCCC;
        padding: 10px;
        box-shadow: 0 10px 22px rgba(9, 27, 27, 0.045);
      }

      .hme-tile-label {
        font-size: 9px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #637877;
      }

      .hme-tile-value {
        margin-top: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 16px;
        font-weight: 500;
        color: #324444;
      }

      .hme-input,
      .hme-textarea {
        border: 1px solid #C2CCCC;
        background: #ffffff;
        font-size: 12px;
        outline: none;
      }

      .hme-input {
        height: 34px;
        padding: 0 8px;
      }

      .hme-textarea {
        width: 100%;
        padding: 8px;
        resize: vertical;
      }

      .hme-input:focus,
      .hme-textarea:focus {
        border-color: #93A6A6;
        box-shadow: 0 0 0 2px rgba(147, 166, 166, 0.18);
      }

      .hme-btn-primary,
      .hme-btn-secondary {
        min-height: 34px;
        padding: 0 14px;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.07em;
      }

      .hme-btn-primary {
        border: 1px solid #AFBEBE;
        background: #EEF2F2;
        color: #324444;
      }

      .hme-btn-secondary {
        border: 1px solid #C2CCCC;
        background: #ffffff;
        color: #324444;
      }

      .hme-btn-primary:hover:not(:disabled),
      .hme-btn-secondary:hover:not(:disabled) {
        background: #F8FAFC;
        border-color: #93A6A6;
      }

      .hme-btn-primary:disabled,
      .hme-btn-secondary:disabled {
        opacity: 0.55;
      }


      .hme-stage-strip {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 6px;
        padding: 0 12px 10px;
      }

      .hme-stage-pill {
        border: 1px solid #D7DFDF;
        background: #FFFFFF;
        padding: 6px 8px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 9px;
        font-weight: 500;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #637877;
      }

      .hme-stage-active {
        border-color: #93A6A6;
        background: #F8FAFC;
        color: #324444;
        box-shadow: inset 3px 0 0 #93A6A6;
      }

      .hme-stage-done {
        background: #F1F5F5;
        color: #465858;
      }

      .hme-confirm-backdrop {
        position: fixed;
        inset: 0;
        z-index: 100004;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(0, 52, 52, 0.42);
        padding: 18px;
      }

      .hme-confirm-card {
        width: min(520px, 100%);
        border: 1px solid #C2CCCC;
        background: #FFFFFF;
        box-shadow: 0 24px 70px rgba(9, 27, 27, 0.22);
      }

      .hme-confirm-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 1px solid #D7DFDF;
        background: #F8FAFC;
        padding: 16px;
      }

      .hme-confirm-kicker {
        font-size: 9px;
        font-weight: 500;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #637877;
      }

      .hme-confirm-title {
        margin-top: 4px;
        font-size: 18px;
        font-weight: 500;
        color: #324444;
      }

      .hme-confirm-date {
        border: 1px solid #D7DFDF;
        background: #FFFFFF;
        padding: 8px 10px;
        font-size: 11px;
        font-weight: 500;
        color: #465858;
      }

      .hme-confirm-body {
        padding: 16px;
        font-size: 13px;
        line-height: 1.6;
        color: #324444;
      }

      .hme-confirm-note {
        margin: 0 16px 16px;
        border: 1px solid #FEDF89;
        background: #FFFAEB;
        padding: 10px;
        font-size: 11px;
        color: #92400E;
      }

      .hme-confirm-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        border-top: 1px solid #D7DFDF;
        background: #F8FAFC;
        padding: 14px 16px;
      }

      button,
      input,
      select,
      textarea {
        border-radius: 0 !important;
      }
    `}
  </style>
)


export default HospitalityMonthEndModal;

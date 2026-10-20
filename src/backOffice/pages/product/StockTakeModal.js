// src/backOffice/pages/products/StockTakeModal.jsx

import React, { useEffect, useMemo, useRef, useState } from "react";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import {
  approveStockTake,
  beginTradingStockTake,
  downloadStockTakeBalanceSnapshot,
  downloadStockTakeCountSheet,
  downloadTradingCountSheet,
  exportStockTakeReport,
  exportStockTakeVariance,
  getStockTake,
  getStockTakeAuditReport,
  getStockTakeBranchVarianceReport,
  getStockTakeCategoryVarianceReport,
  getStockTakeDashboard,
  getStockTakeVarianceReport,
  getStockTakes,
  postStockTakeAdjustments,
  uploadStockTakeCountSheet,
  uploadTradingCountSheets,
} from "../../../services/StockTakeService";

const today = () => new Date().toISOString().slice(0, 10);

const money = (value, currency = "") => {
  const amount = Number(value || 0).toFixed(2);
  return currency ? `${currency} ${amount}` : amount;
};

const qty = (value) => Number(value || 0).toFixed(4);

const rowsFrom = (payload) => {
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

const metaFrom = (payload) => {
  const data = payload?.data || {};
  return {
    current_page: Number(data.current_page || 1),
    last_page: Number(data.last_page || 1),
    total: Number(data.total || 0),
  };
};

const detailSessionFrom = (payload, fallback = null) => {
  return (
    payload?.data?.session ||
    payload?.data?.data?.session ||
    payload?.session ||
    fallback ||
    null
  );
};

const detailLinesFrom = (payload) => {
  const candidates = [
    payload?.data?.lines?.data,
    payload?.data?.data?.lines?.data,
    payload?.lines?.data,
    payload?.data?.lines,
    payload?.data?.data?.lines,
    payload?.lines,
  ];

  for (const candidate of candidates) {
    if (Array.isArray(candidate)) return candidate;
  }

  return [];
};

const normaliseDetailResponse = (payload, fallbackSession = null) => {
  const session = detailSessionFrom(payload, fallbackSession);
  const lines = detailLinesFrom(payload);

  return {
    data: {
      session,
      lines: {
        data: lines,
        current_page: payload?.data?.lines?.current_page || 1,
        last_page: payload?.data?.lines?.last_page || 1,
        total: payload?.data?.lines?.total || lines.length,
      },
    },
  };
};

const getStoredBranchId = () => {
  try {
    const user = JSON.parse(localStorage.getItem("back_user") || "{}");

    return (
      user?.selectedBranchId ||
      user?.selectedBranch?.id ||
      user?.branch_id ||
      user?.branch?.id ||
      localStorage.getItem("salexa_till_branch_id") ||
      localStorage.getItem("branch_id") ||
      localStorage.getItem("salexa_branch_id") ||
      ""
    );
  } catch {
    return (
      localStorage.getItem("salexa_till_branch_id") ||
      localStorage.getItem("branch_id") ||
      localStorage.getItem("salexa_branch_id") ||
      ""
    );
  }
};

const branchLabel = (branch) => {
  if (!branch) return "";
  const name = branch.branch_name || branch.name || "Unnamed Branch";
  const code = branch.branch_code || branch.code || "";
  return code ? `${name} (${code})` : name;
};

const Button = ({ children, variant = "default", ...props }) => {
  const styles = {
    default: "border-[#ACB8B8] bg-white text-[#003636] hover:bg-[#F6F8FA]",
    primary: "border-[#003636] bg-[#003637] text-white hover:bg-[#004444]",
    success: "border-[#069495] bg-[#E8F7F5] text-[#006869] hover:bg-[#D7F0EC]",
    danger: "border-[#B42318] bg-[#B42318] text-white hover:bg-[#8F1D14]",
    warning: "border-[#B54708] bg-[#FFFAEB] text-[#B54708] hover:bg-[#FEF0C7]",
  };

  return (
    <button
      type="button"
      {...props}
      className={`h-9 border px-4 text-[12px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${styles[variant]}`}
    >
      {children}
    </button>
  );
};

const Field = ({ label, hint, children }) => (
  <label className="flex flex-col gap-1">
    <span className="text-[11px] font-semibold uppercase tracking-wide text-[#637877]">
      {label}
    </span>
    {children}
    {hint && <span className="text-[11px] text-[#637877]">{hint}</span>}
  </label>
);

const StatusPill = ({ status }) => {
  const text = status || "-";
  const styles = {
    draft: "border-[#D7DFDF] bg-[#F6F8FA] text-[#637877]",
    count_uploaded: "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]",
    variance_review: "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]",
    approved: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]",
    posted: "border-[#00BCBC] bg-[#E8F7F5] text-[#006869]",
    cancelled: "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]",
    short: "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]",
    over: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]",
    matched: "border-[#D7DFDF] bg-white text-[#003636]",
    zero_count: "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]",
    unmatched: "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]",
  };

  return (
    <span className={`inline-flex min-w-[108px] justify-center border px-2 py-1 text-[11px] font-semibold ${styles[text] || styles.draft}`}>
      {text}
    </span>
  );
};

const StatCard = ({ label, value, currency, danger, success }) => (
  <div className="border border-[#D7DFDF] bg-white p-3">
    <div className="text-[11px] font-semibold uppercase tracking-wide text-[#637877]">
      {label}
    </div>
    <div
      className={`mt-1 text-[18px] font-black ${
        danger ? "text-[#B42318]" : success ? "text-[#067647]" : "text-[#003636]"
      }`}
    >
      {currency ? money(value, currency) : value}
    </div>
  </div>
);

export default function StockTakeModal({ open = true, onClose }) {
  const fileRef = useRef(null);

  const [activePanel, setActivePanel] = useState("process");
  const [branches, setBranches] = useState([]);
  const [branchSearch, setBranchSearch] = useState("");
  const [branchId, setBranchId] = useState(() => getStoredBranchId());
  const [asOfDate, setAsOfDate] = useState(today());
  const [dateFrom, setDateFrom] = useState(today());
  const [dateTo, setDateTo] = useState(today());
  const [countMode, setCountMode] = useState("zero");
  const [systemQtyMode, setSystemQtyMode] = useState("zero");
  const [includeZeroStock, setIncludeZeroStock] = useState(false);
  const [format, setFormat] = useState("xlsx");

  const [sessions, setSessions] = useState(null);
  const [selectedSession, setSelectedSession] = useState(null);
  const [linesResponse, setLinesResponse] = useState(null);
  const [uploadFile, setUploadFile] = useState(null);
  const [tradingMode, setTradingMode] = useState(false);
  const [balanceFile, setBalanceFile] = useState(null);
  const [tradingCountFile, setTradingCountFile] = useState(null);

  const [dashboard, setDashboard] = useState(null);
  const [varianceReport, setVarianceReport] = useState(null);
  const [categoryReport, setCategoryReport] = useState(null);
  const [branchReport, setBranchReport] = useState(null);
  const [auditReport, setAuditReport] = useState(null);
  const [varianceType, setVarianceType] = useState("");
  const [reportSearch, setReportSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [branchLoading, setBranchLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const selectedBranch = useMemo(
    () => branches.find((branch) => String(branch.id) === String(branchId)),
    [branches, branchId]
  );

  const filteredBranches = useMemo(() => {
    const search = branchSearch.toLowerCase();

    return branches.filter((branch) => {
      const name = String(branch.branch_name || branch.name || "").toLowerCase();
      const code = String(branch.branch_code || branch.code || "").toLowerCase();
      return name.includes(search) || code.includes(search);
    });
  }, [branches, branchSearch]);

  const sessionRows = useMemo(() => rowsFrom(sessions), [sessions]);
  const sessionMeta = useMemo(() => metaFrom(sessions), [sessions]);
  const varianceRows = useMemo(() => rowsFrom(varianceReport), [varianceReport]);
  const varianceMeta = useMemo(() => metaFrom(varianceReport), [varianceReport]);
  const auditRows = useMemo(() => rowsFrom(auditReport), [auditReport]);
  const auditMeta = useMemo(() => metaFrom(auditReport), [auditReport]);

  const varianceLines = detailLinesFrom(linesResponse);
  const sessionDetail = detailSessionFrom(linesResponse, selectedSession);
  const summary = dashboard?.data?.summary || {};
  const sessionIsTrading = (sessionDetail?.mode || selectedSession?.mode || "") === "trading";

  const reportFilters = (page = 1) => ({
    branch_id: branchId,
    date_from: dateFrom,
    date_to: dateTo,
    variance_type: varianceType,
    q: reportSearch,
    page,
    per_page: 50,
  });

  const loadBranches = async () => {
    try {
      setBranchLoading(true);
      setError("");

      const res = await getAuthorisedCompany();
      const info =
        res?.company ||
        res?.schoolInfo ||
        res?.data?.companyInfo ||
        res?.data?.schoolInfo ||
        res?.data ||
        null;

      const apiBranches =
        res?.company?.branches ||
        res?.data?.branches ||
        info?.branches ||
        [];

      const nextBranches = Array.isArray(apiBranches) ? apiBranches : [];
      setBranches(nextBranches);

      const stored = getStoredBranchId();
      if (stored && nextBranches.some((branch) => String(branch.id) === String(stored))) {
        setBranchId(stored);
      } else if (!branchId && nextBranches.length === 1) {
        setBranchId(nextBranches[0].id);
      }
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load authorised branches.");
    } finally {
      setBranchLoading(false);
    }
  };

  const loadSessions = async (page = 1) => {
    if (!branchId) return;

    try {
      setLoading(true);
      setError("");

      const res = await getStockTakes({
        branch_id: branchId,
        date_from: asOfDate,
        date_to: asOfDate,
        page,
        per_page: 25,
      });

      setSessions(res);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load stock takes.");
    } finally {
      setLoading(false);
    }
  };

  const loadReports = async (page = 1) => {
    if (!branchId && activePanel !== "branch-report") {
      setError("Select branch first.");
      return;
    }

    try {
      setLoading(true);
      setError("");

      const filters = reportFilters(page);

      const [dash, variance, category, branch, audit] = await Promise.all([
        getStockTakeDashboard(filters),
        getStockTakeVarianceReport(filters),
        getStockTakeCategoryVarianceReport(filters),
        getStockTakeBranchVarianceReport({
          date_from: dateFrom,
          date_to: dateTo,
          ...(branchId ? { branch_id: branchId } : {}),
        }),
        getStockTakeAuditReport(filters),
      ]);

      setDashboard(dash);
      setVarianceReport(variance);
      setCategoryReport(category);
      setBranchReport(branch);
      setAuditReport(audit);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load stock take reports.");
    } finally {
      setLoading(false);
    }
  };

  const openSession = async (session) => {
    try {
      setLoading(true);
      setError("");
      setMessage("");
      setSelectedSession(session);

      const res = await getStockTake(session.id, {
        per_page: 500,
        branch_id: branchId,
      });

      const normalised = normaliseDetailResponse(res, session);
      setLinesResponse(normalised);

      if (detailLinesFrom(normalised).length === 0) {
        setMessage("Stock take opened, but no variance lines were found. The upload may not have created stock_take_lines. Re-upload the original downloaded count sheet and confirm product_id column is not blank.");
      }
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to open stock take.");
    } finally {
      setLoading(false);
    }
  };

  const downloadSheet = async () => {
    if (!branchId) {
      setError("Select branch first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      await downloadStockTakeCountSheet({
        branch_id: branchId,
        as_of_date: asOfDate,
        format,
        count_mode: countMode,
        system_qty_mode: systemQtyMode,
        include_zero_stock: includeZeroStock ? 1 : 0,
        title: `Stock Take ${asOfDate}`,
      });

      setMessage("Stock take count sheet downloaded.");
      await loadSessions();
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to download count sheet.");
    } finally {
      setLoading(false);
    }
  };

  const downloadBalanceSnapshot = async () => {
    if (!branchId) {
      setError("Select branch first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      await downloadStockTakeBalanceSnapshot({
        branch_id: branchId,
        as_of_date: asOfDate,
        format,
        include_zero_stock: includeZeroStock ? 1 : 0,
        title: `Trading Stock Take ${asOfDate}`,
      });

      setTradingMode(true);
      setMessage("Balance snapshot downloaded. Keep this file safe; it is the frozen system balance.");
      await loadSessions();
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to download balance snapshot.");
    } finally {
      setLoading(false);
    }
  };

  const downloadTradingSheet = async () => {
    if (!branchId) {
      setError("Select branch first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      await downloadTradingCountSheet({
        branch_id: branchId,
        as_of_date: asOfDate,
        format,
        session_id: selectedSession?.id,
        count_mode: countMode === "system" ? "zero" : countMode,
        system_qty_mode: systemQtyMode,
        include_zero_stock: includeZeroStock ? 1 : 0,
      });

      setTradingMode(true);
      setMessage("Trading count sheet downloaded. Counters should enter counted_quantity in this file.");
      await loadSessions();
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to download trading count sheet.");
    } finally {
      setLoading(false);
    }
  };

  const startTradingStockTake = async () => {
    if (!branchId) {
      setError("Select branch first.");
      return;
    }

    const ok = await requestEnterpriseConfirmation({
      title: "Start Trading Stock Take",
      message: "Start trading stock take? This will clear this branch stock balances to zero and enable negative stock for the snapshot products so sales can continue while counting is in progress.",
      confirmLabel: "Start stock take",
    });

    if (!ok) return;

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await beginTradingStockTake({
        branch_id: branchId,
        as_of_date: asOfDate,
        session_id: selectedSession?.id,
        include_zero_stock: includeZeroStock ? 1 : 0,
        title: `Trading Stock Take ${asOfDate}`,
        confirm: true,
      });

      setTradingMode(true);
      setMessage(res?.message || "Trading stock take started.");
      if (res?.data?.session) {
        setSelectedSession(res.data.session);
        await openSession(res.data.session);
      }

      await loadSessions();
      await loadReports();
    } catch (err) {
      setError(err?.response?.data?.message || err?.response?.data?.error || err?.message || "Failed to start trading stock take.");
    } finally {
      setLoading(false);
    }
  };

  const uploadTradingSheets = async () => {
    if (!branchId) {
      setError("Select branch first.");
      return;
    }

    if (!selectedSession?.id && !sessionDetail?.id) {
      setError("Select the trading stock take session first.");
      return;
    }

    if (!tradingCountFile) {
      setError("Select the counted stock sheet first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await uploadTradingCountSheets({
        branch_id: branchId,
        as_of_date: asOfDate,
        session_id: sessionDetail?.id || selectedSession?.id,
        balance_file: balanceFile,
        count_file: tradingCountFile,
      });

      setTradingMode(true);
      setMessage(res?.message || "Trading stock take uploaded and analysed.");

      const uploadedSession = detailSessionFrom(res);
      const uploadedDetail = normaliseDetailResponse(res, uploadedSession);

      if (uploadedSession) {
        setSelectedSession(uploadedSession);
      }

      if (detailLinesFrom(uploadedDetail).length > 0) {
        setLinesResponse(uploadedDetail);
      } else if (uploadedSession?.id) {
        const detail = await getStockTake(uploadedSession.id, {
          per_page: 500,
          branch_id: branchId,
        });
        setLinesResponse(normaliseDetailResponse(detail, uploadedSession));
      }

      await loadSessions();
      await loadReports();
    } catch (err) {
      const payload = err?.response?.data;
      setError(payload?.data?.hint ? `${payload.message} ${payload.data.hint}` : payload?.message || payload?.error || err?.message || "Failed to upload trading stock take sheets.");
    } finally {
      setLoading(false);
    }
  };

  const uploadSheet = async () => {
    if (!branchId) {
      setError("Select branch first.");
      return;
    }

    if (!uploadFile) {
      setError("Select completed stock take Excel/CSV file first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await uploadStockTakeCountSheet({
        file: uploadFile,
        branch_id: branchId,
        as_of_date: asOfDate,
        session_id: selectedSession?.id,
        auto_create_session: 1,
      });

      setMessage(res?.message || "Stock take uploaded.");

      const uploadedSession = detailSessionFrom(res);
      const uploadedDetail = normaliseDetailResponse(res, uploadedSession);

      if (uploadedSession) {
        setSelectedSession(uploadedSession);
      }

      if (detailLinesFrom(uploadedDetail).length > 0) {
        setLinesResponse(uploadedDetail);
      } else if (uploadedSession?.id) {
        const detail = await getStockTake(uploadedSession.id, {
          per_page: 500,
          branch_id: branchId,
        });
        setLinesResponse(normaliseDetailResponse(detail, uploadedSession));
      }

      await loadSessions();
      await loadReports();
    } catch (err) {
      const payload = err?.response?.data;
      setError(payload?.data?.hint ? `${payload.message} ${payload.data.hint}` : payload?.message || payload?.error || err?.message || "Failed to upload count sheet.");
    } finally {
      setLoading(false);
    }
  };

  const approve = async () => {
    if (!sessionDetail?.id) return;

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await approveStockTake(sessionDetail.id, {
        notes: "Approved from Stock Take module.",
      });

      setMessage(res?.message || "Stock take approved.");
      await openSession(res.data || sessionDetail);
      await loadSessions();
      await loadReports();
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to approve stock take.");
    } finally {
      setLoading(false);
    }
  };

  const postAdjustments = async () => {
    if (!sessionDetail?.id) return;

    const ok = await requestEnterpriseConfirmation({
      title: "Post Stock Take",
      message: sessionIsTrading
        ? "Post trading stock take? Sales made during stock take stay as normal sales. The system will deduct the negative trading stock from counted stock and restore negative-stock settings."
        : "Post stock take adjustments? This will update product stock balances and create stock movements.",
      confirmLabel: "Post adjustments",
    });

    if (!ok) return;

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await postStockTakeAdjustments(sessionDetail.id);
      setMessage(res?.message || "Stock take posted.");
      await openSession(res.data || sessionDetail);
      await loadSessions();
      await loadReports();
    } catch (err) {
      setError(err?.response?.data?.message || err?.response?.data?.error || "Failed to post adjustments.");
    } finally {
      setLoading(false);
    }
  };

  const exportVariance = async () => {
    if (!sessionDetail?.id) return;

    try {
      setLoading(true);
      setError("");
      await exportStockTakeVariance(sessionDetail.id, { format: "xlsx" });
      setMessage("Variance report downloaded.");
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to export variance.");
    } finally {
      setLoading(false);
    }
  };

  const exportReport = async (type) => {
    try {
      setLoading(true);
      setError("");
      await exportStockTakeReport({
        ...reportFilters(1),
        type,
        format: "xlsx",
      });
      setMessage("Report downloaded.");
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to export report.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      loadBranches();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (open && branchId) {
      loadSessions();
      loadReports();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, branchId, asOfDate]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[80] bg-white">
      <div className="flex h-screen flex-col bg-[#F6F8FA] text-[#003636]">
        <div className="flex h-14 items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 text-white">
          <div>
            <div className="text-[16px] font-black uppercase tracking-wide">
              Branch Stock Take
            </div>
            <div className="text-[11px] font-semibold text-white/70">
              Branch-based count sheets, upload variance analysis, approval, posting and enterprise reports.
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="h-8 border border-white/30 px-3 text-[12px] font-bold hover:bg-white/10"
          >
            Close
          </button>
        </div>

        {(error || message) && (
          <div
            className={`border-b px-4 py-2 text-[12px] font-semibold ${
              error
                ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]"
                : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
            }`}
          >
            {error || message}
          </div>
        )}

        <div className="flex border-b border-[#D7DFDF] bg-white">
          {[
            ["process", "Stock Take Process"],
            ["variance-report", "Variance Report"],
            ["category-report", "Category Report"],
            ["branch-report", "Branch Report"],
            ["audit-report", "Audit Trail"],
          ].map(([key, label]) => (
            <button
              key={key}
              type="button"
              onClick={() => setActivePanel(key)}
              className={`h-10 border-r border-[#D7DFDF] px-4 text-[12px] font-semibold ${
                activePanel === key ? "bg-[#E8F7F5] text-[#006869]" : "bg-white text-[#003636]"
              }`}
            >
              {label}
            </button>
          ))}
        </div>

        <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden xl:grid-cols-[430px_1fr]">
          <aside className="min-h-0 overflow-auto border-r border-[#D7DFDF] bg-white p-4">
            <div className="space-y-3">
              <Field label="Branch" hint="Mandatory. All count sheets and reports are branch based.">
                <input
                  value={branchSearch}
                  onChange={(event) => setBranchSearch(event.target.value)}
                  placeholder="Search branch..."
                  className="mb-2 h-9 w-full border border-[#ACB8B8] px-3 text-[12px]"
                />

                <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                  <select
                    value={branchId}
                    onChange={(event) => setBranchId(event.target.value)}
                    className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                    disabled={branchLoading || loading}
                  >
                    <option value="">Select branch</option>
                    {filteredBranches.map((branch) => (
                      <option key={branch.id} value={branch.id}>
                        {branchLabel(branch)}
                      </option>
                    ))}
                  </select>

                  <Button onClick={loadBranches} disabled={branchLoading || loading}>
                    Refresh
                  </Button>
                </div>

                <div className="mt-2 border border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2 text-[11px] text-[#637877]">
                  Selected branch ID: <span className="font-semibold text-[#003636]">{branchId || "None"}</span>
                </div>
              </Field>

              <Field label="As Of Date">
                <input
                  type="date"
                  value={asOfDate}
                  onChange={(event) => setAsOfDate(event.target.value)}
                  className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                />
              </Field>

              <div className="grid gap-2 sm:grid-cols-2">
                <Field label="Report From">
                  <input
                    type="date"
                    value={dateFrom}
                    onChange={(event) => setDateFrom(event.target.value)}
                    className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                  />
                </Field>

                <Field label="Report To">
                  <input
                    type="date"
                    value={dateTo}
                    onChange={(event) => setDateTo(event.target.value)}
                    className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                  />
                </Field>
              </div>

              <div className="border border-[#00BCBC] bg-[#E8F7F5] p-3">
                <div className="text-[12px] font-bold uppercase tracking-wide text-[#006869]">
                  Stock Take Mode
                </div>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                  <button
                    type="button"
                    onClick={() => setTradingMode(false)}
                    className={`h-9 border px-3 text-[12px] font-semibold ${
                      !tradingMode ? "border-[#003636] bg-[#003637] text-white" : "border-[#ACB8B8] bg-white text-[#003636]"
                    }`}
                  >
                    Normal
                  </button>
                  <button
                    type="button"
                    onClick={() => setTradingMode(true)}
                    className={`h-9 border px-3 text-[12px] font-semibold ${
                      tradingMode ? "border-[#003636] bg-[#003637] text-white" : "border-[#ACB8B8] bg-white text-[#003636]"
                    }`}
                  >
                    Trading Stock Take
                  </button>
                </div>
                <div className="mt-2 text-[11px] leading-4 text-[#324443]">
                  Trading mode downloads a frozen balance snapshot, clears branch stock to zero, enables negative stock, then deducts sales made during counting from the counted stock before posting.
                </div>
              </div>

              <div className="grid gap-2 sm:grid-cols-2">
                <Field label="Counted Qty Mode" hint="Controls the editable counted quantity column.">
                  <select
                    value={countMode}
                    onChange={(event) => setCountMode(event.target.value)}
                    className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                  >
                    <option value="zero">Clear counted qty to zeros</option>
                    <option value="blank">Blank counted qty</option>
                    <option value="system">Pre-fill counted qty with displayed system qty</option>
                  </select>
                </Field>

                <Field label="System Qty Display" hint="Use Zero when counters must not see expected stock.">
                  <select
                    value={systemQtyMode}
                    onChange={(event) => setSystemQtyMode(event.target.value)}
                    className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                  >
                    <option value="zero">Show system qty as 0</option>
                    <option value="actual">Show actual system qty</option>
                  </select>
                </Field>

                <Field label="Format">
                  <select
                    value={format}
                    onChange={(event) => setFormat(event.target.value)}
                    className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                  >
                    <option value="xlsx">Excel</option>
                    <option value="csv">CSV</option>
                  </select>
                </Field>
              </div>

              <label className="flex items-center gap-2 border border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2 text-[12px]">
                <input
                  type="checkbox"
                  checked={includeZeroStock}
                  onChange={(event) => setIncludeZeroStock(event.target.checked)}
                />
                Include zero-stock products
              </label>

              {!tradingMode ? (
                <div className="grid gap-2 sm:grid-cols-2">
                  <Button onClick={downloadSheet} disabled={loading} variant="primary">
                    Download Count Sheet
                  </Button>

                  <Button onClick={() => loadReports()} disabled={loading}>
                    Apply Reports
                  </Button>
                </div>
              ) : (
                <div className="space-y-2 border border-[#FEDF89] bg-[#FFFAEB] p-3">
                  <div className="text-[12px] font-bold uppercase tracking-wide text-[#B54708]">
                    Trading Stock Take Steps
                  </div>

                  <div className="grid gap-2 sm:grid-cols-2">
                    <Button onClick={downloadBalanceSnapshot} disabled={loading} variant="primary">
                      1. Download Balance
                    </Button>
                    <Button onClick={downloadTradingSheet} disabled={loading}>
                      2. Download Count Sheet
                    </Button>
                    <Button onClick={startTradingStockTake} disabled={loading} variant="warning">
                      3. Clear Stock / Start Trading
                    </Button>
                    <Button onClick={() => loadReports()} disabled={loading}>
                      Apply Reports
                    </Button>
                  </div>
                </div>
              )}

              <Field label="Variance Type">
                <select
                  value={varianceType}
                  onChange={(event) => setVarianceType(event.target.value)}
                  className="h-9 border border-[#ACB8B8] px-2 text-[12px]"
                >
                  <option value="">All</option>
                  <option value="short">Short</option>
                  <option value="over">Over</option>
                  <option value="zero_count">Zero Count</option>
                  <option value="matched">Matched</option>
                  <option value="unmatched">Unmatched</option>
                </select>
              </Field>

              <Field label="Report Search">
                <input
                  value={reportSearch}
                  onChange={(event) => setReportSearch(event.target.value)}
                  placeholder="Product, SKU, barcode, stock take no..."
                  className="h-9 border border-[#ACB8B8] px-3 text-[12px]"
                />
              </Field>

              {!tradingMode ? (
                <>
                  <Field label="Upload Completed Count Sheet">
                    <input
                      ref={fileRef}
                      type="file"
                      accept=".xlsx,.xls,.csv,.txt"
                      onChange={(event) => setUploadFile(event.target.files?.[0] || null)}
                      className="h-10 w-full border border-[#ACB8B8] bg-white px-3 py-2 text-[12px]"
                    />
                  </Field>

                  <Button onClick={uploadSheet} disabled={loading} variant="success">
                    Upload and Analyse Variance
                  </Button>
                </>
              ) : (
                <div className="space-y-2 border border-[#D7DFDF] bg-[#F6F8FA] p-3">
                  <Field label="Upload Balance Snapshot" hint="Optional, but recommended. Upload the balance sheet downloaded before clearing stock.">
                    <input
                      type="file"
                      accept=".xlsx,.xls,.csv,.txt"
                      onChange={(event) => setBalanceFile(event.target.files?.[0] || null)}
                      className="h-10 w-full border border-[#ACB8B8] bg-white px-3 py-2 text-[12px]"
                    />
                  </Field>

                  <Field label="Upload Counted Values Sheet" hint="Required. This is the sheet where counted_quantity was filled in.">
                    <input
                      type="file"
                      accept=".xlsx,.xls,.csv,.txt"
                      onChange={(event) => setTradingCountFile(event.target.files?.[0] || null)}
                      className="h-10 w-full border border-[#ACB8B8] bg-white px-3 py-2 text-[12px]"
                    />
                  </Field>

                  <Button onClick={uploadTradingSheets} disabled={loading} variant="success">
                    4. Upload Trading Sheets / Analyse
                  </Button>
                </div>
              )}

              <div className="border border-[#D7DFDF] bg-[#F6F8FA] p-3 text-[11px] leading-5 text-[#637877]">
                <div className="font-bold uppercase text-[#003636]">Branch stock take flow</div>
                {!tradingMode ? (
                  <>
                    <div>1. Select branch and as-of date.</div>
                    <div>2. Download count sheet with Counted Quantity next to Product Name.</div>
                    <div>3. Count physical stock.</div>
                    <div>4. Upload and review variances.</div>
                    <div>5. Approve, post and export reports.</div>
                  </>
                ) : (
                  <>
                    <div>1. Download balance snapshot.</div>
                    <div>2. Download counted values sheet.</div>
                    <div>3. Clear branch stock to zero and start trading negative.</div>
                    <div>4. Upload balance/count sheets and review variance.</div>
                    <div>5. Approve and post final stock after deducting sales.</div>
                  </>
                )}
              </div>
            </div>
          </aside>

          <main className="min-h-0 overflow-auto p-4">
            <div className="mb-3 grid gap-2 md:grid-cols-3 xl:grid-cols-6">
              <StatCard label="Sessions" value={summary?.session_count || 0} />
              <StatCard label="Lines" value={summary?.line_count || 0} />
              <StatCard label="Variance Qty" value={qty(summary?.variance_quantity)} danger={Number(summary?.variance_quantity || 0) < 0} success={Number(summary?.variance_quantity || 0) > 0} />
              <StatCard label="Cost Variance" value={summary?.variance_cost_value || 0} currency={summary?.cost_currency || ""} />
              <StatCard label="Selling Variance" value={summary?.variance_selling_value || 0} currency={summary?.selling_currency || ""} />
              <StatCard label="Short / Over" value={`${summary?.short_count || 0} / ${summary?.over_count || 0}`} />
            </div>

            {activePanel === "process" && (
              <>
                <div className="mb-3 border border-[#D7DFDF] bg-white">
                  <div className="border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2 text-[12px] font-bold uppercase tracking-wide">
                    Branch Stock Take Sessions
                  </div>

                  <div className="overflow-auto">
                    <table className="min-w-[1100px] w-full border-collapse text-left text-[12px]">
                      <thead className="bg-[#003637] text-white">
                        <tr>
                          <th className="px-3 py-2 font-semibold">No</th>
                          <th className="px-3 py-2 font-semibold">Date</th>
                          <th className="px-3 py-2 font-semibold">Title</th>
                          <th className="px-3 py-2 font-semibold">Status</th>
                          <th className="px-3 py-2 font-semibold">Mode</th>
                          <th className="px-3 py-2 text-right font-semibold">Lines</th>
                          <th className="px-3 py-2 text-right font-semibold">Variance Qty</th>
                          <th className="px-3 py-2 text-right font-semibold">Cost Variance</th>
                          <th className="px-3 py-2 text-right font-semibold">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {sessionRows.map((session) => (
                          <tr key={session.id} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                            <td className="px-3 py-2 text-[#003636]">{session.stock_take_no}</td>
                            <td className="px-3 py-2">{session.as_of_date}</td>
                            <td className="px-3 py-2">{session.title || "-"}</td>
                            <td className="px-3 py-2"><StatusPill status={session.status} /></td>
                            <td className="px-3 py-2">{session.mode || "normal"}</td>
                            <td className="px-3 py-2 text-right">{session.line_count}</td>
                            <td className="px-3 py-2 text-right">{qty(session.variance_quantity)}</td>
                            <td className="px-3 py-2 text-right">{money(session.variance_cost_value, session.cost_currency)}</td>
                            <td className="px-3 py-2">
                              <div className="flex justify-end gap-1">
                                <Button onClick={() => openSession(session)} disabled={loading}>View</Button>
                              </div>
                            </td>
                          </tr>
                        ))}

                        {sessionRows.length === 0 && (
                          <tr>
                            <td colSpan={9} className="px-3 py-8 text-center text-[#637877]">
                              No branch stock take sessions found.
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>

                <VarianceLinesTable
                  sessionDetail={sessionDetail}
                  varianceLines={varianceLines}
                  loading={loading}
                  approve={approve}
                  postAdjustments={postAdjustments}
                  exportVariance={exportVariance}
                />
              </>
            )}

            {activePanel === "variance-report" && (
              <ReportTable
                title="Variance Report"
                rows={varianceRows}
                columns={[
                  ["stock_take_no", "Stock Take"],
                  ["as_of_date", "Date"],
                  ["product_name", "Product"],
                  ["code", "Code"],
                  ["category_name", "Category"],
                  ["system_quantity", "System Qty", "qty"],
                  ["counted_quantity", "Counted Qty", "qty"],
                  ["trading_quantity", "Trading Qty", "qty"],
                  ["final_quantity", "Final Qty", "qty"],
                  ["variance_quantity", "Variance", "qty"],
                  ["variance_type", "Type", "status"],
                  ["unit_cost", "Unit Cost", "money_cost"],
                  ["variance_cost_value", "Cost Var", "money_cost"],
                  ["selling_price", "Selling Price", "money_sell"],
                  ["variance_selling_value", "Selling Var", "money_sell"],
                ]}
                meta={varianceMeta}
                onPrev={() => loadReports(varianceMeta.current_page - 1)}
                onNext={() => loadReports(varianceMeta.current_page + 1)}
                onExport={() => exportReport("variance")}
              />
            )}

            {activePanel === "category-report" && (
              <ReportTable
                title="Category Variance Report"
                rows={categoryReport?.data || []}
                columns={[
                  ["category_name", "Category"],
                  ["line_count", "Lines"],
                  ["system_quantity", "System Qty", "qty"],
                  ["counted_quantity", "Counted Qty", "qty"],
                  ["trading_quantity", "Trading Qty", "qty"],
                  ["final_quantity", "Final Qty", "qty"],
                  ["variance_quantity", "Variance", "qty"],
                  ["matched_count", "Matched"],
                  ["short_count", "Short"],
                  ["over_count", "Over"],
                  ["zero_count", "Zero"],
                  ["variance_cost_value", "Cost Var", "money_cost"],
                  ["variance_selling_value", "Selling Var", "money_sell"],
                ]}
                onExport={() => exportReport("category")}
              />
            )}

            {activePanel === "branch-report" && (
              <ReportTable
                title="Branch Variance Report"
                rows={branchReport?.data || []}
                columns={[
                  ["branch_name", "Branch"],
                  ["session_count", "Sessions"],
                  ["line_count", "Lines"],
                  ["system_quantity", "System Qty", "qty"],
                  ["counted_quantity", "Counted Qty", "qty"],
                  ["trading_quantity", "Trading Qty", "qty"],
                  ["final_quantity", "Final Qty", "qty"],
                  ["variance_quantity", "Variance", "qty"],
                  ["short_count", "Short"],
                  ["over_count", "Over"],
                  ["zero_count", "Zero"],
                  ["variance_cost_value", "Cost Var", "money_cost"],
                  ["variance_selling_value", "Selling Var", "money_sell"],
                ]}
                onExport={() => exportReport("branch")}
              />
            )}

            {activePanel === "audit-report" && (
              <ReportTable
                title="Stock Take Audit Trail"
                rows={auditRows}
                columns={[
                  ["stock_take_no", "Stock Take"],
                  ["as_of_date", "Date"],
                  ["title", "Title"],
                  ["status", "Status", "status"],
                  ["line_count", "Lines"],
                  ["variance_quantity", "Variance Qty", "qty"],
                  ["variance_cost_value", "Cost Var", "money_cost"],
                  ["created_by_name", "Created By"],
                  ["created_at", "Created At"],
                  ["uploaded_by_name", "Uploaded By"],
                  ["uploaded_at", "Uploaded At"],
                  ["approved_by_name", "Approved By"],
                  ["approved_at", "Approved At"],
                  ["posted_by_name", "Posted By"],
                  ["posted_at", "Posted At"],
                ]}
                meta={auditMeta}
                onPrev={() => loadReports(auditMeta.current_page - 1)}
                onNext={() => loadReports(auditMeta.current_page + 1)}
                onExport={() => exportReport("audit")}
              />
            )}
          </main>
        </div>
      </div>
    </div>
  );
}

function VarianceLinesTable({ sessionDetail, varianceLines, loading, approve, postAdjustments, exportVariance }) {
  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="flex items-center justify-between border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2">
        <div>
          <div className="text-[12px] font-bold uppercase tracking-wide">Selected Session Variance</div>
          <div className="text-[11px] font-semibold text-[#637877]">{sessionDetail?.stock_take_no || "Select a stock take session"}</div>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button onClick={exportVariance} disabled={!sessionDetail?.id || loading}>Export</Button>
          <Button onClick={approve} disabled={!sessionDetail?.id || loading || !["count_uploaded", "variance_review"].includes(sessionDetail?.status)} variant="warning">Approve</Button>
          <Button onClick={postAdjustments} disabled={!sessionDetail?.id || loading || sessionDetail?.status !== "approved"} variant="danger">Post</Button>
        </div>
      </div>

      <div className="overflow-auto">
        <table className="min-w-[1650px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              <th className="px-3 py-2 font-semibold">Product</th>
              <th className="px-3 py-2 font-semibold">Code</th>
              <th className="px-3 py-2 font-semibold">Category</th>
              <th className="px-3 py-2 text-right font-semibold">System Qty</th>
              <th className="px-3 py-2 text-right font-semibold">Counted Qty</th>
              <th className="px-3 py-2 text-right font-semibold">Trading Qty</th>
              <th className="px-3 py-2 text-right font-semibold">Final Qty</th>
              <th className="px-3 py-2 text-right font-semibold">Variance</th>
              <th className="px-3 py-2 font-semibold">Type</th>
              <th className="px-3 py-2 text-right font-semibold">Cost Var</th>
              <th className="px-3 py-2 text-right font-semibold">Selling Var</th>
              <th className="px-3 py-2 font-semibold">Notes</th>
            </tr>
          </thead>
          <tbody>
            {varianceLines.map((line) => (
              <tr key={line.id} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                <td className="px-3 py-2 text-[#003636]">{line.product_name}</td>
                <td className="px-3 py-2">{line.code || line.sku || "-"}</td>
                <td className="px-3 py-2">{line.category_name || "-"}</td>
                <td className="px-3 py-2 text-right">{qty(line.system_quantity)}</td>
                <td className="px-3 py-2 text-right">{qty(line.counted_quantity)}</td>
                <td className="px-3 py-2 text-right">{line.trading_quantity !== undefined ? qty(line.trading_quantity) : "-"}</td>
                <td className="px-3 py-2 text-right">{line.final_quantity !== undefined ? qty(line.final_quantity) : "-"}</td>
                <td className="px-3 py-2 text-right">{qty(line.variance_quantity)}</td>
                <td className="px-3 py-2"><StatusPill status={line.variance_type} /></td>
                <td className="px-3 py-2 text-right">{money(line.variance_cost_value, line.cost_currency)}</td>
                <td className="px-3 py-2 text-right">{money(line.variance_selling_value, line.selling_currency)}</td>
                <td className="px-3 py-2">{line.count_notes || "-"}</td>
              </tr>
            ))}

            {varianceLines.length === 0 && (
              <tr>
                <td colSpan={12} className="px-3 py-10 text-center text-[#637877]">
                  Upload a completed count sheet or select a session to see variance lines.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ReportTable({ title, rows = [], columns = [], meta, onPrev, onNext, onExport }) {
  const renderValue = (row, key, type) => {
    if (type === "qty") return qty(row[key]);
    if (type === "money_cost") return money(row[key], row.cost_currency);
    if (type === "money_sell") return money(row[key], row.selling_currency);
    if (type === "status") return <StatusPill status={row[key]} />;
    return row[key] ?? "-";
  };

  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="flex items-center justify-between border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2">
        <div className="text-[12px] font-bold uppercase tracking-wide">{title}</div>
        <Button onClick={onExport}>Download Excel</Button>
      </div>

      <div className="overflow-auto">
        <table className="min-w-[1300px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              {columns.map(([key, label]) => (
                <th key={key} className="px-3 py-2 font-semibold">{label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={row.id || row.product_id || row.category_name || row.stock_take_no || index} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                {columns.map(([key, , type]) => (
                  <td key={key} className={`px-3 py-2 ${type?.startsWith("money") || type === "qty" ? "text-right" : ""}`}>
                    {renderValue(row, key, type)}
                  </td>
                ))}
              </tr>
            ))}

            {rows.length === 0 && (
              <tr>
                <td colSpan={columns.length} className="px-3 py-10 text-center text-[#637877]">
                  No report records found.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {meta && (
        <div className="flex items-center justify-between border-t border-[#D7DFDF] px-3 py-2 text-[12px] font-semibold text-[#637877]">
          <span>Page {meta.current_page} of {meta.last_page} · {meta.total} records</span>
          <div className="flex gap-2">
            <Button disabled={meta.current_page <= 1} onClick={onPrev}>Previous</Button>
            <Button disabled={meta.current_page >= meta.last_page} onClick={onNext}>Next</Button>
          </div>
        </div>
      )}
    </div>
  );
}

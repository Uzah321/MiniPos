import React, { useEffect, useMemo, useRef, useState } from "react";
import { Check, ChevronDown, Download, RefreshCw, Search } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import HospitalityReportNavigator, {
  HOSPITALITY_REPORT_KEYS,
} from "../../../components/HospitalityReportNavigator";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import {
  downloadClosingStockMovementExcel,
  downloadClosingStockMovementPdf,
  getClosingStockDays,
  getClosingStockMovementReport,
} from "../../../services/ClosingStockMovementReportService";

const qty = (value) => Number(value || 0).toFixed(4);
const money = (value, currency = "USD") => `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;
const today = () => new Date().toISOString().slice(0, 10);

const formatDate = (value) => {
  if (!value) return "-";
  const d = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
};

const dateRangeName = (days) => {
  if (!days?.length) return "closing-stock-movement-report";
  const dates = days.map((day) => day.closing_date).filter(Boolean);
  if (dates.length === 1) return `closing-stock-movement-report-${dates[0]}`;
  return `closing-stock-movement-report-${dates[0]}-to-${dates[dates.length - 1]}`;
};

const REPORT_VIEWS = [
  { key: "summary", label: "Day Summary", description: "Opening, movement and closing totals by day" },
  { key: "lines", label: "Stock Lines", description: "Expected, counted and variance detail by item" },
  { key: "movements", label: "Movement Detail", description: "Underlying stock movement ledger" },
];

function Dropdown({ label, value, width = "w-full", children }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    const close = (event) => {
      if (!ref.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);

  return (
    <div ref={ref} className={`relative ${width}`}>
      <button
        type="button"
        aria-label={label || "Closing stock days"}
        onClick={() => setOpen((prev) => !prev)}
        className="h-9 w-full border border-[#B9C5C5] bg-white px-3 text-left text-[11px] text-[#122424] shadow-sm flex items-center justify-between"
      >
        <span className="min-w-0 truncate">{label && <span className="text-[#637877]">{label}: </span>}{value}</span>
        <ChevronDown className="h-3.5 w-3.5 text-[#637877]" />
      </button>
      {open && (
        <div className="absolute left-0 top-[38px] z-[100] max-h-[360px] w-full overflow-y-auto border border-[#ACB8B8] bg-white shadow-xl">
          {children({ close: () => setOpen(false) })}
        </div>
      )}
    </div>
  );
}

const ClosingStockMovementReportModal = ({ open, onClose, onNavigateReport }) => {
  const [scopeFilters, setScopeFilters] = useState({ date_from: "", date_to: today(), branch_id: "", till_id: "", cashier_id: "", shift_session_id: "", currency_codes: [], business_mode: "fast_food_boh" });
  const [closingDays, setClosingDays] = useState([]);
  const [selectedClosingIds, setSelectedClosingIds] = useState([]);
  const [search, setSearch] = useState("");
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [activeTab, setActiveTab] = useState("summary");
  const [generated, setGenerated] = useState(false);
  const [outputChooserOpen, setOutputChooserOpen] = useState(false);
  const [appliedClosingIds, setAppliedClosingIds] = useState([]);
  const [exportingFormat, setExportingFormat] = useState("");
  const closingDaysRequestRef = useRef(0);
  const lookups = useHospitalityReportLookups(open, scopeFilters);

  const days = report?.days || [];
  const movementDetail = report?.movement_detail || [];

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 3500);
  };

  const params = (ids = selectedClosingIds) => ({ closing_ids: ids });

  const loadClosingDays = async (nextScope = scopeFilters) => {
    const requestId = ++closingDaysRequestRef.current;
    const rows = await getClosingStockDays({ limit: 250, branch_id: nextScope.branch_id || undefined, date_from: nextScope.date_from || undefined, date_to: nextScope.date_to || undefined });
    if (requestId !== closingDaysRequestRef.current) return [];
    const list = Array.isArray(rows) ? rows : [];
    setClosingDays(list);
    const availableIds = new Set(list.map((item) => String(item.id)));
    const retainedIds = selectedClosingIds.filter((id) => availableIds.has(String(id)));
    const nextIds = retainedIds.length ? retainedIds : (list[0] ? [String(list[0].id)] : []);
    setSelectedClosingIds(nextIds);
    return nextIds;
  };

  const loadReport = async (ids = selectedClosingIds) => {
    if (!ids.length) {
      setReport(null);
      setGenerated(false);
      showNotice("Select at least one closing stock day.");
      return false;
    }
    setLoading(true);
    setReport(null);
    try {
      const data = await getClosingStockMovementReport({ closing_ids: ids });
      setReport(data);
      setAppliedClosingIds([...ids]);
      setGenerated(true);
      return true;
    } catch (error) {
      setGenerated(false);
      setAppliedClosingIds([]);
      showNotice(error?.response?.data?.message || error?.message || "Failed to load closing stock movement report.");
      return false;
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    (async () => {
      try {
        setLoading(true);
        await loadClosingDays();
        setReport(null);
        setGenerated(false);
        setAppliedClosingIds([]);
        setOutputChooserOpen(false);
      } catch (error) {
        setClosingDays([]);
        setSelectedClosingIds([]);
        setReport(null);
        setGenerated(false);
        setAppliedClosingIds([]);
        showNotice(error?.response?.data?.message || error?.message || "Failed to load closing stock days.");
      } finally {
        setLoading(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const filteredClosingDays = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return closingDays;
    return closingDays.filter((day) => {
      const text = `${day.closing_date || ""} ${formatDate(day.closing_date)} ${day.status || ""}`.toLowerCase();
      return text.includes(q);
    });
  }, [closingDays, search]);

  const toggleClosingDay = (id) => {
    const cleanId = String(id);
    setGenerated(false);
    setReport(null);
    setAppliedClosingIds([]);
    setSelectedClosingIds((prev) =>
      prev.includes(cleanId) ? prev.filter((item) => item !== cleanId) : [...prev, cleanId]
    );
  };

  const openOutputChooser = () => {
    if (!selectedClosingIds.length) {
      showNotice("Select at least one closing stock day.");
      return;
    }
    setOutputChooserOpen(true);
  };

  const viewSelectedReport = async () => {
    const viewed = await loadReport([...selectedClosingIds]);
    if (viewed) setOutputChooserOpen(false);
  };

  const selectedDayRows = (ids) =>
    closingDays.filter((day) => ids.map(String).includes(String(day.id)));

  const downloadExcel = async (ids = generated ? appliedClosingIds : selectedClosingIds) => {
    if (!ids.length) return showNotice("Select at least one closing stock day.");
    try {
      setExportingFormat("xlsx");
      await downloadClosingStockMovementExcel(
        params(ids),
        `${dateRangeName(selectedDayRows(ids))}.xlsx`,
      );
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Failed to download the Excel report.");
    } finally {
      setExportingFormat("");
    }
  };

  const downloadPdf = async (ids = generated ? appliedClosingIds : selectedClosingIds) => {
    if (!ids.length) return showNotice("Select at least one closing stock day.");
    try {
      setExportingFormat("pdf");
      await downloadClosingStockMovementPdf(
        params(ids),
        `${dateRangeName(selectedDayRows(ids))}.pdf`,
      );
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Failed to download the PDF report.");
    } finally {
      setExportingFormat("");
    }
  };

  const downloadChosenFormat = async (format) => {
    setOutputChooserOpen(false);
    if (format === "pdf") await downloadPdf();
    else await downloadExcel();
  };

  const handleScopeFiltersChange = (next) => {
    setGenerated(false);
    setReport(null);
    setAppliedClosingIds([]);
    setScopeFilters(next);
    setLoading(true);
    loadClosingDays(next)
      .catch((error) => {
        setClosingDays([]);
        setSelectedClosingIds([]);
        showNotice(error?.response?.data?.message || error?.message || "Failed to load closing stock days.");
      })
      .finally(() => setLoading(false));
  };

  const selectedLabel = selectedClosingIds.length === 0
    ? "Select days"
    : selectedClosingIds.length === 1
      ? formatDate(closingDays.find((day) => String(day.id) === String(selectedClosingIds[0]))?.closing_date)
      : `${selectedClosingIds.length} closing days`;

  const closingDayCells = [
    {
      key: "closing-stock-days",
      label: "Closing stock days",
      content: (
        <Dropdown value={selectedLabel}>
          {() => (
            <div>
              <div className="border-b border-[#E4E8E8] p-2">
                <div className="flex h-8 items-center gap-2 border border-[#CBD6D6] bg-[#F8FAFC] px-2">
                  <Search className="h-3.5 w-3.5 text-[#637877]" />
                  <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search closing day"
                    aria-label="Search closing stock days"
                    className="h-full min-w-0 flex-1 bg-transparent text-[11px] outline-none"
                  />
                </div>
              </div>
              <div className="max-h-[300px] overflow-y-auto">
                {filteredClosingDays.length === 0 ? (
                  <div className="px-3 py-4 text-center text-[10px] text-[#637877]">
                    No closing stock days match this branch and trading-date period.
                  </div>
                ) : filteredClosingDays.map((day) => {
                  const id = String(day.id);
                  const checked = selectedClosingIds.includes(id);
                  return (
                    <label key={id} className="flex cursor-pointer items-center gap-2 border-b border-[#EFF2F2] px-3 py-2 hover:bg-[#F8FAFC]">
                      <input type="checkbox" checked={checked} onChange={() => toggleClosingDay(id)} />
                      <span className="min-w-0 flex-1">
                        <span className="block text-[11px] font-medium">{formatDate(day.closing_date)}</span>
                        <span className="block text-[10px] text-[#637877]">Lines {day.total_lines || 0} · Variance {day.variance_lines || 0}</span>
                      </span>
                      {checked && <Check className="h-3.5 w-3.5 text-[#057B7B]" />}
                    </label>
                  );
                })}
              </div>
            </div>
          )}
        </Dropdown>
      ),
    },
    {
      key: "closing-stock-day-availability",
      label: "Closing day availability",
      content: (
        <div className="flex h-[38px] items-center justify-between gap-3 border border-[#B9C0C0] bg-white px-3">
          <span className="min-w-0 truncate text-[11px] text-[#4D5756]">
            {closingDays.length} day{closingDays.length === 1 ? "" : "s"} found · {selectedClosingIds.length} selected
          </span>
          <button
            type="button"
            onClick={() => handleScopeFiltersChange(scopeFilters)}
            disabled={loading}
            className="shrink-0 border-l border-[#D8DCDC] pl-3 text-[10px] font-semibold uppercase text-[#006869] disabled:opacity-50"
          >
            {loading ? "Loading…" : "Refresh"}
          </button>
        </div>
      ),
    },
  ];

  if (!open) return null;

  return (
    <>
    <FullScreenModal open={open} onClose={onClose} title="Closing Stock Movement Report" subtitle="Opening stock, stock movement, closing count and variance by closing day">
      <div className="relative h-full min-h-0 overflow-hidden bg-[#EFF3F3] text-[#122424] text-[11px]">
        {notice && (
          <div className="absolute left-1/2 top-2 z-[200] -translate-x-1/2 border border-[#122424] bg-white px-4 py-2 text-[11px] shadow-xl">
            {notice}
          </div>
        )}

        <div className="flex h-full min-h-0">
            <HospitalityReportNavigator
              section="inventory"
              activeKey={HOSPITALITY_REPORT_KEYS.CLOSING_STOCK}
              onNavigate={onNavigateReport}
              groups={[
                {
                  title: "Closing stock views",
                  items: REPORT_VIEWS,
                  activeKey: activeTab,
                  onChange: setActiveTab,
                },
              ]}
            />
            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {!generated && (
              <div className="shrink-0 bg-white">
                <HospitalityReportScopePanel
                  filters={scopeFilters}
                  setFilters={handleScopeFiltersChange}
                  lookups={lookups}
                  loading={loading}
                  onApply={openOutputChooser}
                  periodLabel="Trading Date Period"
                  supported={{ till_id: false, cashier_id: false, shift_session_id: false, currency_codes: false }}
                  extraCells={closingDayCells}
                />
              </div>
            )}
            <div className={`min-h-0 min-w-0 flex-1 ${generated ? "overflow-hidden" : "overflow-y-auto p-4"}`}>
            {!generated ? (
              <ReportGenerationPrompt
                title="Configure Closing Stock Movement Report"
                message="Choose the branch, period and closing stock days, then select Generate."
              />
            ) : (
              <ReportViewShell
                title="Closing Stock Movement Report"
                subtitle={`${appliedClosingIds.length} closing day${appliedClosingIds.length === 1 ? "" : "s"}`}
                onBackToFilters={() => setGenerated(false)}
                busy={loading || !!exportingFormat}
                actions={(
                  <>
                    <button type="button" onClick={() => loadReport(appliedClosingIds)} disabled={loading || !!exportingFormat} className="inline-flex h-8 items-center gap-1 border border-[#ACB8B8] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
                    <button type="button" onClick={() => setOutputChooserOpen(true)} disabled={loading || !!exportingFormat} className="inline-flex h-8 items-center gap-1 border border-[#057B7B] bg-[#057B7B] px-3 text-[11px] font-semibold text-white disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
                  </>
                )}
              >
              <div className="h-full overflow-y-auto p-4">
              {!days.length ? (
              <div className="border border-[#CBD6D6] bg-white p-8 text-center text-[#637877]">No closing stock days selected.</div>
            ) : activeTab === "summary" ? (
              <div className="space-y-3">
                {days.map((day) => (
                  <section key={day.closing_id} className="border border-[#C4CFCF] bg-white">
                    <div className="border-b border-[#C4CFCF] bg-[#003A3A] px-4 py-3 text-white">
                      <div className="text-[15px] font-medium">{day.formatted_date}</div>
                      <div className="mt-1 text-[10px] text-white/70">Lines {day.total_lines} · Variance lines {day.variance_lines}</div>
                    </div>
                    <div className="grid grid-cols-2 gap-px bg-[#E2E9E9] md:grid-cols-6">
                      <Metric label="Opening" value={qty(day.totals?.opening_qty)} />
                      <Metric label="Receipts" value={qty(day.totals?.receipts_qty)} />
                      <Metric label="BOH to Front" value={qty(day.totals?.boh_to_front_qty)} />
                      <Metric label="Expected" value={qty(day.totals?.expected_closing_qty)} />
                      <Metric label="Counted" value={qty(day.totals?.counted_closing_qty)} />
                      <Metric label="Variance" value={qty(day.totals?.variance_qty)} danger={Number(day.totals?.variance_qty || 0) !== 0} />
                    </div>
                    <div className="p-3">
                      <table className="sx-enterprise-table w-full text-left text-[10.5px]">
                        <thead className="bg-[#F8FAFC] text-[9px] uppercase text-[#465858]">
                          <tr><th className="px-2 py-2">Currency</th><th className="px-2 py-2 text-right">Variance Value</th></tr>
                        </thead>
                        <tbody>
                          {Object.entries(day.totals?.variance_value_by_currency || {}).map(([currency, value]) => (
                            <tr key={currency} className="border-t border-[#EFF2F2]"><td className="px-2 py-2">{currency}</td><td className="px-2 py-2 text-right">{money(value, currency)}</td></tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </section>
                ))}
              </div>
            ) : activeTab === "lines" ? (
              <div className="space-y-3">
                {days.map((day) => (
                  <section key={day.closing_id} className="border border-[#C4CFCF] bg-white">
                    <div className="border-b border-[#C4CFCF] bg-[#003A3A] px-4 py-2 text-[13px] font-medium text-white">{day.formatted_date}</div>
                    <div className="overflow-x-auto">
                      <table className="sx-enterprise-table min-w-[1580px] w-full text-left text-[10.5px]">
                        <thead className="bg-[#F8FAFC] text-[9px] uppercase text-[#465858]">
                          <tr>
                            <th className="px-2 py-2">Product</th><th>Code</th><th>UOM</th><th className="text-right">Opening</th><th className="text-right">Receipts</th><th className="text-right">BOH Front</th><th className="text-right">Sales</th><th className="text-right">Issues</th><th className="text-right">Adjust.</th><th className="text-right">Expected</th><th className="text-right">Counted</th><th className="text-right">Variance</th><th className="text-right">Value</th>
                          </tr>
                        </thead>
                        <tbody>
                          {day.lines.map((line) => (
                            <tr key={`${day.closing_id}-${line.product_id}`} className={`border-t border-[#EFF2F2] ${line.has_variance ? "bg-[#FFF7ED]" : "bg-white"}`}>
                              <td className="px-2 py-2 font-medium">{line.product_name}</td>
                              <td className="text-[#637877]">{line.product_code || line.product_sku || "-"}</td>
                              <td>{line.uom_code}</td>
                              <td className="text-right">{qty(line.opening_qty)}</td>
                              <td className="text-right">{qty(line.receipts_qty)}</td>
                              <td className="text-right">{qty(line.boh_to_front_qty)}</td>
                              <td className="text-right">{qty(line.sales_consumption_qty)}</td>
                              <td className="text-right">{qty(line.other_issues_qty)}</td>
                              <td className="text-right">{qty(line.adjustments_qty)}</td>
                              <td className="text-right">{qty(line.expected_closing_qty)}</td>
                              <td className="text-right">{line.counted_closing_qty === null ? "-" : qty(line.counted_closing_qty)}</td>
                              <td className={`text-right ${Number(line.variance_qty || 0) < 0 ? "text-[#B42318]" : Number(line.variance_qty || 0) > 0 ? "text-[#027A48]" : ""}`}>{line.variance_qty === null ? "-" : qty(line.variance_qty)}</td>
                              <td className="text-right">{line.variance_value === null ? "-" : money(line.variance_value, line.currency_code)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </section>
                ))}
              </div>
            ) : (
              <div className="border border-[#C4CFCF] bg-white overflow-x-auto">
                <table className="sx-enterprise-table min-w-[1640px] w-full text-left text-[10.5px]">
                  <thead className="bg-[#003A3A] text-[9px] uppercase text-white">
                    <tr><th className="px-2 py-2">Created</th><th>Date</th><th>Product</th><th>Code</th><th>Movement</th><th>Stock Area</th><th>From Area</th><th>To Area</th><th className="text-right">In</th><th className="text-right">Out</th><th className="text-right">Balance</th><th className="text-right">Cost</th><th>Reference</th><th>Notes</th></tr>
                  </thead>
                  <tbody>
                    {movementDetail.map((row, index) => (
                      <tr key={`${row.created_at}-${row.product_id}-${index}`} className="border-t border-[#EFF2F2]">
                        <td className="px-2 py-2">{row.created_at}</td><td>{formatDate(row.movement_date)}</td><td className="font-medium">{row.product_name}</td><td className="text-[#637877]">{row.product_code || "-"}</td><td>{row.movement_type}</td><td>{row.stock_area || "-"}</td><td>{row.from_stock_area || "-"}</td><td>{row.to_stock_area || "-"}</td><td className="text-right">{qty(row.quantity_in)}</td><td className="text-right">{qty(row.quantity_out)}</td><td className="text-right">{row.balance_after === null ? "-" : qty(row.balance_after)}</td><td className="text-right">{money(row.total_cost, row.currency_code)}</td><td>{row.reference_type}</td><td>{row.notes}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              )}
              </div>
              </ReportViewShell>
            )}
            </div>
          </div>
        </div>
      </div>
    </FullScreenModal>

    <ReportOutputChooser
      open={outputChooserOpen}
      reportTitle="Closing Stock Movement Report"
      busy={loading || !!exportingFormat}
      busyLabel={loading ? "Loading closing stock movements from the backend…" : "Generating backend download…"}
      onClose={() => setOutputChooserOpen(false)}
      onView={generated ? undefined : viewSelectedReport}
      onDownloadPdf={() => downloadChosenFormat("pdf")}
      onDownloadExcel={() => downloadChosenFormat("xlsx")}
      outputs={{
        view: generated
          ? { available: false, reason: "The report is already open." }
          : { available: selectedClosingIds.length > 0, reason: selectedClosingIds.length ? "" : "Select at least one closing stock day." },
        pdf: { available: (generated ? appliedClosingIds : selectedClosingIds).length > 0 },
        excel: { available: (generated ? appliedClosingIds : selectedClosingIds).length > 0 },
      }}
    />
    </>
  );
};

const Metric = ({ label, value, danger = false }) => (
  <div className="bg-white p-3">
    <div className="text-[9px] uppercase text-[#637877]">{label}</div>
    <div className={`mt-1 text-[15px] font-medium tabular-nums ${danger ? "text-[#B42318]" : "text-[#122424]"}`}>{value}</div>
  </div>
);

export default ClosingStockMovementReportModal;

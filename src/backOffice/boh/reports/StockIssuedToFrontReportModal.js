import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw, Search, X } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import HospitalityReportNavigator, {
  HOSPITALITY_REPORT_KEYS,
} from "../../../components/HospitalityReportNavigator";
import {
  downloadStockIssuedToFrontExcel,
  downloadStockIssuedToFrontPdf,
  getStockIssuedToFrontReport,
} from "../../../services/StockIssuedToFrontReportService";

const today = () => new Date().toISOString().slice(0, 10);
const money = (v, c = "USD") => `${c || "USD"} ${Number(v || 0).toFixed(2)}`;
const qty = (v) => Number(v || 0).toFixed(4);
const snapshotFilters = (value = {}) => ({ ...value, currency_codes: [...(value.currency_codes || [])] });

const fmtDate = (value) => {
  if (!value) return "-";
  const d = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
};

const StockIssuedToFrontReportModal = ({ open, onClose, onNavigateReport }) => {
  const [scopeFilters, setScopeFilters] = useState({ date_from: today(), date_to: today(), branch_id: "", till_id: "", cashier_id: "", shift_session_id: "", currency_codes: [], business_mode: "fast_food_boh" });
  const [search, setSearch] = useState("");
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [downloading, setDownloading] = useState("");
  const [notice, setNotice] = useState("");
  const [viewing, setViewing] = useState(false);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState("configuration");
  const [outputFilters, setOutputFilters] = useState(null);
  const [appliedFilters, setAppliedFilters] = useState(null);

  const filters = useMemo(() => ({ ...scopeFilters, search }), [scopeFilters, search]);
  const activeFilters = appliedFilters || filters;
  const lookups = useHospitalityReportLookups(open, scopeFilters);
  const dateFrom = activeFilters.date_from;
  const dateTo = activeFilters.date_to;
  const days = report?.days || [];
  const totals = report?.summary?.currency_totals || [];

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4200);
  };

  const loadReport = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setReport(null);
      const data = await getStockIssuedToFrontReport(nextFilters);
      setReport(data);
      return true;
    } catch (error) {
      setReport(null);
      showNotice(error?.response?.data?.message || error?.message || "Failed to load report.");
      return false;
    } finally {
      setLoading(false);
    }
  };

  const openChooser = (source = "configuration") => {
    const nextFilters = source === "result" && appliedFilters ? appliedFilters : filters;
    setChooserSource(source);
    setOutputFilters(snapshotFilters(nextFilters));
    setChooserOpen(true);
  };

  const viewReport = async () => {
    const nextFilters = snapshotFilters(outputFilters || filters);
    setChooserOpen(false);
    setViewing(false);
    setAppliedFilters(null);
    const loaded = await loadReport(nextFilters);
    if (loaded) {
      setAppliedFilters(nextFilters);
      setViewing(true);
    }
  };

  const refreshReport = async () => {
    const nextFilters = snapshotFilters(appliedFilters || filters);
    const loaded = await loadReport(nextFilters);
    if (!loaded) {
      setAppliedFilters(null);
      setViewing(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    setReport(null);
    setViewing(false);
    setAppliedFilters(null);
    setOutputFilters(null);
    setChooserOpen(false);
    setNotice("");
  }, [open]);

  const filenameBase = `stock-issued-to-front-${dateFrom}-to-${dateTo}`;

  const downloadReport = async (format) => {
    try {
      setDownloading(format);
      const nextFilters = snapshotFilters(outputFilters || appliedFilters || filters);
      if (format === "excel") await downloadStockIssuedToFrontExcel(nextFilters, `${filenameBase}.xlsx`);
      else await downloadStockIssuedToFrontPdf(nextFilters, `${filenameBase}.pdf`);
      setChooserOpen(false);
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Report download failed.");
      setChooserOpen(false);
    } finally {
      setDownloading("");
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title="Stock Issued To Front Report" subtitle="Opening stock, BOH issue, available stock and issue cost">
      <div className="relative flex h-full min-h-0 overflow-hidden bg-[#F3F7F6] text-[#122424] text-[11px]">
        {notice && (
          <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 border border-[#122424] bg-white px-4 py-2 text-[11px] font-medium shadow-xl">
            {notice}
          </div>
        )}

        <HospitalityReportNavigator
          section="inventory"
          activeKey={HOSPITALITY_REPORT_KEYS.STOCK_ISSUED}
          onNavigate={onNavigateReport}
        />
        <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        {!viewing && <div className="shrink-0 bg-white">
          <HospitalityReportScopePanel filters={scopeFilters} setFilters={setScopeFilters} lookups={lookups} loading={loading} onApply={() => openChooser("configuration")} supported={{ till_id: false, cashier_id: false, shift_session_id: false, currency_codes: false }} />
          <div className="border-b border-[#CBD6D6] bg-white px-4 py-3">
            <label>
              <div className="mb-1 text-[9px] uppercase tracking-wide text-[#637877]">Search product / code / notes</div>
              <div className="flex h-9 border border-[#BAC5C5] bg-white focus-within:border-[#324444]">
                <div className="flex w-9 items-center justify-center border-r border-[#E2E9E9]"><Search className="h-4 w-4 text-[#637877]" /></div>
                <input value={search} onChange={(e) => setSearch(e.target.value)} className="min-w-0 flex-1 px-2 text-[12px] outline-none" placeholder="Search issued stock" />
              </div>
            </label>
          </div>
        </div>}

        {!viewing ? (
          <ReportGenerationPrompt
            title="Configure Stock Issues Report"
            message="Choose the branch, date range and optional product search, then select Generate."
          />
        ) : <ReportViewShell
          title="Stock Issued To Front Report"
          subtitle="Opening stock, BOH issue, available stock and issue cost"
          backLabel="Configuration / Back"
          onBackToFilters={() => setViewing(false)}
          busy={loading}
          actions={<>
            <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AEB9B9] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
            <button type="button" onClick={() => openChooser("result")} disabled={loading || !!downloading} className="inline-flex h-8 items-center gap-1 border border-[#069494] bg-white px-3 text-[11px] font-semibold text-[#008585] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
          </>}
        >
        <div className="shrink-0 border-b border-[#CBD6D6] bg-[#F8FAFC] px-4 py-3">
          <div className="grid gap-2 md:grid-cols-4">
            <SummaryCard label="Movements" value={report?.summary?.movement_count || 0} />
            <SummaryCard label="Products" value={report?.summary?.distinct_products || 0} />
            <SummaryCard label="Days" value={report?.summary?.distinct_days || 0} />
            <SummaryCard label="Range" value={`${fmtDate(dateFrom)} - ${fmtDate(dateTo)}`} />
          </div>
          {totals.length > 0 && (
            <div className="mt-2 grid gap-2 md:grid-cols-3">
              {totals.map((row) => (
                <div key={row.currency_code} className="border border-[#CBD6D6] bg-white p-2">
                  <div className="text-[9px] uppercase tracking-wide text-[#637877]">Issue value - {row.currency_code}</div>
                  <div className="mt-1 text-[15px] font-medium tabular-nums">{money(row.total_cost, row.currency_code)}</div>
                  <div className="text-[10px] text-[#637877]">Qty {qty(row.issued_quantity)} · {row.movement_count} movements</div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="min-h-0 flex-1 overflow-auto p-3">
          {loading ? (
            <div className="flex h-full items-center justify-center text-[#637877]"><RefreshCw className="mr-2 h-5 w-5 animate-spin" /> Loading stock issued report...</div>
          ) : days.length === 0 ? (
            <div className="flex h-full flex-col items-center justify-center text-[#637877]"><X className="mb-2 h-7 w-7" />No BOH stock issued to front for this date range.</div>
          ) : (
            days.map((day) => (
              <section key={day.issue_date} className="mb-3 border border-[#CBD6D6] bg-white">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#CBD6D6] bg-[#003A3A] px-3 py-2 text-white">
                  <div>
                    <div className="text-[13px] font-medium">{fmtDate(day.issue_date)}</div>
                    <div className="text-[10px] text-white/70">{day.movement_count} movements · {day.distinct_products} products</div>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {(day.currency_totals || []).map((ct) => (
                      <div key={ct.currency_code} className="border border-white/20 px-2 py-1 text-right">
                        <div className="text-[9px] text-white/60">{ct.currency_code}</div>
                        <div className="text-[11px] font-medium">{money(ct.total_cost, ct.currency_code)}</div>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="overflow-x-auto">
                  <table className="sx-enterprise-table min-w-[1650px] w-full text-left text-[10.5px]">
                    <thead className="bg-[#E2E9E9] text-[9px] uppercase tracking-wide text-[#324444]">
                      <tr>
                        <th className="px-2 py-2">Product</th>
                        <th className="px-2 py-2">Product Type</th>
                        <th className="px-2 py-2">Code</th>
                        <th className="px-2 py-2">SKU</th>
                        <th className="px-2 py-2">UOM</th>
                        <th className="px-2 py-2 text-right">Opening Front</th>
                        <th className="px-2 py-2 text-right">Issued Qty</th>
                        <th className="px-2 py-2 text-right">Front After</th>
                        <th className="px-2 py-2 text-right">BOH Before</th>
                        <th className="px-2 py-2 text-right">BOH After</th>
                        <th className="px-2 py-2 text-right">Avg Cost</th>
                        <th className="px-2 py-2 text-right">Issue Cost</th>
                        <th className="px-2 py-2">Currency</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(day.product_summary || []).map((row) => (
                        <tr key={`${row.product_id}-${row.currency_code}`} className="border-t border-[#E2E9E9] odd:bg-white even:bg-[#F8FAFC]">
                          <td className="px-2 py-2 font-medium">{row.product_name}</td>
                          <td className="px-2 py-2">{row.product_type || "-"}</td>
                          <td className="px-2 py-2 text-[#637877]">{row.product_code || "-"}</td>
                          <td className="px-2 py-2 text-[#637877]">{row.product_sku || "-"}</td>
                          <td className="px-2 py-2">{row.uom_code || "EACH"}</td>
                          <td className="px-2 py-2 text-right tabular-nums">{qty(row.front_opening_qty)}</td>
                          <td className="px-2 py-2 text-right font-medium tabular-nums">{qty(row.issued_quantity)}</td>
                          <td className="px-2 py-2 text-right tabular-nums">{qty(row.front_available_after_issue)}</td>
                          <td className="px-2 py-2 text-right tabular-nums">{qty(row.back_opening_before_issue)}</td>
                          <td className="px-2 py-2 text-right tabular-nums">{qty(row.back_available_after_issue)}</td>
                          <td className="px-2 py-2 text-right tabular-nums">{money(row.average_unit_cost, row.currency_code)}</td>
                          <td className="px-2 py-2 text-right font-medium tabular-nums">{money(row.total_cost, row.currency_code)}</td>
                          <td className="px-2 py-2">{row.currency_code}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ))
          )}
        </div>
        </ReportViewShell>}
        </div>
      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Stock Issued To Front Report"
        busy={!!downloading}
        busyLabel="Preparing stock issue report…"
        onClose={() => setChooserOpen(false)}
        onView={viewReport}
        onDownloadPdf={() => downloadReport("pdf")}
        onDownloadExcel={() => downloadReport("excel")}
        outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
      />
    </FullScreenModal>
  );
};

const SummaryCard = ({ label, value }) => (
  <div className="border border-[#CBD6D6] bg-white p-2">
    <div className="text-[9px] uppercase tracking-wide text-[#637877]">{label}</div>
    <div className="mt-1 truncate text-[15px] font-medium tabular-nums" title={String(value)}>{value}</div>
  </div>
);

export default StockIssuedToFrontReportModal;

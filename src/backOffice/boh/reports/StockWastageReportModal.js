import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw, Search } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import HospitalityReportNavigator, {
  HOSPITALITY_REPORT_KEYS,
} from "../../../components/HospitalityReportNavigator";
import { downloadStockWastageExcel, downloadStockWastagePdf, getStockWastageReport } from "../../../services/StockWastageService";

const today = () => new Date().toISOString().slice(0, 10);
const qty = (v) => Number(v || 0).toFixed(4);
const money = (v, c = "USD") => `${c || "USD"} ${Number(v || 0).toFixed(2)}`;
const formatDate = (v) => {
  const d = new Date(`${String(v || "").slice(0, 10)}T00:00:00`);
  return Number.isNaN(d.getTime()) ? v || "-" : d.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
};

const REASONS = ["", "Expired", "Damaged", "Spoilt", "Over production", "Kitchen error", "Quality reject", "Other"];
const snapshotFilters = (value = {}) => ({ ...value, currency_codes: [...(value.currency_codes || [])] });

const StockWastageReportModal = ({ open, onClose, onNavigateReport }) => {
  const [filters, setFilters] = useState({ date_from: today(), date_to: today(), branch_id: "", till_id: "", cashier_id: "", shift_session_id: "", currency_codes: [], business_mode: "fast_food_boh", stock_area: "", reason_code: "", search: "" });
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [notice, setNotice] = useState("");
  const [viewing, setViewing] = useState(false);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState("configuration");
  const [outputFilters, setOutputFilters] = useState(null);
  const [appliedFilters, setAppliedFilters] = useState(null);
  const lookups = useHospitalityReportLookups(open, filters);

  const showNotice = (m) => { setNotice(m); setTimeout(() => setNotice(""), 3500); };
  const update = (key, value) => {
    setFilters((p) => ({ ...p, [key]: value }));
  };

  const load = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setReport(null);
      setReport(await getStockWastageReport(nextFilters));
      return true;
    } catch (e) {
      setReport(null);
      showNotice(e?.response?.data?.message || e.message || "Failed to load report.");
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
    const loaded = await load(nextFilters);
    if (loaded) {
      setAppliedFilters(nextFilters);
      setViewing(true);
    }
  };

  const refreshReport = async () => {
    const nextFilters = snapshotFilters(appliedFilters || filters);
    const loaded = await load(nextFilters);
    if (!loaded) {
      setAppliedFilters(null);
      setViewing(false);
    }
  };

  const downloadReport = async (format) => {
    try {
      setExporting(true);
      const nextFilters = snapshotFilters(outputFilters || appliedFilters || filters);
      if (format === "excel") await downloadStockWastageExcel(nextFilters);
      else await downloadStockWastagePdf(nextFilters);
      setChooserOpen(false);
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Report download failed.");
      setChooserOpen(false);
    } finally {
      setExporting(false);
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

  const currencyTotals = report?.summary?.currency_totals || [];
  const topProducts = useMemo(() => {
    const map = new Map();
    (report?.lines || []).forEach((line) => {
      const key = line.product_id;
      const row = map.get(key) || { product_name: line.product_name, total_quantity: 0, total_cost: 0, currency_code: line.currency_code || "USD" };
      row.total_quantity += Number(line.wastage_quantity || 0);
      row.total_cost += Number(line.total_cost || 0);
      map.set(key, row);
    });
    return [...map.values()].sort((a,b)=>b.total_cost-a.total_cost).slice(0,8);
  }, [report]);
  const maxCost = Math.max(1, ...topProducts.map((x)=>x.total_cost));

  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title="Stock Wastage Report" subtitle="Daily stock loss, reasons, cost and closing impact">
      <div className="relative flex h-full min-h-0 overflow-hidden bg-[#EFF3F3] text-[#122424] text-[11px]">
        {notice && <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 border border-[#122424] bg-white px-4 py-2 shadow-lg font-semibold">{notice}</div>}
        <HospitalityReportNavigator
          section="inventory"
          activeKey={HOSPITALITY_REPORT_KEYS.STOCK_WASTAGE}
          onNavigate={onNavigateReport}
        />
        <div className="flex min-w-0 flex-1 flex-col overflow-hidden">

        {!viewing && <div className="shrink-0 bg-white">
          <HospitalityReportScopePanel filters={filters} setFilters={setFilters} lookups={lookups} loading={loading} onApply={() => openChooser("configuration")} supported={{ till_id: false, cashier_id: false, shift_session_id: false, currency_codes: false }} />
          <div className="grid gap-2 border-b border-[#CBD6D6] bg-white p-3 xl:grid-cols-[140px_170px_1fr]">
            <label><div className="mb-1 text-[9px] uppercase text-[#637877]">Stock Area</div><select value={filters.stock_area} onChange={(e)=>update('stock_area', e.target.value)} className="h-9 w-full border border-[#CBD6D6] px-2 outline-none"><option value="">ALL</option><option value="front">FRONT</option><option value="back">BOH / BACK</option></select></label>
            <label><div className="mb-1 text-[9px] uppercase text-[#637877]">Reason</div><select value={filters.reason_code} onChange={(e)=>update('reason_code', e.target.value)} className="h-9 w-full border border-[#CBD6D6] px-2 outline-none">{REASONS.map((r)=><option key={r} value={r}>{r || 'ALL'}</option>)}</select></label>
            <label><div className="mb-1 text-[9px] uppercase text-[#637877]">Search</div><div className="flex h-9 border border-[#CBD6D6] bg-white"><span className="flex w-9 items-center justify-center border-r border-[#E2E9E9]"><Search className="h-4 w-4" /></span><input value={filters.search} onChange={(e)=>update('search', e.target.value)} placeholder="Product, code, SKU, reference" className="flex-1 px-2 outline-none" /></div></label>
          </div>
        </div>}

        {!viewing ? (
          <ReportGenerationPrompt
            title="Configure Stock Wastage Report"
            message="Choose the branch, period, stock area and reason, then select Generate."
          />
        ) : <ReportViewShell
          title="Stock Wastage Report"
          subtitle="Daily stock loss, reasons, cost and closing impact"
          backLabel="Configuration / Back"
          onBackToFilters={() => setViewing(false)}
          busy={loading}
          actions={<>
            <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AEB9B9] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
            <button type="button" onClick={() => openChooser("result")} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069494] bg-white px-3 text-[11px] font-semibold text-[#008585] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
          </>}
        >
        <div className="p-3">
          <div className="grid gap-2 md:grid-cols-4">
            <Summary label="Wastage Days" value={report?.summary?.wastage_days || 0} />
            <Summary label="Lines" value={report?.summary?.line_count || 0} />
            <Summary label="Total Qty" value={qty(report?.summary?.total_quantity || 0)} />
            <Summary label="Total Cost" value={currencyTotals.map((c)=>money(c.total_cost,c.currency_code)).join(' / ') || '0.00'} />
          </div>

          {topProducts.length > 0 && <div className="mt-3 border border-[#CBD6D6] bg-white p-3">
            <div className="mb-2 text-[10px] uppercase tracking-wide text-[#324444]">Top Wastage Cost Products</div>
            <div className="space-y-2">
              {topProducts.map((p)=><div key={p.product_name} className="grid grid-cols-[220px_1fr_130px] items-center gap-3"><div className="truncate font-semibold">{p.product_name}</div><div className="h-5 border border-[#CBD6D6] bg-[#F8FAFC]"><div className="h-full bg-[#145151]" style={{width:`${Math.max(4,(p.total_cost/maxCost)*100)}%`}} /></div><div className="text-right">{money(p.total_cost,p.currency_code)}</div></div>)}
            </div>
          </div>}

          {(report?.days || []).map((day)=>(
            <section key={day.wastage_date} className="mt-3 border border-[#CBD6D6] bg-white">
              <div className="grid grid-cols-[1fr_180px_260px] gap-2 border-b border-[#CBD6D6] bg-[#003A3A] px-3 py-2 text-white">
                <div><div className="text-[13px] font-semibold">{formatDate(day.wastage_date)}</div><div className="text-[9px] text-white/70">Grouped daily wastage stock flow</div></div>
                <div className="text-right"><div className="text-[9px] text-white/70">Total Qty</div><b>{qty(day.total_quantity)}</b></div>
                <div className="text-right"><div className="text-[9px] text-white/70">Cost</div>{(day.currency_totals || []).map((c)=><b key={c.currency_code} className="ml-2">{money(c.total_cost,c.currency_code)}</b>)}</div>
              </div>
              <div className="overflow-x-auto">
                <table className="sx-enterprise-table min-w-[1460px] w-full text-[10.5px]">
                  <thead className="bg-[#F8FAFC] text-[9px] uppercase text-[#465858]">
                    <tr><th className="px-2 py-2 text-left">Reference</th><th className="px-2 text-left">Product</th><th className="px-2 text-left">Code</th><th className="px-2 text-left">SKU</th><th className="px-2">Area</th><th className="px-2">Reason</th><th className="px-2 text-right">Opening</th><th className="px-2 text-right">Wasted</th><th className="px-2 text-right">Closing</th><th className="px-2 text-right">Unit Cost</th><th className="px-2 text-right">Cost</th><th className="px-2">By</th><th className="px-2 text-left">Notes</th></tr>
                  </thead>
                  <tbody>
                    {(day.lines || []).map((line) => (
                      <tr key={line.id} className="border-t border-[#EEF3F2]">
                        <td className="px-2 py-2">{line.reference_no}</td>
                        <td className="px-2 font-semibold">{line.product_name}</td>
                        <td className="px-2 text-[#637877]">{line.product_code || "-"}</td>
                        <td className="px-2 text-[#637877]">{line.product_sku || "-"}</td>
                        <td className="px-2 text-center uppercase">{line.stock_area}</td>
                        <td className="px-2 text-center">{line.reason_code || "-"}</td>
                        <td className="px-2 text-right">{qty(line.opening_quantity)}</td>
                        <td className="px-2 text-right font-semibold text-[#B42318]">{qty(line.wastage_quantity)}</td>
                        <td className="px-2 text-right">{qty(line.closing_quantity)}</td>
                        <td className="px-2 text-right">{money(line.unit_cost, line.currency_code)}</td>
                        <td className="px-2 text-right font-semibold text-[#B42318]">{money(line.total_cost, line.currency_code)}</td>
                        <td className="px-2 text-center">{line.created_by_name}</td>
                        <td className="px-2">{line.notes || "-"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          ))}

          {!loading && !(report?.days || []).length && <div className="mt-3 border border-[#CBD6D6] bg-white p-10 text-center text-[#637877]">No wastage records found for selected filters.</div>}
        </div>
        </ReportViewShell>}
        </div>
      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Stock Wastage Report"
        busy={exporting}
        busyLabel="Preparing stock wastage report…"
        onClose={() => setChooserOpen(false)}
        onView={viewReport}
        onDownloadPdf={() => downloadReport("pdf")}
        onDownloadExcel={() => downloadReport("excel")}
        outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
      />
    </FullScreenModal>
  );
};

const Summary = ({ label, value }) => <div className="border border-[#CBD6D6] bg-white p-3"><div className="text-[9px] uppercase text-[#637877]">{label}</div><div className="mt-1 truncate text-[16px] font-semibold">{value}</div></div>;

export default StockWastageReportModal;

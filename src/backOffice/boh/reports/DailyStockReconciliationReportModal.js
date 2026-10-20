import React, { useEffect, useMemo, useState } from "react";
import { Download, FileText } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import {
  downloadMenuRecipeDsrExcel,
  downloadMenuRecipeDsrPdf,
  getCurrentTradingDay,
  getSavedMenuRecipeDsrReport,
  getSavedMenuRecipeDsrReports,
} from "../../../services/MenuRecipeDsrService";

const money = (value, currency = "USD") => `${currency} ${Number(value || 0).toFixed(2)}`;
const qty = (value) => Number(value || 0).toFixed(4);

const normalizePagination = (payload) => {
  const data = payload?.data?.data || payload?.data || [];
  return {
    rows: Array.isArray(data) ? data : [],
    currentPage: payload?.current_page || payload?.data?.current_page || 1,
    lastPage: payload?.last_page || payload?.data?.last_page || 1,
    total: payload?.total || payload?.data?.total || (Array.isArray(data) ? data.length : 0),
  };
};

const ClosedMenuRecipeDailyStockReconciliationModal = ({ open, onClose }) => {
  const [rows, setRows] = useState([]);
  const [report, setReport] = useState(null);
  const [scopeFilters, setScopeFilters] = useState({ date_from: "", date_to: "", branch_id: "", till_id: "", cashier_id: "", shift_session_id: "", currency_codes: [], business_mode: "fast_food_boh" });
  const [selectedMenuIds, setSelectedMenuIds] = useState([]);
  const [allSelected, setAllSelected] = useState(true);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const lookups = useHospitalityReportLookups(open, scopeFilters);

  const lines = report?.lines || [];
  const menuLines = useMemo(() => lines.filter((line) => line.section === "menu_summary"), [lines]);
  const recipeLines = useMemo(() => lines.filter((line) => line.section === "menu_recipe"), [lines]);

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4500);
  };

  const openReport = async (id) => {
    try {
      setLoading(true);
      const data = await getSavedMenuRecipeDsrReport(id);
      setReport(data);
      const ids = (data?.lines || []).filter((line) => line.section === "menu_summary").map((line) => String(line.menu_product_id));
      setSelectedMenuIds(ids);
      setAllSelected(true);
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Failed to open closed DSR report.");
    } finally {
      setLoading(false);
    }
  };

  const loadReports = async (nextFilters = scopeFilters) => {
    try {
      setLoading(true);
      const payload = await getSavedMenuRecipeDsrReports({ date_from: nextFilters.date_from || undefined, date_to: nextFilters.date_to || undefined, per_page: 50 });
      const normalized = normalizePagination(payload);
      setRows(normalized.rows);
      if (normalized.rows.length) await openReport(normalized.rows[0].id);
      else setReport(null);
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Failed to load closed reports.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    const initialize = async () => {
      try {
        const day = await getCurrentTradingDay();
        const tradingDate = day?.trading_date || "";
        const authoritativeFilters = { ...scopeFilters, date_to: tradingDate };
        setScopeFilters(authoritativeFilters);
        await loadReports(authoritativeFilters);
      } catch (error) {
        showNotice(error?.response?.data?.message || error?.message || "Failed to resolve the current trading date.");
        await loadReports({ ...scopeFilters, date_to: "" });
      }
    };
    initialize();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const params = () => ({ menu_product_ids: allSelected ? undefined : selectedMenuIds });

  const downloadExcel = async () => {
    if (!report?.id) return showNotice("Select a closed report first.");
    if (!allSelected && selectedMenuIds.length === 0) return showNotice("Select at least one menu item.");
    await downloadMenuRecipeDsrExcel(report.id, `closed-dsr-${report.trading_date || report.id}.xls`, params());
  };

  const downloadPdf = async () => {
    if (!report?.id) return showNotice("Select a closed report first.");
    if (!allSelected && selectedMenuIds.length === 0) return showNotice("Select at least one menu item.");
    await downloadMenuRecipeDsrPdf(report.id, `closed-dsr-${report.trading_date || report.id}.pdf`, params());
  };

  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title="Closed DSR Reports" subtitle="Select a closed Day End, choose menu items, then download Excel or PDF">
      <div className="relative flex h-[calc(100vh-86px)] flex-col overflow-hidden bg-[#F3F6F6] text-[#091B1B]">
        {notice && <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 rounded-md bg-[#003434] px-4 py-2 text-[12px] text-white shadow-xl">{notice}</div>}
        <HospitalityReportScopePanel filters={scopeFilters} setFilters={setScopeFilters} lookups={lookups} loading={loading} onApply={() => loadReports()} periodLabel="Trading Date Range" supported={{ branch_id: false, till_id: false, cashier_id: false, shift_session_id: false, currency_codes: false }} actions={<><button type="button" onClick={downloadExcel} disabled={!report} className="inline-flex h-9 items-center gap-2 border border-[#057B7B] bg-[#057B7B] px-3 text-[11px] font-semibold text-white disabled:opacity-50"><Download className="h-4 w-4" />Excel</button><button type="button" onClick={downloadPdf} disabled={!report} className="inline-flex h-9 items-center gap-2 border border-[#7C2D12] bg-[#7C2D12] px-3 text-[11px] font-semibold text-white disabled:opacity-50"><FileText className="h-4 w-4" />PDF</button></>} />
        <div className="flex min-h-0 flex-1">
          <aside className="w-[360px] shrink-0 border-r border-[#D1D8D7] bg-white p-4">
            <div className="max-h-[34vh] overflow-y-auto rounded-xl border border-[#D1D8D7]">
              {rows.map((row) => (
                <button key={row.id} type="button" onClick={() => openReport(row.id)} className={`block w-full border-b border-[#EFF2F2] px-4 py-3 text-left text-[12px] hover:bg-[#F8FAFC] ${report?.id === row.id ? "bg-[#EFF7F7]" : "bg-white"}`}>
                  <span className="block font-semibold">Trading date {row.trading_date}</span>
                  <span className="block text-[11px] text-[#637877]">{row.status || "final"} · {row.currency_code || "MULTI"}</span>
                </button>
              ))}
            </div>

            <div className="mt-4 rounded-xl border border-[#D1D8D7]">
              <div className="border-b border-[#E4E8E8] px-4 py-3 text-[12px] font-semibold uppercase text-[#324444]">Menu item filter</div>
              <label className="flex cursor-pointer items-center gap-3 border-b border-[#EFF2F2] px-4 py-3 text-[12px] font-semibold">
                <input type="checkbox" checked={allSelected} onChange={(e) => { const checked = e.target.checked; setAllSelected(checked); setSelectedMenuIds(checked ? menuLines.map((line) => String(line.menu_product_id)) : []); }} /> All menu items
              </label>
              <div className="max-h-[24vh] overflow-y-auto">
                {menuLines.map((line) => {
                  const id = String(line.menu_product_id);
                  return <label key={`${id}-${line.currency_code}`} className="flex cursor-pointer gap-3 border-b border-[#F1F5F5] px-4 py-3 text-[12px] hover:bg-[#F8FAFC]">
                    <input type="checkbox" checked={selectedMenuIds.includes(id)} onChange={() => {
                      const next = selectedMenuIds.includes(id) ? selectedMenuIds.filter((item) => item !== id) : [...selectedMenuIds, id];
                      setSelectedMenuIds(next); setAllSelected(next.length === menuLines.length);
                    }} />
                    <span><span className="block font-semibold">{line.menu_product_name}</span><span className="block text-[11px] text-[#637877]">{line.currency_code} · {money(line.menu_revenue, line.currency_code)}</span></span>
                  </label>;
                })}
              </div>
            </div>
          </aside>

          <main className="flex min-w-0 flex-1 flex-col">
            <div className="shrink-0 border-b border-[#D1D8D7] bg-white p-4">
              <div className="flex items-center justify-between gap-3">
                <div><div className="text-[20px] font-semibold">{report ? `Trading Date ${report.trading_date}` : "No report selected"}</div><div className="text-[12px] text-[#637877]">Closed Day End report with menu-level ingredients and expected profit.</div></div>
              </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-4">
              {menuLines.filter((menu) => allSelected || selectedMenuIds.includes(String(menu.menu_product_id))).map((menu) => {
                const children = recipeLines.filter((line) => String(line.menu_product_id) === String(menu.menu_product_id) && line.currency_code === menu.currency_code);
                return <section key={`${menu.menu_product_id}-${menu.currency_code}`} className="mb-4 rounded-xl border border-[#D1D8D7] bg-white shadow-sm">
                  <div className="border-b border-[#E4E8E8] bg-[#F8FAFC] p-4"><div className="flex justify-between gap-3"><div><div className="text-[18px] font-semibold">{menu.menu_product_name}</div><div className="text-[12px] text-[#637877]">{menu.menu_product_code || "NO CODE"} · {menu.currency_code}</div></div><div className="text-right text-[12px]"><div>Revenue <b>{money(menu.menu_revenue, menu.currency_code)}</b></div><div>Cost <b>{money(menu.menu_recipe_cost, menu.currency_code)}</b></div><div>Expected Profit <b>{money(menu.menu_gross_profit, menu.currency_code)}</b></div></div></div></div>
                  <div className="overflow-x-auto"><table className="sx-enterprise-table min-w-[900px] w-full text-[12px]"><thead className="text-left uppercase text-[#637877]"><tr><th className="px-3 py-3">Ingredient</th><th>UOM</th><th className="text-right">Recipe Qty</th><th className="text-right">Consumed</th><th className="text-right">Unit Cost</th><th className="text-right">Total Cost</th></tr></thead><tbody>{children.map((line) => <tr key={`${menu.menu_product_id}-${line.ingredient_product_id}`} className="border-t border-[#EFF2F2]"><td className="px-3 py-3 font-semibold">{line.ingredient_name}</td><td>{line.uom_code}</td><td className="text-right">{qty(line.recipe_qty_per_menu)}</td><td className="text-right">{qty(line.ingredient_consumed_qty)}</td><td className="text-right">{money(line.unit_cost, line.currency_code)}</td><td className="text-right font-semibold">{money(line.ingredient_total_cost, line.currency_code)}</td></tr>)}</tbody></table></div>
                </section>;
              })}
            </div>
          </main>
        </div>
      </div>
    </FullScreenModal>
  );
};

export default ClosedMenuRecipeDailyStockReconciliationModal;

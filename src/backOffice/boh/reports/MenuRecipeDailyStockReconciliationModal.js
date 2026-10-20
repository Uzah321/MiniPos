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
  downloadLiveMenuRecipeDsrExcel,
  downloadLiveMenuRecipeDsrPdf,
  downloadMenuRecipeDsrExcel,
  downloadMenuRecipeDsrPdf,
  getCurrentTradingDay,
  getLiveMenuRecipeDsr,
  getSavedMenuRecipeDsrReport,
  getSavedMenuRecipeDsrReports,
} from "../../../services/MenuRecipeDsrService";

const money = (value, currency = "USD") => `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;
const qty = (value) => Number(value || 0).toFixed(4);
const pct = (value) => `${Number(value || 0).toFixed(2)}%`;

const PALETTE = ["#057B7B", "#CA8A04", "#7C2D12", "#9333EA", "#B45309", "#65A30D", "#166534", "#BE123C"];

const REPORT_VIEWS = [
  { key: "menu", label: "Menu Item Flow", description: "Recipe consumption and stock flow by sold menu item" },
  { key: "shared", label: "Shared Ingredient Flow", description: "Shared ingredient usage across menu items" },
  { key: "profit", label: "Profit Summary", description: "Recipe cost, gross profit and food-cost percentage" },
];

const formatDate = (value) => {
  if (!value) return "-";
  const d = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
};

const lineSection = (line) => String(line?.section || line?.line_type || "").toLowerCase();
const getLines = (report) => report?.lines || report?.data?.lines || [];
const getReportId = (report) => report?.id || report?.data?.id;
const isMenuLine = (line) => ["menu_summary", "menu", "menu_item", "sales_menu"].includes(lineSection(line));
const isRecipeLine = (line) => ["menu_recipe", "recipe_consumption", "ingredient_consumption", "menu_ingredient"].includes(lineSection(line));
const isIngredientRecon = (line) => ["ingredient_reconciliation", "ingredient_recon", "stock_reconciliation"].includes(lineSection(line));

const menuIdOf = (line) => String(line?.menu_product_id || line?.product_id || "");
const currencyOf = (line) => String(line?.currency_code || line?.sale_currency_code || "USD").toUpperCase();
const ingredientIdOf = (line) => String(line?.ingredient_product_id || line?.product_id || "");

export const buildIngredientUsageVariance = (lines = []) => lines.map((line) => {
  const expectedUsage = Number(line.sales_consumption_qty || 0);
  const variance = Number(line.variance_qty || 0);
  return {
    id: ingredientIdOf(line),
    name: line.ingredient_name || "Unnamed ingredient",
    code: line.ingredient_code || "-",
    uom: line.uom_code || "EACH",
    expectedUsage,
    actualUsage: expectedUsage - variance,
    variance,
  };
});

function CompactDropdown({ label, value, children, width = "w-[280px]" }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    const close = (e) => {
      if (!ref.current?.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);

  return (
    <div ref={ref} className={`relative ${width}`}>
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        className="h-9 w-full border border-[#BAC5C5] bg-white px-3 text-left text-[10.5px] font-semibold text-[#0D1B1B] shadow-sm flex items-center justify-between"
      >
        <span className="min-w-0 truncate"><span className="text-[#637877]">{label}: </span>{value}</span>
        <ChevronDown className="h-3.5 w-3.5 text-[#637877]" />
      </button>
      {open && (
        <div className="absolute left-0 top-[38px] z-[90] max-h-[360px] w-full overflow-y-auto border border-[#ACB8B8] bg-white shadow-xl">
          {children({ close: () => setOpen(false) })}
        </div>
      )}
    </div>
  );
}

function MetricTile({ label, value, tone = "dark" }) {
  const tones = {
    dark: "border-[#1E2B2B] bg-[#004041] text-white",
    light: "border-[#CBD6D6] bg-white text-[#091B1B]",
    profit: "border-[#057B7B] bg-[#ECFDF5] text-[#064E3B]",
    cost: "border-[#B45309] bg-[#FFF7ED] text-[#7C2D12]",
  };
  return (
    <div className={`border px-3 py-2 ${tones[tone] || tones.light}`}>
      <div className="text-[9px] uppercase tracking-wide opacity-70">{label}</div>
      <div className="mt-1 text-[13px] font-black leading-none">{value}</div>
    </div>
  );
}

function HorizontalStackGraph({ row, maxTotal }) {
  const widthPercent = maxTotal > 0 ? Math.max(5, Math.min(100, (row.total / maxTotal) * 100)) : 0;
  return (
    <div className="border border-[#CBD6D6] bg-white">
      <div className="grid grid-cols-12 border-b border-[#E2E9E9]">
        <div className="col-span-12 lg:col-span-3 px-3 py-2">
          <div className="text-[11px] font-black text-[#091B1B]">{row.name}</div>
          <div className="text-[9px] text-[#637877]">{row.code || "NO CODE"} · {row.uom || "UOM"}</div>
        </div>
        <div className="col-span-12 lg:col-span-9 px-3 py-2">
          <div className="mb-1 flex items-center justify-between text-[9px] text-[#637877]">
            <span>Total consumed</span>
            <b className="text-[#091B1B]">{qty(row.total)} {row.uom}</b>
          </div>
          <div className="h-8 w-full border border-[#CBD6D6] bg-[#F8FAFC]">
            <div className="flex h-full" style={{ width: `${widthPercent}%` }}>
              {row.contributions.map((item, index) => {
                const share = row.total > 0 ? (item.qty / row.total) * 100 : 0;
                return (
                  <div
                    key={`${row.currency}-${row.id}-${item.menuId}-${index}`}
                    title={`${item.menuName}: ${qty(item.qty)} ${row.uom}`}
                    className="h-full border-r border-white/70"
                    style={{ width: `${share}%`, backgroundColor: PALETTE[index % PALETTE.length] }}
                  />
                );
              })}
            </div>
          </div>
          <div className="mt-2 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-1">
            {row.contributions.map((item, index) => (
              <div key={`${row.currency}-${row.id}-legend-${item.menuId}-${index}`} className="flex items-center gap-2 text-[9px] text-[#324444]">
                <span className="h-2.5 w-2.5 shrink-0 border border-[#CBD6D6]" style={{ backgroundColor: PALETTE[index % PALETTE.length] }} />
                <span className="truncate">{item.menuName}</span>
                <b className="ml-auto">{qty(item.qty)}</b>
              </div>
            ))}
          </div>
        </div>
      </div>
      <div className="grid grid-cols-6 bg-[#F8FAFC] text-[9px]">
        <div className="border-r border-[#E2E9E9] px-2 py-1"><span className="text-[#637877]">Opening</span><br /><b>{qty(row.recon?.opening_qty)}</b></div>
        <div className="border-r border-[#E2E9E9] px-2 py-1"><span className="text-[#637877]">Receipts</span><br /><b>{qty(row.recon?.receipts_qty)}</b></div>
        <div className="border-r border-[#E2E9E9] px-2 py-1"><span className="text-[#637877]">BOH Front</span><br /><b>{qty(row.recon?.boh_to_front_qty)}</b></div>
        <div className="border-r border-[#E2E9E9] px-2 py-1"><span className="text-[#637877]">Expected</span><br /><b>{qty(row.recon?.expected_closing_qty)}</b></div>
        <div className="border-r border-[#E2E9E9] px-2 py-1"><span className="text-[#637877]">Variance</span><br /><b>{qty(row.recon?.variance_qty)}</b></div>
        <div className="px-2 py-1"><span className="text-[#637877]">Value</span><br /><b>{money(row.recon?.variance_value, row.recon?.currency_code || row.currency)}</b></div>
      </div>
    </div>
  );
}

const MenuRecipeDailyStockReconciliationModal = ({ open, onClose, onNavigateReport }) => {
  const [scopeFilters, setScopeFilters] = useState({ date_from: "", date_to: "", branch_id: "", till_id: "", cashier_id: "", shift_session_id: "", currency_codes: [], business_mode: "fast_food_boh" });
  const [tradingDay, setTradingDay] = useState(null);
  const [closedReports, setClosedReports] = useState([]);
  const [selectedReportKey, setSelectedReportKey] = useState("current");
  const [report, setReport] = useState(null);
  const [dateSearch, setDateSearch] = useState("");
  const [menuSearch, setMenuSearch] = useState("");
  const [selectedMenuIds, setSelectedMenuIds] = useState([]);
  const [allSelected, setAllSelected] = useState(true);
  const [activeTab, setActiveTab] = useState("menu");
  const [selectedFlowCurrency, setSelectedFlowCurrency] = useState("ALL");
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [generated, setGenerated] = useState(false);
  const [outputChooserOpen, setOutputChooserOpen] = useState(false);
  const [appliedReportKey, setAppliedReportKey] = useState("");
  const [exportingFormat, setExportingFormat] = useState("");
  const lookups = useHospitalityReportLookups(open, scopeFilters);

  const lines = getLines(report);
  const menuLinesAll = useMemo(() => lines.filter(isMenuLine), [lines]);
  const recipeLines = useMemo(() => lines.filter(isRecipeLine), [lines]);
  const ingredientLines = useMemo(() => lines.filter(isIngredientRecon), [lines]);

  const reportMode = selectedReportKey === "current" ? "live" : "closed";
  const selectedReport = closedReports.find((item) => String(item.id) === String(selectedReportKey));
  const selectedTradingDate = reportMode === "live" ? tradingDay?.trading_date : selectedReport?.trading_date || report?.trading_date;

  useEffect(() => {
    if (!selectedTradingDate) return;
    setScopeFilters((current) => ({ ...current, date_from: selectedTradingDate, date_to: selectedTradingDate }));
  }, [selectedTradingDate]);

  const visibleMenuLines = useMemo(() => {
    if (allSelected) return menuLinesAll;
    const ids = new Set(selectedMenuIds.map(String));
    return menuLinesAll.filter((line) => ids.has(menuIdOf(line)));
  }, [allSelected, menuLinesAll, selectedMenuIds]);

  const selectedParams = () => ({
    trading_date: selectedTradingDate,
    menu_product_ids: allSelected ? undefined : selectedMenuIds,
  });

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4200);
  };

  const loadClosedReports = async () => {
    const res = await getSavedMenuRecipeDsrReports({ per_page: 200 });
    const rows = res?.data || res?.items || res?.reports || [];
    setClosedReports(Array.isArray(rows) ? rows : []);
  };

  const syncMenuSelection = (nextReport, preserveSelection = false) => {
    const ids = getLines(nextReport).filter(isMenuLine).map(menuIdOf).filter(Boolean);
    if (!preserveSelection || allSelected) {
      setSelectedMenuIds(ids);
      setAllSelected(true);
    }
  };

  const loadLive = async (preserveSelection = false) => {
    const day = await getCurrentTradingDay();
    setTradingDay(day);
    if (!day?.trading_date) {
      throw new Error(day?.sale_block_reason || "No authoritative Hospitality trading date is open for this branch.");
    }
    const live = await getLiveMenuRecipeDsr({
      trading_date: day?.trading_date,
      menu_product_ids: preserveSelection && !allSelected ? selectedMenuIds : undefined,
    });
    setReport(live);
    syncMenuSelection(live, preserveSelection);
  };

  const loadSaved = async (reportId, preserveSelection = false) => {
    const saved = await getSavedMenuRecipeDsrReport(reportId);
    setReport(saved);
    syncMenuSelection(saved, preserveSelection);
  };

  const loadSelected = async (
    key = selectedReportKey,
    preserveSelection = false,
    keepViewWhileLoading = false,
  ) => {
    try {
      setLoading(true);
      if (!keepViewWhileLoading) {
        setGenerated(false);
        setReport(null);
      }
      if (key === "current") await loadLive(preserveSelection);
      else await loadSaved(key, preserveSelection);
      setAppliedReportKey(String(key));
      setGenerated(true);
      return true;
    } catch (error) {
      setReport(null);
      setGenerated(false);
      setAppliedReportKey("");
      showNotice(error?.response?.data?.message || error?.message || "Failed to load DSR.");
      return false;
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    setSelectedReportKey("current");
    setActiveTab("menu");
    setReport(null);
    setGenerated(false);
    setAppliedReportKey("");
    setOutputChooserOpen(false);
    Promise.allSettled([
      loadClosedReports(),
      getCurrentTradingDay().then((day) => setTradingDay(day)),
    ]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const chooseReport = (key) => {
    setSelectedReportKey(String(key));
    setActiveTab("menu");
    setReport(null);
    setGenerated(false);
    setAppliedReportKey("");
    setSelectedMenuIds([]);
    setAllSelected(true);
  };

  const toggleMenu = (id) => {
    const cleanId = String(id);
    const next = selectedMenuIds.includes(cleanId)
      ? selectedMenuIds.filter((item) => item !== cleanId)
      : [...selectedMenuIds, cleanId];

    setSelectedMenuIds(next);
    setAllSelected(next.length === menuLinesAll.length);
  };

  const applySelection = async () => {
    if (!allSelected && selectedMenuIds.length === 0) return showNotice("Select at least one menu item.");
    if (reportMode === "live") await loadSelected(appliedReportKey || "current", true, true);
  };

  const downloadExcel = async () => {
    if (!allSelected && selectedMenuIds.length === 0) return showNotice("Select at least one menu item.");
    try {
      setExportingFormat("xlsx");
      const filename = `${reportMode}-dsr-${selectedTradingDate || "current"}.xlsx`;
      if (reportMode === "live") await downloadLiveMenuRecipeDsrExcel(selectedParams(), filename);
      else await downloadMenuRecipeDsrExcel(getReportId(report) || appliedReportKey || selectedReportKey, filename, selectedParams());
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Failed to download the Excel report.");
    } finally {
      setExportingFormat("");
    }
  };

  const downloadPdf = async () => {
    if (!allSelected && selectedMenuIds.length === 0) return showNotice("Select at least one menu item.");
    try {
      setExportingFormat("pdf");
      const filename = `${reportMode}-dsr-${selectedTradingDate || "current"}.pdf`;
      if (reportMode === "live") await downloadLiveMenuRecipeDsrPdf(selectedParams(), filename);
      else await downloadMenuRecipeDsrPdf(getReportId(report) || appliedReportKey || selectedReportKey, filename, selectedParams());
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || "Failed to download the PDF report.");
    } finally {
      setExportingFormat("");
    }
  };

  const openOutputChooser = () => setOutputChooserOpen(true);

  const viewSelectedReport = async () => {
    const viewed = await loadSelected(selectedReportKey, false);
    if (viewed) setOutputChooserOpen(false);
  };

  const downloadChosenFormat = async (format) => {
    setOutputChooserOpen(false);
    if (format === "pdf") await downloadPdf();
    else await downloadExcel();
  };

  const filteredReports = useMemo(() => {
    const search = dateSearch.trim().toLowerCase();
    if (!search) return closedReports;
    return closedReports.filter((row) => {
      const text = `${row.trading_date || ""} ${formatDate(row.trading_date)} ${row.status || ""}`.toLowerCase();
      return text.includes(search);
    });
  }, [closedReports, dateSearch]);

  const filteredMenuLines = useMemo(() => {
    const search = menuSearch.trim().toLowerCase();
    if (!search) return menuLinesAll;
    return menuLinesAll.filter((line) => `${line.menu_product_name || ""} ${line.menu_product_code || ""} ${currencyOf(line)}`.toLowerCase().includes(search));
  }, [menuLinesAll, menuSearch]);

  const ingredientById = useMemo(() => {
    const map = new Map();
    ingredientLines.forEach((line) => map.set(ingredientIdOf(line), line));
    return map;
  }, [ingredientLines]);

  const totalsByCurrency = useMemo(() => {
    const map = new Map();
    visibleMenuLines.forEach((menu) => {
      const cur = currencyOf(menu);
      const row = map.get(cur) || { currency: cur, qty: 0, revenue: 0, cost: 0, profit: 0 };
      row.qty += Number(menu.menu_quantity_sold || 0);
      row.revenue += Number(menu.menu_revenue || 0);
      row.cost += Number(menu.menu_recipe_cost || 0);
      row.profit += Number(menu.menu_gross_profit || 0);
      map.set(cur, row);
    });
    return [...map.values()].sort((a, b) => a.currency.localeCompare(b.currency));
  }, [visibleMenuLines]);

  const ingredientUsageVariance = useMemo(
    () => buildIngredientUsageVariance(ingredientLines),
    [ingredientLines]
  );

  const topUsageVariance = useMemo(
    () => [...ingredientUsageVariance]
      .sort((left, right) => Math.abs(right.variance) - Math.abs(left.variance))
      .slice(0, 4),
    [ingredientUsageVariance]
  );

  const sharedFlowByCurrency = useMemo(() => {
    const visibleMenusById = new Map(visibleMenuLines.map((m) => [menuIdOf(m), m]));
    const flow = new Map();

    recipeLines.forEach((line) => {
      const menu = visibleMenusById.get(menuIdOf(line));
      if (!menu) return;

      const currency = currencyOf(menu);
      const ingredientId = ingredientIdOf(line);
      const key = `${currency}::${ingredientId}`;
      const current = flow.get(key) || {
        key,
        currency,
        id: ingredientId,
        name: line.ingredient_name,
        code: line.ingredient_code,
        uom: line.uom_code,
        total: 0,
        recon: ingredientById.get(ingredientId),
        contributionMap: new Map(),
      };

      const qtyConsumed = Number(line.ingredient_consumed_qty || 0);
      const menuKey = `${menuIdOf(menu)}::${currency}`;
      const contribution = current.contributionMap.get(menuKey) || {
        menuId: menuIdOf(menu),
        menuName: menu.menu_product_name,
        qty: 0,
      };

      contribution.qty += qtyConsumed;
      current.total += qtyConsumed;
      current.contributionMap.set(menuKey, contribution);
      flow.set(key, current);
    });

    return [...flow.values()]
      .map((row) => ({
        ...row,
        contributions: [...row.contributionMap.values()].sort((a, b) => b.qty - a.qty),
      }))
      .sort((a, b) => a.currency.localeCompare(b.currency) || String(a.name || "").localeCompare(String(b.name || "")));
  }, [ingredientById, recipeLines, visibleMenuLines]);

  const graphCurrencies = useMemo(() => {
    return ["ALL", ...new Set(sharedFlowByCurrency.map((row) => row.currency))];
  }, [sharedFlowByCurrency]);

  useEffect(() => {
    if (!graphCurrencies.includes(selectedFlowCurrency)) setSelectedFlowCurrency("ALL");
  }, [graphCurrencies, selectedFlowCurrency]);

  const visibleSharedFlow = useMemo(() => {
    return selectedFlowCurrency === "ALL"
      ? sharedFlowByCurrency
      : sharedFlowByCurrency.filter((row) => row.currency === selectedFlowCurrency);
  }, [selectedFlowCurrency, sharedFlowByCurrency]);

  const maxSharedTotal = useMemo(() => Math.max(0, ...visibleSharedFlow.map((row) => row.total)), [visibleSharedFlow]);

  if (!open) return null;

  return (
    <>
    <FullScreenModal open={open} onClose={onClose} title="Daily Stock Reconciliation" subtitle="Menu recipe stock flow">
      <div className="relative h-full min-h-0 overflow-hidden bg-[#EFF3F3] text-[#091B1B] text-[10.5px]">
        {notice && <div className="absolute left-1/2 top-2 z-50 -translate-x-1/2 border border-[#091B1B] bg-white px-4 py-2 text-[10.5px] font-semibold text-[#091B1B] shadow-xl">{notice}</div>}

        <div className="flex h-full min-h-0">
          <HospitalityReportNavigator
            section="inventory"
            activeKey={HOSPITALITY_REPORT_KEYS.MENU_RECIPE_DSR}
            onNavigate={onNavigateReport}
            groups={[
              {
                title: "Reconciliation views",
                items: REPORT_VIEWS,
                activeKey: activeTab,
                onChange: setActiveTab,
              },
            ]}
          />
          <div className="flex min-h-0 min-w-0 flex-1 flex-col">
          {!generated && <>
          <HospitalityReportScopePanel filters={scopeFilters} setFilters={(next) => { setGenerated(false); setReport(null); setAppliedReportKey(""); setScopeFilters(next); }} lookups={lookups} loading={loading} onApply={openOutputChooser} supported={{ branch_id: false, date_from: false, date_to: false, till_id: false, cashier_id: false, shift_session_id: false, currency_codes: false }} />
          <div className="shrink-0 border-b border-[#C8D3D3] bg-white px-5 py-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex flex-wrap items-center gap-2">
                <CompactDropdown label="Trading date" value={`${reportMode === "live" ? "Current" : "Closed"} · ${formatDate(selectedTradingDate)}`} width="w-[300px]">
                  {({ close }) => (
                    <div>
                      <div className="p-2 border-b border-[#E4E8E8]">
                        <div className="flex h-8 items-center gap-2 border border-[#CBD6D6] bg-[#F8FAFC] px-2">
                          <Search className="h-3.5 w-3.5 text-[#637877]" />
                          <input value={dateSearch} onChange={(e) => setDateSearch(e.target.value)} placeholder="Search date" className="h-full min-w-0 flex-1 bg-transparent text-[10.5px] outline-none" />
                        </div>
                      </div>
                      <button type="button" onClick={() => { chooseReport("current"); close(); }} className="flex w-full items-center justify-between border-b border-[#EFF2F2] px-3 py-2 text-left hover:bg-[#F8FAFC]">
                        <span><b>Current trading date</b><br /><small>{formatDate(tradingDay?.trading_date)} · {tradingDay?.status || "open"}</small></span>
                        {selectedReportKey === "current" && <Check className="h-4 w-4" />}
                      </button>
                      {filteredReports.map((row) => (
                        <button key={row.id} type="button" onClick={() => { chooseReport(row.id); close(); }} className="flex w-full items-center justify-between border-b border-[#EFF2F2] px-3 py-2 text-left hover:bg-[#F8FAFC]">
                          <span><b>{formatDate(row.trading_date)}</b><br /><small>{String(row.status || "closed").toUpperCase()} · {row.currency_code || "MULTI"}</small></span>
                          {String(selectedReportKey) === String(row.id) && <Check className="h-4 w-4" />}
                        </button>
                      ))}
                    </div>
                  )}
                </CompactDropdown>
              </div>
            </div>
          </div>
          </>}

          {!generated ? (
            <ReportGenerationPrompt
              title="Configure Daily Stock Reconciliation"
              message="Choose the current or a closed trading date, then select Generate."
            />
          ) : (
          <ReportViewShell
            title="Daily Stock Reconciliation"
            subtitle={`${reportMode === "live" ? "Current" : "Closed"} trading date · ${formatDate(selectedTradingDate)}`}
            onBackToFilters={() => {
              setGenerated(false);
              setAllSelected(true);
            }}
            busy={loading || !!exportingFormat}
            actions={(
              <>
                <button type="button" title="Refresh" onClick={() => loadSelected(appliedReportKey || selectedReportKey, true, true)} disabled={loading || !!exportingFormat} className="inline-flex h-8 items-center gap-1 border border-[#BAC5C5] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
                <button type="button" onClick={openOutputChooser} disabled={loading || !!exportingFormat} className="inline-flex h-8 items-center gap-1 border border-[#057B7B] bg-[#057B7B] px-3 text-[11px] font-semibold text-white disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
              </>
            )}
          >
          <div className="shrink-0 border-b border-[#C8D3D3] bg-white px-5 py-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <CompactDropdown label="Menu items" value={allSelected ? `All sold (${menuLinesAll.length})` : `${selectedMenuIds.length} selected`} width="w-[340px]">
                  {() => (
                    <div>
                      <div className="border-b border-[#E4E8E8] p-2">
                        <div className="flex h-8 items-center gap-2 border border-[#CBD6D6] bg-[#F8FAFC] px-2">
                          <Search className="h-3.5 w-3.5 text-[#637877]" />
                          <input value={menuSearch} onChange={(e) => setMenuSearch(e.target.value)} placeholder="Search menu item" className="h-full min-w-0 flex-1 bg-transparent text-[10.5px] outline-none" />
                        </div>
                      </div>
                      <label className="flex cursor-pointer items-center gap-2 border-b border-[#EFF2F2] px-3 py-2 font-semibold hover:bg-[#F8FAFC]">
                        <input type="checkbox" checked={allSelected} onChange={(e) => { const checked = e.target.checked; setAllSelected(checked); setSelectedMenuIds(checked ? menuLinesAll.map(menuIdOf) : []); }} />
                        All sold menu items
                      </label>
                      <div className="max-h-[260px] overflow-y-auto">
                        {filteredMenuLines.map((line) => {
                          const id = menuIdOf(line);
                          return (
                            <label key={`${id}-${currencyOf(line)}`} className="flex cursor-pointer items-start gap-2 border-b border-[#F1F5F5] px-3 py-2 hover:bg-[#F8FAFC]">
                              <input type="checkbox" checked={selectedMenuIds.includes(id)} onChange={() => toggleMenu(id)} />
                              <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold">{line.menu_product_name}</span>
                                <span className="block text-[9.5px] text-[#637877]">{currencyOf(line)} · Qty {qty(line.menu_quantity_sold)} · {money(line.menu_revenue, currencyOf(line))}</span>
                              </span>
                            </label>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </CompactDropdown>
                <button type="button" onClick={applySelection} className="h-9 border border-[#0D1B1B] bg-[#003434] px-4 text-[10px] font-bold uppercase text-white disabled:opacity-50" disabled={loading}>Apply Menu Selection</button>
              </div>
              <div className="h-9 border border-[#CBD6D6] bg-[#F8FAFC] px-3 text-[10.5px] font-semibold leading-9">
                {visibleMenuLines.length} menu · {reportMode.toUpperCase()}
              </div>
            </div>
          </div>
          <div className="shrink-0 border-b border-[#C8D3D3] bg-[#F8FAFC] px-5 py-2">
            <div className="flex flex-wrap gap-2">
                {totalsByCurrency.map((row) => (
                  <MetricTile key={row.currency} label={`${row.currency} profit`} value={money(row.profit, row.currency)} tone="profit" />
                ))}
            </div>
            <IngredientUsageVarianceVisual rows={topUsageVariance} allRows={ingredientUsageVariance} />
          </div>

          <div className="flex min-h-0 flex-1">
            <div className="min-h-0 min-w-0 flex-1 overflow-y-auto p-4">
            {activeTab === "menu" && (
              <>
                {visibleMenuLines.map((menu) => {
                  const menuId = menuIdOf(menu);
                  const childrenExact = recipeLines.filter((line) => menuIdOf(line) === menuId && currencyOf(line) === currencyOf(menu));
                  const children = childrenExact.length ? childrenExact : recipeLines.filter((line) => menuIdOf(line) === menuId);

                  return (
                    <section key={`${menuId}-${currencyOf(menu)}`} className="mb-3 border border-[#C4CFCF] bg-white shadow-sm">
                      <div className="grid grid-cols-12 border-b border-[#C4CFCF] bg-[#004041] text-white">
                        <div className="col-span-12 md:col-span-4 p-3">
                          <div className="text-[14px] font-bold leading-tight">{menu.menu_product_name}</div>
                          <div className="mt-1 text-[9px] text-white/70">{menu.menu_product_code || "NO CODE"} · {currencyOf(menu)}</div>
                        </div>
                        <div className="col-span-12 md:col-span-8 grid grid-cols-5 gap-1 p-3 text-right text-[9.5px]">
                          <div><span className="block text-white/60">Qty</span><b>{qty(menu.menu_quantity_sold)}</b></div>
                          <div><span className="block text-white/60">Sales</span><b>{money(menu.menu_revenue, currencyOf(menu))}</b></div>
                          <div><span className="block text-white/60">Cost</span><b>{money(menu.menu_recipe_cost, currencyOf(menu))}</b></div>
                          <div><span className="block text-white/60">Profit</span><b>{money(menu.menu_gross_profit, currencyOf(menu))}</b></div>
                          <div><span className="block text-white/60">Cost %</span><b>{pct(menu.menu_cost_percent)}</b></div>
                        </div>
                      </div>

                      <div className="overflow-x-auto">
                        <table className="sx-enterprise-table min-w-[1460px] w-full text-left text-[10px]">
                          <thead className="bg-[#F8FAFC] text-[8.5px] uppercase text-[#465858]">
                            <tr>
                              <th className="px-3 py-2">Ingredient</th><th className="px-3 py-2">Code</th><th className="px-3 py-2">UOM</th><th className="px-3 py-2 text-right">Recipe</th><th className="px-3 py-2 text-right">Sold</th><th className="px-3 py-2 text-right">Consumed</th><th className="px-3 py-2 text-right">Unit Cost</th><th className="px-3 py-2 text-right">Total Cost</th><th className="px-3 py-2 text-right">Opening</th><th className="px-3 py-2 text-right">Receipts</th><th className="px-3 py-2 text-right">BOH Front</th><th className="px-3 py-2 text-right">Issues</th><th className="px-3 py-2 text-right">Expected</th><th className="px-3 py-2 text-right">Counted</th><th className="px-3 py-2 text-right">Variance</th><th className="px-3 py-2 text-right">Value</th>
                            </tr>
                          </thead>
                          <tbody>
                            {children.map((line) => {
                              const recon = ingredientById.get(ingredientIdOf(line));
                              return (
                                <tr key={`${menuId}-${ingredientIdOf(line)}-${currencyOf(line)}`} className="border-t border-[#EFF2F2] odd:bg-white even:bg-[#FBFCFE]">
                                  <td className="px-3 py-2 font-semibold">{line.ingredient_name}</td>
                                  <td className="px-3 py-2 text-[#637877]">{line.ingredient_code || "-"}</td>
                                  <td className="px-3 py-2">{line.uom_code}</td>
                                  <td className="px-3 py-2 text-right">{qty(line.recipe_qty_per_menu)}</td>
                                  <td className="px-3 py-2 text-right">{qty(line.menu_quantity_sold || menu.menu_quantity_sold)}</td>
                                  <td className="px-3 py-2 text-right font-bold">{qty(line.ingredient_consumed_qty)}</td>
                                  <td className="px-3 py-2 text-right">{money(line.unit_cost, currencyOf(line))}</td>
                                  <td className="px-3 py-2 text-right font-bold">{money(line.ingredient_total_cost, currencyOf(line))}</td>
                                  <td className="px-3 py-2 text-right">{qty(recon?.opening_qty)}</td>
                                  <td className="px-3 py-2 text-right">{qty(recon?.receipts_qty)}</td>
                                  <td className="px-3 py-2 text-right">{qty(recon?.boh_to_front_qty)}</td>
                                  <td className="px-3 py-2 text-right">{qty(recon?.other_issues_qty)}</td>
                                  <td className="px-3 py-2 text-right">{qty(recon?.expected_closing_qty)}</td>
                                  <td className="px-3 py-2 text-right">{qty(recon?.counted_closing_qty)}</td>
                                  <td className="px-3 py-2 text-right font-bold">{qty(recon?.variance_qty)}</td>
                                  <td className="px-3 py-2 text-right font-bold">{money(recon?.variance_value, recon?.currency_code || currencyOf(line))}</td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    </section>
                  );
                })}
              </>
            )}

            {activeTab === "shared" && (
              <section className="border border-[#C4CFCF] bg-white">
                <div className="grid grid-cols-12 border-b border-[#C4CFCF] bg-[#004041] text-white">
                  <div className="col-span-12 lg:col-span-5 p-3">
                    <div className="text-[14px] font-black">Shared Ingredient Flow</div>
                    <div className="mt-1 text-[9px] text-white/65">Separated by sales currency. Each bar shows how different menu items consumed the same ingredient.</div>
                  </div>
                  <div className="col-span-12 lg:col-span-7 p-3 flex flex-wrap items-center justify-end gap-2">
                    {graphCurrencies.map((cur) => (
                      <button
                        key={cur}
                        type="button"
                        onClick={() => setSelectedFlowCurrency(cur)}
                        className={`h-8 border px-3 text-[10px] font-black ${selectedFlowCurrency === cur ? "border-white bg-white text-[#0D1B1B]" : "border-white/30 bg-transparent text-white"}`}
                      >
                        {cur === "ALL" ? "ALL CURRENCIES" : cur}
                      </button>
                    ))}
                  </div>
                </div>

                <div className="grid grid-cols-1 xl:grid-cols-4 gap-3 p-3 border-b border-[#E2E9E9] bg-[#F8FAFC]">
                  {totalsByCurrency.map((row) => (
                    <MetricTile key={`shared-${row.currency}`} label={`${row.currency} revenue / profit`} value={`${money(row.revenue, row.currency)} / ${money(row.profit, row.currency)}`} tone="light" />
                  ))}
                </div>

                <div className="p-3 space-y-3">
                  {visibleSharedFlow.map((row) => <HorizontalStackGraph key={row.key} row={row} maxTotal={maxSharedTotal} />)}
                  {visibleSharedFlow.length === 0 && <div className="border border-[#CBD6D6] bg-white p-8 text-center text-[11px] text-[#637877]">No shared ingredient consumption for this selection.</div>}
                </div>
              </section>
            )}

            {activeTab === "profit" && (
              <section className="border border-[#C4CFCF] bg-white">
                <div className="border-b border-[#C4CFCF] bg-[#004041] px-3 py-3 text-[14px] font-black text-white">Profit Summary</div>
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 p-3 bg-[#F8FAFC] border-b border-[#E2E9E9]">
                  {totalsByCurrency.map((row) => (
                    <div key={`profit-${row.currency}`} className="border border-[#CBD6D6] bg-white p-3">
                      <div className="text-[11px] font-black">{row.currency}</div>
                      <div className="mt-2 grid grid-cols-2 gap-2 text-[10px]">
                        <div><span className="text-[#637877]">Revenue</span><br /><b>{money(row.revenue, row.currency)}</b></div>
                        <div><span className="text-[#637877]">Recipe cost</span><br /><b>{money(row.cost, row.currency)}</b></div>
                        <div><span className="text-[#637877]">Profit</span><br /><b>{money(row.profit, row.currency)}</b></div>
                        <div><span className="text-[#637877]">Food cost</span><br /><b>{row.revenue > 0 ? pct((row.cost / row.revenue) * 100) : "0.00%"}</b></div>
                      </div>
                    </div>
                  ))}
                </div>
                <div className="overflow-x-auto p-3">
                  <table className="sx-enterprise-table min-w-[900px] w-full text-left text-[10px] border border-[#E2E9E9]">
                    <thead className="bg-[#F8FAFC] text-[9px] uppercase text-[#465858]"><tr><th className="px-3 py-2">Menu item</th><th className="px-3 py-2">Currency</th><th className="px-3 py-2 text-right">Qty</th><th className="px-3 py-2 text-right">Revenue</th><th className="px-3 py-2 text-right">Recipe cost</th><th className="px-3 py-2 text-right">Profit</th><th className="px-3 py-2 text-right">Food cost %</th></tr></thead>
                    <tbody>
                      {visibleMenuLines.map((row) => (
                        <tr key={`profit-row-${menuIdOf(row)}-${currencyOf(row)}`} className="border-t border-[#E2E9E9]"><td className="px-3 py-2 font-semibold">{row.menu_product_name}</td><td className="px-3 py-2">{currencyOf(row)}</td><td className="px-3 py-2 text-right">{qty(row.menu_quantity_sold)}</td><td className="px-3 py-2 text-right">{money(row.menu_revenue, currencyOf(row))}</td><td className="px-3 py-2 text-right">{money(row.menu_recipe_cost, currencyOf(row))}</td><td className="px-3 py-2 text-right font-black">{money(row.menu_gross_profit, currencyOf(row))}</td><td className="px-3 py-2 text-right">{pct(row.menu_cost_percent)}</td></tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            )}

            {!loading && visibleMenuLines.length === 0 && (
              <div className="border border-[#CBD6D6] bg-white p-8 text-center text-[11px] text-[#637877]">No sold menu items for this trading date.</div>
            )}
            </div>
          </div>
          </ReportViewShell>
          )}
          </div>
        </div>
      </div>
    </FullScreenModal>

    <ReportOutputChooser
      open={outputChooserOpen}
      reportTitle="Daily Stock Reconciliation"
      busy={loading || !!exportingFormat}
      busyLabel={loading ? "Loading daily stock reconciliation from the backend…" : "Generating backend download…"}
      onClose={() => setOutputChooserOpen(false)}
      onView={generated ? undefined : viewSelectedReport}
      onDownloadPdf={() => downloadChosenFormat("pdf")}
      onDownloadExcel={() => downloadChosenFormat("xlsx")}
      outputs={{
        view: generated ? { available: false, reason: "The report is already open." } : true,
        pdf: true,
        excel: true,
      }}
    />
    </>
  );
};

const IngredientUsageVarianceVisual = ({ rows = [], allRows = [] }) => {
  if (!rows.length) return null;
  const maximum = Math.max(1, ...rows.flatMap((row) => [Math.abs(row.expectedUsage), Math.abs(row.actualUsage)]));
  const favorable = allRows.filter((row) => row.variance > 0).length;
  const adverse = allRows.filter((row) => row.variance < 0).length;
  const matched = allRows.length - favorable - adverse;

  return (
    <section className="mt-2 border border-[#CBD6D6] bg-white p-2">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div>
          <div className="text-[11px] font-bold text-[#003636]">Expected vs Actual Ingredient Usage</div>
          <div className="text-[9px] text-[#637877]">Largest backend stock variances; positive is favorable, negative is adverse.</div>
        </div>
        <div className="flex flex-wrap gap-1 text-[9px] font-semibold uppercase">
          <span className="border border-[#ABEFC6] bg-[#ECFDF3] px-2 py-1 text-[#027A48]">Favorable {favorable}</span>
          <span className="border border-[#FECDCA] bg-[#FEF3F2] px-2 py-1 text-[#B42318]">Adverse {adverse}</span>
          <span className="border border-[#CFD6D6] bg-[#F9FAFB] px-2 py-1 text-[#465757]">Matched {matched}</span>
        </div>
      </div>
      <div className="grid gap-2 lg:grid-cols-2 2xl:grid-cols-4">
        {rows.map((row) => (
          <div key={`${row.id}-${row.uom}`} className="border border-[#D7DFDF] bg-[#F8FAFC] p-2">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0"><div className="truncate text-[10px] font-semibold">{row.name}</div><div className="text-[8.5px] text-[#627474]">{row.code} · {row.uom}</div></div>
              <span className={`shrink-0 text-[9px] font-bold ${row.variance < 0 ? "text-[#B42318]" : row.variance > 0 ? "text-[#027A48]" : "text-[#627474]"}`}>{row.variance > 0 ? "+" : ""}{qty(row.variance)}</span>
            </div>
            <UsageBar label="Expected" value={row.expectedUsage} maximum={maximum} color="bg-[#069494]" />
            <UsageBar label="Actual" value={row.actualUsage} maximum={maximum} color={row.variance < 0 ? "bg-[#F04438]" : "bg-[#12B76A]"} />
          </div>
        ))}
      </div>
    </section>
  );
};

const UsageBar = ({ label, value, maximum, color }) => (
  <div className="mt-1.5">
    <div className="mb-0.5 flex justify-between text-[8.5px] text-[#465757]"><span>{label}</span><strong>{qty(value)}</strong></div>
    <div className="h-1.5 bg-[#E4E8E8]"><div className={`h-full ${color}`} style={{ width: `${Math.max(value !== 0 ? 2 : 0, (Math.abs(value) / maximum) * 100)}%` }} /></div>
  </div>
);

export default MenuRecipeDailyStockReconciliationModal;

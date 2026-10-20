import React, { useEffect, useState } from "react";
import { Download, RefreshCw } from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import ReportViewNavigator from "../../../components/ReportViewNavigator";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import {
  getFoodRecipes,
  getFoodManufacturingProductionReport,
  getFoodManufacturingProductionPlan,
  getFoodManufacturingProductionPlans,
  downloadFoodManufacturingProductionExcel,
  downloadFoodManufacturingProductionPdf,
  downloadFoodManufacturingPlanningExcel,
  downloadFoodManufacturingPlanningPdf,
  downloadFoodManufacturingSavedPlansExcel,
  downloadFoodManufacturingSavedPlansPdf,
  buildFoodManufacturingReportParams,
} from "../../../services/FoodManufacturingService";

const rows = (response) => {
  const payload = response?.data ?? response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};
const data = (response) => response?.data?.data || response?.data || response || {};
const qty = (value) => Number(value || 0).toFixed(4);
const money = (currency, value) => `${currency || "USD"} ${Number(value || 0).toFixed(4)}`;
const today = () => new Date().toISOString().slice(0, 10);
const monthStart = () => `${new Date().toISOString().slice(0, 8)}01`;
const getMessage = (error) => error?.response?.data?.message || error?.response?.data?.error || error?.message || "Request failed.";
const REPORT_VIEWS = [
  { key: "summary", label: "Production Register", description: "Completed production batches and output cost." },
  { key: "plans", label: "Saved Plans", description: "Saved manufacturing plans and shortages." },
  { key: "materials", label: "Material Consumption", description: "Recipe component usage and cost." },
  { key: "planning", label: "Planning Calculator", description: "Requirements for the selected recipe." },
  { key: "shortages", label: "Shortages", description: "Materials requiring receive or transfer." },
];
const REPORT_ARTIFACTS = {
  production: { title: "Production Register", description: "Completed production batches and material consumption." },
  saved_plans: { title: "Saved Production Plans", description: "Saved manufacturing plans and shortage status." },
  planning: { title: "Material Planning", description: "Requirements and shortages for the selected recipe." },
};
const isPlanningView = (tab) => ["planning", "shortages"].includes(tab);
const artifactForTab = (tab) => tab === "plans" ? "saved_plans" : ["planning", "shortages"].includes(tab) ? "planning" : "production";
const snapshotFilters = (value = {}) => ({ ...value, currency_codes: [...(value.currency_codes || [])] });
const filtersForTab = (value, tab) => snapshotFilters({
  ...value,
  planned_quantity: isPlanningView(tab) ? value?.planned_quantity || "" : "",
});

export default function FoodManufacturingReportsModal({ open, onClose }) {
  const [activeTab, setActiveTab] = useState("summary");
  const [recipes, setRecipes] = useState([]);
  const [report, setReport] = useState(null);
  const [plan, setPlan] = useState(null);
  const [savedPlans, setSavedPlans] = useState([]);
  const [filters, setFilters] = useState({ date_from: monthStart(), date_to: today(), branch_id: "", till_id: "", cashier_id: "", shift_session_id: "", currency_codes: [], business_mode: "fast_food_boh", recipe_id: "", produced_product_id: "", source_stock_area: "", destination_stock_area: "", planned_quantity: "" });
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [viewing, setViewing] = useState(false);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserSource, setChooserSource] = useState("configuration");
  const [outputFilters, setOutputFilters] = useState(null);
  const [outputArtifact, setOutputArtifact] = useState("production");
  const [outputTab, setOutputTab] = useState("summary");
  const [appliedFilters, setAppliedFilters] = useState(null);
  const [messageModal, setMessageModal] = useState({ open: false, type: "success", title: "", message: "" });
  const lookups = useHospitalityReportLookups(open, filters);

  const summary = report?.summary || {};
  const batches = report?.batches || [];
  const lines = report?.lines || [];
  const planLines = plan?.lines || [];
  const plans = savedPlans.length ? savedPlans : report?.plans || [];
  const currency = summary.currency_code === "MULTI" ? "" : summary.currency_code || "USD";

  useEffect(() => {
    if (!open) {
      setChooserOpen(false);
      return;
    }
    setViewing(false);
    setChooserOpen(false);
    setOutputFilters(null);
    setAppliedFilters(null);
    setReport(null);
    setPlan(null);
    setSavedPlans([]);
    (async () => {
      try {
        const [manufacturingRes, prepRes] = await Promise.all([
          getFoodRecipes({ per_page: 500, recipe_type: "manufacturing", branch_id: filters.branch_id || undefined }),
          getFoodRecipes({ per_page: 500, recipe_type: "prep", branch_id: filters.branch_id || undefined }),
        ]);
        setRecipes([...rows(manufacturingRes), ...rows(prepRes)].filter((r, i, arr) => arr.findIndex((x) => String(x.id) === String(r.id)) === i));
      } catch (error) {
        showError("Recipe Options Failed", error);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const showError = (title, error) => setMessageModal({ open: true, type: "error", title, message: getMessage(error) });

  const loadAll = async (nextFilters = filters) => {
    try {
      setLoading(true);
      setReport(null);
      setPlan(null);
      setSavedPlans([]);
      const nextParams = buildFoodManufacturingReportParams(nextFilters);
      const [manufacturingRes, prepRes, reportRes, plansRes] = await Promise.all([
        getFoodRecipes({ per_page: 500, recipe_type: "manufacturing", branch_id: nextFilters.branch_id || undefined }),
        getFoodRecipes({ per_page: 500, recipe_type: "prep", branch_id: nextFilters.branch_id || undefined }),
        getFoodManufacturingProductionReport(nextParams),
        getFoodManufacturingProductionPlans(nextParams),
      ]);
      setRecipes([...rows(manufacturingRes), ...rows(prepRes)].filter((r, i, arr) => arr.findIndex((x) => String(x.id) === String(r.id)) === i));
      setReport(data(reportRes));
      setSavedPlans(rows(plansRes));
      if (nextFilters.recipe_id && nextFilters.planned_quantity) {
        const planRes = await getFoodManufacturingProductionPlan(nextParams);
        setPlan(data(planRes));
      } else {
        setPlan(null);
      }
      return true;
    } catch (error) {
      setReport(null);
      setPlan(null);
      setSavedPlans([]);
      showError("Load Failed", error);
      return false;
    } finally {
      setLoading(false);
    }
  };

  const openChooser = (source = "configuration") => {
    const nextFilters = filtersForTab(source === "result" && appliedFilters ? appliedFilters : filters, activeTab);
    if (isPlanningView(activeTab) && !nextFilters.recipe_id) {
      setMessageModal({ open: true, type: "error", title: "Recipe Required", message: "Select the recipe to calculate its material requirements." });
      return;
    }
    if (isPlanningView(activeTab) && !(Number(nextFilters.planned_quantity) > 0)) {
      setMessageModal({ open: true, type: "error", title: "Planned Quantity Required", message: "Enter a planned quantity greater than zero." });
      return;
    }
    setChooserSource(source);
    setOutputFilters(nextFilters);
    setOutputArtifact(artifactForTab(activeTab));
    setOutputTab(activeTab);
    setChooserOpen(true);
  };

  const viewReport = async () => {
    const nextFilters = snapshotFilters(outputFilters || filters);
    const nextTab = outputTab;
    setChooserOpen(false);
    setViewing(false);
    setAppliedFilters(null);
    const loaded = await loadAll(nextFilters);
    if (loaded) {
      setAppliedFilters(nextFilters);
      setActiveTab(nextTab);
      setViewing(true);
    }
  };

  const refreshReport = async () => {
    const nextFilters = snapshotFilters(appliedFilters || filters);
    const loaded = await loadAll(nextFilters);
    if (!loaded) {
      setAppliedFilters(null);
      setViewing(false);
    }
  };

  const downloadReport = async (format) => {
    try {
      setExporting(true);
      const nextFilters = snapshotFilters(outputFilters || appliedFilters || filters);
      const nextParams = buildFoodManufacturingReportParams(nextFilters);
      const artifact = outputArtifact || artifactForTab(activeTab);
      if (artifact === "planning") {
        if (format === "excel") await downloadFoodManufacturingPlanningExcel(nextParams);
        else await downloadFoodManufacturingPlanningPdf(nextParams);
      } else if (artifact === "saved_plans") {
        if (format === "excel") await downloadFoodManufacturingSavedPlansExcel(nextParams);
        else await downloadFoodManufacturingSavedPlansPdf(nextParams);
      } else if (format === "excel") await downloadFoodManufacturingProductionExcel(nextParams);
      else await downloadFoodManufacturingProductionPdf(nextParams);
      setChooserOpen(false);
    } catch (error) {
      showError("Report Download Failed", error);
      setChooserOpen(false);
    } finally {
      setExporting(false);
    }
  };

  const handleReportViewChange = (nextTab) => {
    setActiveTab(nextTab);
    if (
      viewing
      && isPlanningView(nextTab)
      && (!appliedFilters?.recipe_id || !(Number(appliedFilters?.planned_quantity) > 0))
    ) {
      setPlan(null);
      setViewing(false);
    }
  };

  const productionFilterCells = [
    {
      key: "recipe",
      label: isPlanningView(activeTab) ? "Recipe *" : "Recipe",
      className: isPlanningView(activeTab) ? "" : "md:col-span-2",
      content: (
        <select
          aria-label="Recipe"
          value={filters.recipe_id}
          onChange={(event) => setFilters((previous) => ({ ...previous, recipe_id: event.target.value }))}
          className="hospitality-report-input"
        >
          <option value="">{isPlanningView(activeTab) ? "Select recipe" : "All recipes"}</option>
          {recipes.map((recipe) => <option key={recipe.id} value={recipe.id}>{recipe.name} - {recipe.produced_product?.name || recipe.menu_product?.name || "Output"}</option>)}
        </select>
      ),
    },
    ...(isPlanningView(activeTab) ? [{
      key: "planned-quantity",
      label: "Planned Quantity *",
      content: (
        <input
          aria-label="Planned quantity"
          type="number"
          min="0.0001"
          step="any"
          value={filters.planned_quantity || ""}
          onChange={(event) => setFilters((previous) => ({ ...previous, planned_quantity: event.target.value }))}
          className="hospitality-report-input"
          placeholder="Required"
        />
      ),
    }] : []),
    {
      key: "source-stock-area",
      label: "Consume From",
      content: (
        <select aria-label="Consume from" value={filters.source_stock_area} onChange={(event) => setFilters((previous) => ({ ...previous, source_stock_area: event.target.value }))} className="hospitality-report-input">
          <option value="">All stock areas</option><option value="back">Back Stock</option><option value="front">Front Stock</option>
        </select>
      ),
    },
    {
      key: "destination-stock-area",
      label: "Output To",
      content: (
        <select aria-label="Output to" value={filters.destination_stock_area} onChange={(event) => setFilters((previous) => ({ ...previous, destination_stock_area: event.target.value }))} className="hospitality-report-input">
          <option value="">All stock areas</option><option value="back">Back Stock</option><option value="front">Front Stock</option>
        </select>
      ),
    },
  ];

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title="FOOD PRODUCTION REPORTS" subtitle="Production output, saved plans, material consumption and shortages">
        <div className="food-report-page h-full min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
          <div className="flex h-full min-h-0 overflow-hidden border border-[#AAB5B5] bg-white">
            <ReportViewNavigator items={REPORT_VIEWS} activeKey={activeTab} onChange={handleReportViewChange} title="Production reports" />
            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {!viewing ? <div className="flex h-full min-h-0 flex-col overflow-y-auto">
              <HospitalityReportScopePanel filters={filters} setFilters={setFilters} lookups={lookups} loading={loading} onApply={() => openChooser("configuration")} supported={{ till_id: false, cashier_id: false, shift_session_id: false, currency_codes: false }} extraCells={productionFilterCells} />
              <ReportGenerationPrompt title="Configure Food Production Reports" message="Choose the report view, branch, period and optional recipe planning filters, then select Generate." />
            </div> : <ReportViewShell
              title={REPORT_ARTIFACTS[artifactForTab(activeTab)].title}
              subtitle={REPORT_ARTIFACTS[artifactForTab(activeTab)].description}
              backLabel="Configuration / Back"
              onBackToFilters={() => setViewing(false)}
              busy={loading}
              actions={<>
                <button type="button" onClick={refreshReport} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AAB5B5] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />Refresh</button>
                <button type="button" onClick={() => openChooser("result")} disabled={loading || exporting} className="inline-flex h-8 items-center gap-1 border border-[#069494] bg-white px-3 text-[11px] font-semibold text-[#008585] disabled:opacity-50"><Download className="h-3.5 w-3.5" />Download</button>
              </>}
            >
              <div className="flex h-full min-h-0 min-w-0 flex-col">
                <div className="grid shrink-0 grid-cols-2 gap-2 border-b border-[#CFD9D8] bg-[#F5F7FA] p-2 md:grid-cols-3 xl:grid-cols-6">
                  <Metric label="Batches" value={summary.batch_count || 0} />
                  <Metric label="Saved Plans" value={summary.plan_count || plans.length || 0} />
                  <Metric label="Planned Qty" value={qty(summary.planned_yield)} />
                  <Metric label="Actual Qty" value={qty(summary.actual_yield)} />
                  <Metric label="Total Cost" value={money(currency, summary.total_cost)} />
                  <Metric label="Avg Unit Cost" value={money(currency, summary.unit_cost_average)} />
                </div>
                <div className="min-h-0 min-w-0 flex-1 overflow-auto">
                  {activeTab === "summary" && <BatchTable batches={batches} currency={currency} />}
                  {activeTab === "plans" && <PlansTable plans={plans} currency={currency} />}
                  {activeTab === "materials" && <LineTable lines={lines} currency={currency} />}
                  {activeTab === "planning" && <PlanTable plan={plan} lines={planLines} currency={currency} />}
                  {activeTab === "shortages" && <ShortageTable lines={planLines.filter((r) => Number(r.shortage_quantity || 0) > 0)} currency={currency} />}
                </div>
              </div>
            </ReportViewShell>}
            </div>
          </div>
        </div>
      </FullScreenModal>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle={REPORT_ARTIFACTS[outputArtifact]?.title || "Food Production Report"}
        description="View this report or download the selected production artifact from the backend."
        busy={exporting}
        busyLabel="Preparing production report…"
        onClose={() => setChooserOpen(false)}
        onView={viewReport}
        onDownloadPdf={() => downloadReport("pdf")}
        onDownloadExcel={() => downloadReport("excel")}
        outputs={chooserSource === "result" ? { view: { disabled: true, reason: "This report is already open." } } : undefined}
      />
      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={() => setMessageModal((p) => ({ ...p, open: false }))} />
      <style>{`.food-report-page button,.food-report-page input,.food-report-page select{border-radius:0!important}`}</style>
    </>
  );
}

function Metric({ label, value, danger = false }) { return <div className={`border px-3 py-2 ${danger ? "border-[#FDA29B] bg-[#FEF3F2]" : "border-[#D8DFDF] bg-white"}`}><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-1 truncate text-[14px] font-semibold ${danger ? "text-[#B42318]" : "text-[#004343]"}`}>{value}</div></div>; }
function Th({ children }) { return <th className="border-r border-[#465857] px-2 py-1.5 text-left font-semibold">{children}</th>; }
function Td({ children, strong = false, danger = false }) { return <td className={`border-r border-[#CFD9D8] px-2 py-1.5 ${danger ? "font-semibold text-[#B42318]" : strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}>{children}</td>; }
function Empty({ colSpan = 8, text = "No data found." }) { return <tr><td colSpan={colSpan} className="px-3 py-10 text-center text-[#627474]">{text}</td></tr>; }
function Table({ children }) { return <table className="sx-enterprise-table w-full min-w-[1180px] border-collapse text-left text-[11px]">{children}</table>; }

function BatchTable({ batches, currency }) { return <Table><thead><tr><Th>#</Th><Th>Batch</Th><Th>Date</Th><Th>Recipe</Th><Th>Produced Item</Th><Th>Areas</Th><Th>Planned</Th><Th>Actual</Th><Th>Total Cost</Th><Th>Unit Cost</Th></tr></thead><tbody>{!batches.length ? <Empty colSpan={10} /> : batches.map((b, i) => <tr key={b.id || i} className="border-b border-[#CFD9D8]"><Td>{i + 1}</Td><Td strong>{b.batch_number || b.id}</Td><Td>{b.created_at || "-"}</Td><Td>{b.recipe_name || "-"}</Td><Td>{b.produced_product_name || "-"}</Td><Td>{`${b.source_stock_area || "-"} -> ${b.destination_stock_area || "-"}`}</Td><Td>{qty(b.planned_yield_quantity)}</Td><Td>{qty(b.actual_yield_quantity)}</Td><Td>{money(b.currency || currency, b.total_cost)}</Td><Td>{money(b.currency || currency, b.unit_cost)}</Td></tr>)}</tbody></Table>; }
function PlansTable({ plans, currency }) { return <Table><thead><tr><Th>#</Th><Th>Plan</Th><Th>Date</Th><Th>Recipe</Th><Th>Produced Item</Th><Th>Planned Qty</Th><Th>Areas</Th><Th>Status</Th><Th>Short Lines</Th><Th>Short Value</Th></tr></thead><tbody>{!plans.length ? <Empty colSpan={10} text="No saved plans found." /> : plans.map((p, i) => <tr key={p.id || i} className="border-b border-[#CFD9D8]"><Td>{i + 1}</Td><Td strong>{p.plan_number || p.id}</Td><Td>{p.created_at || "-"}</Td><Td>{p.recipe_name || p.recipe?.name || "-"}</Td><Td>{p.produced_product_name || p.produced_product?.name || p.producedProduct?.name || "-"}</Td><Td>{qty(p.planned_quantity)}</Td><Td>{`${p.source_stock_area || "-"} -> ${p.destination_stock_area || "-"}`}</Td><Td>{p.status}</Td><Td danger={Number(p.shortage_line_count || 0) > 0}>{p.shortage_line_count || 0}</Td><Td>{money(p.currency || currency, p.shortage_value)}</Td></tr>)}</tbody></Table>; }
function LineTable({ lines, currency }) { return <Table><thead><tr><Th>#</Th><Th>Batch</Th><Th>Recipe</Th><Th>Ingredient</Th><Th>Area</Th><Th>Net Qty</Th><Th>Wastage</Th><Th>Gross Qty</Th><Th>Unit Cost</Th><Th>Line Cost</Th></tr></thead><tbody>{!lines.length ? <Empty colSpan={10} /> : lines.map((l, i) => <tr key={`${l.batch_id}-${l.ingredient_product_id}-${i}`} className="border-b border-[#CFD9D8]"><Td>{i + 1}</Td><Td strong>{l.batch_number}</Td><Td>{l.recipe_name}</Td><Td>{l.ingredient_name}<div className="text-[9px] text-[#627474]">{l.ingredient_code}</div></Td><Td>{l.stock_area}</Td><Td>{qty(l.net_quantity)}</Td><Td>{qty(l.wastage_quantity)} / {qty(l.wastage_percent)}%</Td><Td>{qty(l.gross_quantity)}</Td><Td>{money(l.currency || currency, l.unit_cost)}</Td><Td>{money(l.currency || currency, l.line_cost)}</Td></tr>)}</tbody></Table>; }
function PlanTable({ plan, lines, currency }) { return <div><div className="grid grid-cols-4 gap-2 p-2"><Metric label="Plan Quantity" value={qty(plan?.summary?.planned_quantity)} /><Metric label="Lines" value={plan?.summary?.line_count || 0} /><Metric label="Short Lines" value={plan?.summary?.shortage_line_count || 0} danger={Number(plan?.summary?.shortage_line_count || 0) > 0} /><Metric label="Short Value" value={money(currency, plan?.summary?.shortage_value)} danger={Number(plan?.summary?.shortage_value || 0) > 0} /></div><ShortageTable lines={lines} currency={currency} showAll /></div>; }
function ShortageTable({ lines, currency, showAll = false }) { return <Table><thead><tr><Th>#</Th><Th>Ingredient</Th><Th>UOM</Th><Th>Required</Th><Th>Available</Th><Th>Shortage</Th><Th>Unit Cost</Th><Th>Shortage Value</Th><Th>Action</Th></tr></thead><tbody>{!lines.length ? <Empty colSpan={9} text={showAll ? "No plan loaded." : "No shortages found."} /> : lines.map((l, i) => <tr key={l.ingredient_product_id || i} className={`border-b border-[#CFD9D8] ${Number(l.shortage_quantity || 0) > 0 ? "bg-[#FFF1F0]" : "bg-white"}`}><Td>{i + 1}</Td><Td strong>{l.ingredient_name}<div className="text-[9px] text-[#627474]">{l.ingredient_code}</div></Td><Td>{l.uom || "EACH"}</Td><Td>{qty(l.required_quantity)}</Td><Td>{qty(l.available_quantity)}</Td><Td danger={Number(l.shortage_quantity || 0) > 0}>{qty(l.shortage_quantity)}</Td><Td>{money(l.currency || currency, l.unit_cost)}</Td><Td>{money(l.currency || currency, l.shortage_value)}</Td><Td>{Number(l.shortage_quantity || 0) > 0 ? "Receive / Transfer" : "Ready"}</Td></tr>)}</tbody></Table>; }

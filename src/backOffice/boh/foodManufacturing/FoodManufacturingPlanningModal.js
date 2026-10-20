import React, { useEffect, useMemo, useState } from "react";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getProducts } from "../../../services/ProductService";
import {
  getFoodRecipes,
  getFoodManufacturingProductionPlan,
  saveFoodManufacturingProductionPlan,
  getFoodManufacturingProductionPlans,
  getFoodManufacturingSavedPlan,
  deleteFoodManufacturingSavedPlan,
  createFoodManufacturingBatch,
  downloadFoodManufacturingPlanningExcel,
  downloadFoodManufacturingPlanningPdf,
  downloadFoodManufacturingSavedPlansExcel,
  downloadFoodManufacturingSavedPlansPdf,
  buildFoodManufacturingPlanPayload,
  buildFoodManufacturingBatchPayload,
  buildFoodManufacturingReportParams,
} from "../../../services/FoodManufacturingService";

const normalizeRows = (response) => {
  const payload = response?.data ?? response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

const unwrapData = (response) => response?.data?.data || response?.data || response || {};
const getBranchId = (user) => user?.branch?.id || user?.branch_id || user?.branchId || "";
const qty = (value) => Number(value || 0).toFixed(4);
const money = (currency, value) => `${currency || "USD"} ${Number(value || 0).toFixed(4)}`;
const today = () => new Date().toISOString().slice(0, 10);
const monthStart = () => `${new Date().toISOString().slice(0, 8)}01`;
const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

const getBranchName = (branch) => branch?.branch_name || branch?.name || "Unnamed Branch";
const getProductName = (product) => product?.name || product?.product_name || "-";
const getProductCode = (product) => product?.code || product?.sku || product?.barcode || "-";
const getProductType = (product) => String(product?.product_type || "").replaceAll("_", " ");
const getRecipeOutputProductId = (recipe) =>
  recipe?.produced_product_id ||
  recipe?.producedProduct?.id ||
  recipe?.produced_product?.id ||
  recipe?.menu_product_id ||
  recipe?.menuProduct?.id ||
  recipe?.menu_product?.id ||
  "";
const getRecipeProduct = (recipe) =>
  recipe?.produced_product?.name ||
  recipe?.producedProduct?.name ||
  recipe?.menu_product?.name ||
  recipe?.menuProduct?.name ||
  recipe?.menu_product_name ||
  "-";

const normalizeMaterialLine = (row) => ({
  ...row,
  requiredQty: Number(row.required_quantity || 0),
  availableQty: Number(row.available_quantity || 0),
  shortageQty: Number(row.shortage_quantity || 0),
  unitCost: Number(row.unit_cost || 0),
  requiredValue: Number(row.required_value || 0),
  shortageValue: Number(row.shortage_value || 0),
});

export default function FoodManufacturingPlanningModal({ open, onClose, currentUser = null }) {
  const defaultBranchId = getBranchId(currentUser);
  const [activeTab, setActiveTab] = useState("calculator");
  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);
  const [branches, setBranches] = useState([]);
  const [savedPlans, setSavedPlans] = useState([]);
  const [selectedPlanId, setSelectedPlanId] = useState("");
  const [selectedRecipeId, setSelectedRecipeId] = useState("");
  const [producedProductId, setProducedProductId] = useState("");
  const [branchId, setBranchId] = useState(defaultBranchId);
  const [sourceStockArea, setSourceStockArea] = useState("back");
  const [destinationStockArea, setDestinationStockArea] = useState("back");
  const [plannedQuantity, setPlannedQuantity] = useState("");
  const [actualQuantity, setActualQuantity] = useState("");
  const [plannedFor, setPlannedFor] = useState(today());
  const [notes, setNotes] = useState("");
  const [filters, setFilters] = useState({ date_from: monthStart(), date_to: today(), status: "", search: "" });
  const [materialPlan, setMaterialPlan] = useState(null);
  const [loading, setLoading] = useState(false);
  const [planLoading, setPlanLoading] = useState(false);
  const [planEditorOpen, setPlanEditorOpen] = useState(false);
  const [messageModal, setMessageModal] = useState({ open: false, type: "success", title: "", message: "" });

  const selectedRecipe = useMemo(
    () => recipes.find((recipe) => String(recipe.id) === String(selectedRecipeId)) || null,
    [recipes, selectedRecipeId]
  );

  const materialLines = useMemo(() => {
    const lines = Array.isArray(materialPlan?.lines) ? materialPlan.lines : [];
    return lines.map(normalizeMaterialLine);
  }, [materialPlan]);

  const explodedMaterialLines = useMemo(() => {
    const lines = Array.isArray(materialPlan?.exploded_lines) ? materialPlan.exploded_lines : [];
    return lines.map(normalizeMaterialLine);
  }, [materialPlan]);

  const summary = materialPlan?.summary || {};
  const shortageLines = Number(summary.shortage_line_count ?? materialLines.filter((line) => line.shortageQty > 0).length);
  const requiredValue = Number(summary.required_value ?? materialLines.reduce((sum, line) => sum + line.requiredValue, 0));
  const shortageValue = Number(summary.shortage_value ?? materialLines.reduce((sum, line) => sum + line.shortageValue, 0));
  const canProduce = materialLines.length > 0 && shortageLines === 0;
  const currency = summary.currency_code && summary.currency_code !== "MULTI" ? summary.currency_code : "USD";

  const visiblePlans = useMemo(() => {
    const q = String(filters.search || "").trim().toLowerCase();
    return savedPlans.filter((plan) => {
      const statusOk = !filters.status || String(plan.status || "") === filters.status;
      const textOk = !q ||
        String(plan.plan_number || plan.id || "").toLowerCase().includes(q) ||
        String(plan.recipe_name || plan.recipe?.name || "").toLowerCase().includes(q) ||
        String(plan.produced_product_name || plan.produced_product?.name || plan.producedProduct?.name || "").toLowerCase().includes(q);
      return statusOk && textOk;
    });
  }, [savedPlans, filters.status, filters.search]);

  const coveragePercent = materialLines.length
    ? Math.min(100, Math.max(0, ((requiredValue - shortageValue) / Math.max(requiredValue, 0.000001)) * 100))
    : 0;

  const shortagePercent = materialLines.length
    ? Math.min(100, Math.max(0, (shortageValue / Math.max(requiredValue, 0.000001)) * 100))
    : 0;

  const topShortages = useMemo(
    () => materialLines
      .filter((line) => Number(line.shortageQty || 0) > 0)
      .sort((a, b) => Number(b.shortageValue || 0) - Number(a.shortageValue || 0))
      .slice(0, 6),
    [materialLines]
  );

  const topRequired = useMemo(
    () => materialLines
      .slice()
      .sort((a, b) => Number(b.requiredValue || 0) - Number(a.requiredValue || 0))
      .slice(0, 6),
    [materialLines]
  );

  useEffect(() => {
    if (open) loadAll();
  }, [open]);

  const showMessage = ({ type = "success", title, message }) =>
    setMessageModal({ open: true, type, title, message });

  const reportParams = () => buildFoodManufacturingReportParams({
    branch_id: branchId,
    date_from: filters.date_from,
    date_to: filters.date_to,
    recipe_id: selectedRecipeId,
    produced_product_id: producedProductId,
    source_stock_area: sourceStockArea,
    destination_stock_area: destinationStockArea,
    planned_quantity: plannedQuantity,
    status: filters.status,
    per_page: 500,
  });

  const loadAll = async () => {
    try {
      setLoading(true);
      const [manufacturingRes, prepRes, companyRes, productRes, plansRes] = await Promise.all([
        getFoodRecipes({ per_page: 500, recipe_type: "manufacturing", with_ingredients: true }),
        getFoodRecipes({ per_page: 500, recipe_type: "prep", with_ingredients: true }),
        getAuthorisedCompany(),
        getProducts({ per_page: 500, product_types: "semi_finished", is_active: true }),
        getFoodManufacturingProductionPlans({ per_page: 500, date_from: filters.date_from, date_to: filters.date_to }),
      ]);

      const mergedRecipes = [...normalizeRows(manufacturingRes), ...normalizeRows(prepRes)]
        .filter((recipe) => ["manufacturing", "prep"].includes(String(recipe?.recipe_type || "").toLowerCase()))
        .filter((recipe, index, list) => list.findIndex((item) => String(item.id) === String(recipe.id)) === index);

      const company = companyRes?.company || companyRes?.data?.company || null;

      setRecipes(mergedRecipes);
      setProducts(normalizeRows(productRes).filter((product) => String(product.product_type || "").toLowerCase() === "semi_finished"));
      setSavedPlans(normalizeRows(plansRes));
      setBranches(
        companyRes?.company?.branches ||
          companyRes?.data?.branches ||
          companyRes?.data?.company?.branches ||
          company?.branches ||
          []
      );
    } catch (error) {
      showMessage({ type: "error", title: "Load Failed", message: getErrorMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const selectRecipe = (recipeId) => {
    const recipe = recipes.find((item) => String(item.id) === String(recipeId));
    const outputId = getRecipeOutputProductId(recipe);
    const baseQty = recipe?.yield_quantity ? String(recipe.yield_quantity) : "";

    setSelectedRecipeId(recipeId);
    setProducedProductId(outputId || "");
    setSourceStockArea(recipe?.source_stock_area || "back");
    setDestinationStockArea(recipe?.destination_stock_area || "back");
    setPlannedQuantity(baseQty);
    setActualQuantity(baseQty);
    setSelectedPlanId("");
    setMaterialPlan(null);
  };

  const openNewPlan = () => {
    setSelectedRecipeId("");
    setProducedProductId("");
    setBranchId(defaultBranchId);
    setSourceStockArea("back");
    setDestinationStockArea("back");
    setPlannedQuantity("");
    setActualQuantity("");
    setPlannedFor(today());
    setSelectedPlanId("");
    setMaterialPlan(null);
    setNotes("");
    setPlanEditorOpen(true);
  };

  const calculatePlan = async () => {
    if (!selectedRecipeId) return showMessage({ type: "error", title: "Validation", message: "Select production recipe." });
    if (!producedProductId) return showMessage({ type: "error", title: "Validation", message: "Select produced stock item." });
    if (!branchId) return showMessage({ type: "error", title: "Validation", message: "Select branch." });
    if (!Number(plannedQuantity || 0)) return showMessage({ type: "error", title: "Validation", message: "Enter planned quantity." });

    try {
      setPlanLoading(true);
      const res = await getFoodManufacturingProductionPlan({
        branch_id: branchId,
        recipe_id: selectedRecipeId,
        produced_product_id: producedProductId,
        source_stock_area: sourceStockArea,
        destination_stock_area: destinationStockArea,
        planned_quantity: Number(plannedQuantity || 0),
      });
      setMaterialPlan(unwrapData(res));
      setActiveTab("materials");
    } catch (error) {
      setMaterialPlan(null);
      showMessage({ type: "error", title: "Calculation Failed", message: getErrorMessage(error) });
    } finally {
      setPlanLoading(false);
    }
  };

  const savePlan = async () => {
    if (!materialPlan || materialLines.length === 0) {
      await calculatePlan();
      return;
    }

    try {
      setLoading(true);
      const payload = buildFoodManufacturingPlanPayload({
        branch_id: branchId,
        recipe_id: selectedRecipeId,
        produced_product_id: producedProductId,
        source_stock_area: sourceStockArea,
        destination_stock_area: destinationStockArea,
        planned_quantity: plannedQuantity,
        planned_for: plannedFor,
        notes,
      });
      const res = await saveFoodManufacturingProductionPlan(payload);
      const saved = unwrapData(res);
      setSelectedPlanId(saved?.id || "");
      showMessage({ title: "Plan Saved", message: res?.message || "Production plan saved successfully." });
      await loadSavedPlans();
      setActiveTab("plans");
    } catch (error) {
      showMessage({ type: "error", title: "Save Failed", message: getErrorMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const loadSavedPlans = async () => {
    const res = await getFoodManufacturingProductionPlans({
      per_page: 500,
      branch_id: branchId || undefined,
      date_from: filters.date_from,
      date_to: filters.date_to,
      status: filters.status || undefined,
    });
    setSavedPlans(normalizeRows(res));
  };

  const openSavedPlan = async (plan) => {
    try {
      setLoading(true);
      const res = await getFoodManufacturingSavedPlan(plan.id);
      const saved = unwrapData(res);
      setSelectedPlanId(saved.id || plan.id);
      setSelectedRecipeId(saved.food_recipe_id || saved.recipe_id || saved.recipe?.id || "");
      setProducedProductId(saved.produced_product_id || saved.producedProduct?.id || saved.produced_product?.id || "");
      setBranchId(saved.branch_id || branchId);
      setSourceStockArea(saved.source_stock_area || "back");
      setDestinationStockArea(saved.destination_stock_area || "back");
      setPlannedQuantity(String(saved.planned_quantity || ""));
      setActualQuantity(String(saved.actual_quantity || saved.planned_quantity || ""));
      setPlannedFor(saved.planned_for || today());
      setNotes(saved.notes || "");
      setMaterialPlan({
        filters: saved.filters || {},
        recipe: saved.recipe || {},
        summary: saved.summary || {},
        lines: saved.lines || [],
        exploded_lines: saved.exploded_lines || [],
        subrecipes: saved.subrecipe_requirements || saved.subrecipes || [],
      });
      setActiveTab("materials");
      setPlanEditorOpen(true);
    } catch (error) {
      showMessage({ type: "error", title: "Plan Open Failed", message: getErrorMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const removeSavedPlan = async (plan) => {
    if (!await requestEnterpriseConfirmation({
      title: "Delete Production Plan",
      message: `Delete production plan ${plan.plan_number || plan.id}?`,
      confirmLabel: "Delete plan",
    })) return;
    try {
      setLoading(true);
      await deleteFoodManufacturingSavedPlan(plan.id);
      if (String(selectedPlanId) === String(plan.id)) setSelectedPlanId("");
      await loadSavedPlans();
      showMessage({ title: "Plan Deleted", message: "Production plan deleted successfully." });
    } catch (error) {
      showMessage({ type: "error", title: "Delete Failed", message: getErrorMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const recordProduction = async () => {
    const actualQty = Number(actualQuantity || plannedQuantity || 0);
    if (!selectedRecipeId) return showMessage({ type: "error", title: "Validation", message: "Select production recipe." });
    if (!producedProductId) return showMessage({ type: "error", title: "Validation", message: "Select produced stock item." });
    if (!branchId) return showMessage({ type: "error", title: "Validation", message: "Select branch." });
    if (!actualQty) return showMessage({ type: "error", title: "Validation", message: "Actual quantity must be greater than zero." });
    if (shortageLines > 0) return showMessage({ type: "error", title: "Material Shortage", message: "Resolve shortages before recording production." });

    try {
      setLoading(true);
      const payload = buildFoodManufacturingBatchPayload({
        recipe_id: selectedRecipeId,
        branch_id: branchId,
        produced_product_id: producedProductId,
        source_stock_area: sourceStockArea,
        destination_stock_area: destinationStockArea,
        planned_yield_quantity: Number(plannedQuantity || actualQty),
        actual_yield_quantity: actualQty,
        plan_id: selectedPlanId || null,
        notes,
      });
      const res = await createFoodManufacturingBatch(payload);
      showMessage({ title: "Production Recorded", message: res?.message || "Production recorded and stock updated." });
      await loadSavedPlans();
      setPlanEditorOpen(false);
    } catch (error) {
      showMessage({ type: "error", title: "Record Failed", message: getErrorMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const exportMaterialExcel = async () => {
    try { await downloadFoodManufacturingPlanningExcel(reportParams()); } catch (error) { showMessage({ type: "error", title: "Excel Failed", message: getErrorMessage(error) }); }
  };
  const exportMaterialPdf = async () => {
    try { await downloadFoodManufacturingPlanningPdf(reportParams()); } catch (error) { showMessage({ type: "error", title: "PDF Failed", message: getErrorMessage(error) }); }
  };
  const exportSavedExcel = async () => {
    try { await downloadFoodManufacturingSavedPlansExcel({ branch_id: branchId || undefined, date_from: filters.date_from, date_to: filters.date_to, status: filters.status || undefined }); } catch (error) { showMessage({ type: "error", title: "Excel Failed", message: getErrorMessage(error) }); }
  };
  const exportSavedPdf = async () => {
    try { await downloadFoodManufacturingSavedPlansPdf({ branch_id: branchId || undefined, date_from: filters.date_from, date_to: filters.date_to, status: filters.status || undefined }); } catch (error) { showMessage({ type: "error", title: "PDF Failed", message: getErrorMessage(error) }); }
  };

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title="PRODUCTION PLANNING" subtitle="Material requirements, shortage checking, saved plans and production release">
        <div className="food-plan-page h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
          <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
            <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
              <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
                <div className="text-[13px] font-semibold text-[#0C1B1B]">Production planning desk</div>
                <div className="flex gap-1">
                  <ActionButton onClick={openNewPlan} label="New Plan" primary />
                  <ActionButton onClick={loadAll} label={loading ? "Loading" : "Refresh"} />
                </div>
              </div>

              <div className="grid grid-cols-6 gap-2 p-2">
                <Metric label="Status" value={selectedPlanId ? "Saved" : canProduce ? "Ready" : materialLines.length ? "Shortage" : "Not Calculated"} ok={!!selectedPlanId || canProduce} danger={materialLines.length > 0 && !canProduce} />
                <Metric label="Recipe Yield" value={qty(selectedRecipe?.yield_quantity)} />
                <Metric label="Required Value" value={money(currency, requiredValue)} />
                <Metric label="Short Lines" value={shortageLines} danger={shortageLines > 0} />
                <Metric label="Short Value" value={money(currency, shortageValue)} danger={shortageValue > 0} />
                <Metric label="Saved Plans" value={savedPlans.length} />
              </div>
            </div>

            <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
              {[['calculator', 'Calculator'], ['materials', 'Direct Requirements'], ['exploded', 'Exploded BOM'], ['plans', 'Saved Plans'], ['shortages', 'Shortages']].map(([key, label]) => (
                <button key={key} type="button" onClick={() => setActiveTab(key)} className={`h-7 border border-b-0 px-4 text-[11px] font-semibold ${activeTab === key ? "border-[#004343] bg-[#004344] text-white" : "border-[#AAB5B5] bg-white text-[#324443]"}`}>{label}</button>
              ))}
            </div>

            <div className="min-h-0 flex-1 overflow-auto bg-[#F7F8FA] p-2">
              {activeTab === "calculator" && (
                <div className="grid gap-2 xl:grid-cols-[minmax(360px,0.7fr)_minmax(620px,1.3fr)]">
                  <Panel title="How to Plan">
                    <p>Click New Plan to enter the recipe, output item, branch, stock areas and planned quantity in the centered planning dialog.</p>
                    <p className="mt-2">Only semi-finished items are shown as produced stock items. Finished goods are excluded from production output selection.</p>
                    <p className="mt-2">Saved-plan rows use Open to return to that same dialog. Recording remains disabled by validation while shortages exist.</p>
                  </Panel>

                  <PlanVisuals
                    currency={currency}
                    materialLines={materialLines}
                    requiredValue={requiredValue}
                    shortageValue={shortageValue}
                    shortageLines={shortageLines}
                    coveragePercent={coveragePercent}
                    shortagePercent={shortagePercent}
                    topShortages={topShortages}
                    topRequired={topRequired}
                  />

                </div>
              )}

              {activeTab === "materials" && (
                <>
                  <div className="mb-2 flex justify-end gap-1"><ActionButton onClick={exportMaterialExcel} label="Material Excel" /><ActionButton onClick={exportMaterialPdf} label="Material PDF" /></div>
                  <div className="mb-2">
                    <PlanVisuals
                      currency={currency}
                      materialLines={materialLines}
                      requiredValue={requiredValue}
                      shortageValue={shortageValue}
                      shortageLines={shortageLines}
                      coveragePercent={coveragePercent}
                      shortagePercent={shortagePercent}
                      topShortages={topShortages}
                      topRequired={topRequired}
                    />
                  </div>
                  <MaterialTable lines={materialLines} currency={currency} />
                </>
              )}

              {activeTab === "exploded" && (
                <>
                  <div className="mb-2 border border-[#AAB5B5] bg-white p-3 text-[11px] text-[#324443]">
                    <div className="font-semibold uppercase text-[#004343]">Multi-level material requirement</div>
                    <div className="mt-1">
                      Prepared sub-recipes are expanded to their leaf ingredients for planning only. Production posting still consumes each approved recipe once, preventing raw materials from being deducted twice.
                    </div>
                    <div className="mt-2 grid gap-2 sm:grid-cols-4">
                      <Metric label="BOM Depth" value={summary.bom_max_depth || 0} />
                      <Metric label="Sub-recipes" value={summary.subrecipe_count || 0} />
                      <Metric label="Leaf Materials" value={summary.exploded_line_count || explodedMaterialLines.length} />
                      <Metric label="Missing Costs" value={summary.missing_cost_line_count || 0} danger={Number(summary.missing_cost_line_count || 0) > 0} />
                    </div>
                  </div>
                  <MaterialTable lines={explodedMaterialLines} currency={currency} exploded />
                </>
              )}

              {activeTab === "plans" && (
                <>
                  <div className="mb-2 grid grid-cols-[120px_120px_140px_minmax(220px,1fr)_auto] gap-2">
                    <Field label="From"><input type="date" value={filters.date_from} onChange={(e) => setFilters((p) => ({ ...p, date_from: e.target.value }))} className="plan-input" /></Field>
                    <Field label="To"><input type="date" value={filters.date_to} onChange={(e) => setFilters((p) => ({ ...p, date_to: e.target.value }))} className="plan-input" /></Field>
                    <Field label="Status"><select value={filters.status} onChange={(e) => setFilters((p) => ({ ...p, status: e.target.value }))} className="plan-input"><option value="">All</option><option value="ready">Ready</option><option value="shortage">Shortage</option><option value="produced">Produced</option><option value="draft">Draft</option></select></Field>
                    <Field label="Search"><input value={filters.search} onChange={(e) => setFilters((p) => ({ ...p, search: e.target.value }))} className="plan-input" placeholder="Search plan, recipe or item" /></Field>
                    <div className="flex items-end gap-1"><ActionButton onClick={loadSavedPlans} label="Load" /><ActionButton onClick={exportSavedExcel} label="Plans Excel" /><ActionButton onClick={exportSavedPdf} label="Plans PDF" /></div>
                  </div>
                  <PlansTable plans={visiblePlans} currency={currency} selectedPlanId={selectedPlanId} onOpen={openSavedPlan} onDelete={removeSavedPlan} />
                </>
              )}

              {activeTab === "shortages" && <MaterialTable lines={materialLines.filter((line) => line.shortageQty > 0)} currency={currency} shortageOnly />}
            </div>
          </div>
        </div>
      </FullScreenModal>

      <ManufacturingFormModal
        open={open && planEditorOpen}
        title={selectedPlanId ? "Edit Production Plan" : "New Production Plan"}
        subtitle="Plan, validate and record production."
        onClose={() => setPlanEditorOpen(false)}
        busy={loading || planLoading}
        pageClass="food-plan-page"
        width="1280px"
        footer={(
          <>
            <ActionButton onClick={() => setPlanEditorOpen(false)} label="Cancel" disabled={loading || planLoading} />
            <ActionButton onClick={calculatePlan} label={planLoading ? "Calculating" : "Calculate"} disabled={loading || planLoading} />
            <ActionButton onClick={savePlan} label={loading ? "Saving" : "Save Plan"} disabled={loading || planLoading} />
            <ActionButton onClick={recordProduction} label={loading ? "Recording" : "Record Production"} primary disabled={loading || planLoading} />
          </>
        )}
      >
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <Field label="Recipe">
            <select value={selectedRecipeId} onChange={(e) => selectRecipe(e.target.value)} className="plan-input">
              <option value="">Select Recipe</option>
              {recipes.map((recipe) => <option key={recipe.id} value={recipe.id}>{recipe.name || recipe.recipe_name} - {getRecipeProduct(recipe)}</option>)}
            </select>
          </Field>
          <Field label="Produced Stock Item">
            <select value={producedProductId} onChange={(e) => { setProducedProductId(e.target.value); setSelectedPlanId(""); }} className="plan-input">
              <option value="">Select Item To Produce</option>
              {products.map((product) => <option key={product.id} value={product.id}>{getProductName(product)} - {getProductCode(product)} - {getProductType(product)}</option>)}
            </select>
          </Field>
          <Field label="Branch">
            <select value={branchId} onChange={(e) => { setBranchId(e.target.value); setSelectedPlanId(""); }} className="plan-input">
              <option value="">Select Branch</option>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
            </select>
          </Field>
          <Field label="Consume From">
            <select value={sourceStockArea} onChange={(e) => { setSourceStockArea(e.target.value); setSelectedPlanId(""); }} className="plan-input"><option value="back">Back</option><option value="front">Front</option></select>
          </Field>
          <Field label="Output To">
            <select value={destinationStockArea} onChange={(e) => { setDestinationStockArea(e.target.value); setSelectedPlanId(""); }} className="plan-input"><option value="back">Back</option><option value="front">Front</option></select>
          </Field>
          <Field label="Planned Qty">
            <input type="number" step="0.0001" value={plannedQuantity} onChange={(e) => { setPlannedQuantity(e.target.value); if (!actualQuantity) setActualQuantity(e.target.value); setSelectedPlanId(""); }} className="plan-input" />
          </Field>
          <Field label="Actual Qty">
            <input type="number" step="0.0001" value={actualQuantity} onChange={(e) => setActualQuantity(e.target.value)} className="plan-input" />
          </Field>
          <Field label="Planned For">
            <input type="date" value={plannedFor} onChange={(e) => setPlannedFor(e.target.value)} className="plan-input" />
          </Field>
        </div>

        <div className="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-5">
          <Metric label="Status" value={selectedPlanId ? "Saved" : canProduce ? "Ready" : materialLines.length ? "Shortage" : "Not Calculated"} ok={!!selectedPlanId || canProduce} danger={materialLines.length > 0 && !canProduce} />
          <Metric label="Recipe Yield" value={qty(selectedRecipe?.yield_quantity)} />
          <Metric label="Required Value" value={money(currency, requiredValue)} />
          <Metric label="Short Lines" value={shortageLines} danger={shortageLines > 0} />
          <Metric label="Short Value" value={money(currency, shortageValue)} danger={shortageValue > 0} />
        </div>

        <div className="mt-3 max-h-[38vh] overflow-auto border border-[#AAB5B5] bg-white">
          <MaterialTable lines={materialLines} currency={currency} />
        </div>

        <Field label="Production Notes">
          <textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="mt-3 min-h-[72px] w-full border border-[#AAB5B5] bg-white p-2 text-[11px] outline-none" />
        </Field>
      </ManufacturingFormModal>

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={() => setMessageModal((p) => ({ ...p, open: false }))} />
      <Styles />
    </>
  );
}


function PlanVisuals({
  currency,
  materialLines,
  requiredValue,
  shortageValue,
  shortageLines,
  coveragePercent,
  shortagePercent,
  topShortages,
  topRequired,
}) {
  const hasPlan = materialLines.length > 0;
  const coveredValue = Math.max(Number(requiredValue || 0) - Number(shortageValue || 0), 0);

  return (
    <section className="border border-[#AFB9B9] bg-white p-3">
      <div className="mb-2 flex items-center justify-between border-b border-[#E2E9E9] pb-2">
        <div>
          <div className="text-[12px] font-semibold uppercase text-[#324443]">Planning Visuals</div>
          <div className="text-[10px] font-semibold uppercase text-[#627474]">Requirement value, coverage and shortage exposure</div>
        </div>
        <div className={`border px-2 py-1 text-[10px] font-semibold uppercase ${shortageLines > 0 ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : hasPlan ? "border-[#B7E4C7] bg-[#ECFDF3] text-[#027A48]" : "border-[#D8DFDF] bg-[#F8FAFC] text-[#627474]"}`}>
          {hasPlan ? shortageLines > 0 ? "Shortage" : "Ready" : "No Plan"}
        </div>
      </div>

      <div className="grid gap-2 lg:grid-cols-[220px_minmax(280px,1fr)]">
        <div className="border border-[#D8DFDF] bg-[#F8FAFC] p-3">
          <DonutChart
            title="Material Value"
            valueA={coveredValue}
            valueB={shortageValue}
            labelA="Covered"
            labelB="Shortage"
            currency={currency}
          />
        </div>

        <div className="grid gap-2">
          <div className="border border-[#D8DFDF] bg-white p-3">
            <div className="mb-1 flex justify-between text-[10px] font-semibold uppercase text-[#627474]"><span>Stock Coverage</span><span>{qty(coveragePercent)}%</span></div>
            <ProgressBar value={coveragePercent} tone="ok" />
            <div className="mt-2 flex justify-between text-[10px] font-semibold uppercase text-[#627474]"><span>Shortage Exposure</span><span>{qty(shortagePercent)}%</span></div>
            <ProgressBar value={shortagePercent} tone="danger" />
          </div>

          <div className="grid gap-2 lg:grid-cols-2">
            <BarList title="Top Required Value" rows={topRequired} valueKey="requiredValue" currency={currency} emptyText="No requirements calculated." />
            <BarList title="Top Shortages" rows={topShortages} valueKey="shortageValue" currency={currency} emptyText="No shortage lines." danger />
          </div>
        </div>
      </div>
    </section>
  );
}

function DonutChart({ title, valueA, valueB, labelA, labelB, currency }) {
  const total = Math.max(Number(valueA || 0) + Number(valueB || 0), 0.000001);
  const pctA = Math.max(0, Math.min(100, (Number(valueA || 0) / total) * 100));
  const pctB = 100 - pctA;
  const circumference = 100;

  return (
    <div>
      <div className="mb-2 text-[10px] font-semibold uppercase text-[#627474]">{title}</div>
      <div className="flex items-center gap-3">
        <svg viewBox="0 0 42 42" className="h-[118px] w-[118px]">
          <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#E4E8E8" strokeWidth="7" />
          <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#12B76A" strokeWidth="7" strokeDasharray={`${pctA} ${circumference - pctA}`} strokeDashoffset="25" />
          <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#F04438" strokeWidth="7" strokeDasharray={`${pctB} ${circumference - pctB}`} strokeDashoffset={25 - pctA} />
          <text x="21" y="20" textAnchor="middle" className="fill-[#003434] text-[6px] font-semibold">{qty(pctA)}%</text>
          <text x="21" y="26" textAnchor="middle" className="fill-[#627474] text-[4px] font-semibold">covered</text>
        </svg>
        <div className="min-w-0 flex-1 text-[11px]">
          <LegendRow color="#12B76A" label={labelA} value={money(currency, valueA)} />
          <LegendRow color="#F04438" label={labelB} value={money(currency, valueB)} />
          <div className="mt-2 border-t border-[#E4E8E8] pt-2 font-semibold text-[#0C1B1B]">Total: {money(currency, total)}</div>
        </div>
      </div>
    </div>
  );
}

function LegendRow({ color, label, value }) {
  return (
    <div className="mb-1 flex items-center justify-between gap-2">
      <div className="flex min-w-0 items-center gap-2"><span style={{ background: color }} className="h-3 w-3 border border-black/10" /><span className="truncate text-[#324443]">{label}</span></div>
      <span className="font-semibold text-[#0C1B1B]">{value}</span>
    </div>
  );
}

function ProgressBar({ value, tone = "ok" }) {
  const safe = Math.max(0, Math.min(100, Number(value || 0)));
  const bar = tone === "danger" ? "#F04438" : "#12B76A";
  return <div className="h-3 border border-[#CFD6D6] bg-[#F2F4F7]"><div className="h-full" style={{ width: `${safe}%`, background: bar }} /></div>;
}

function BarList({ title, rows, valueKey, currency, emptyText, danger = false }) {
  const max = Math.max(...rows.map((row) => Number(row[valueKey] || 0)), 0.000001);
  return (
    <div className="border border-[#D8DFDF] bg-white p-2">
      <div className="mb-2 text-[10px] font-semibold uppercase text-[#627474]">{title}</div>
      {!rows.length ? (
        <div className="py-5 text-center text-[11px] font-semibold text-[#627474]">{emptyText}</div>
      ) : rows.map((row) => {
        const value = Number(row[valueKey] || 0);
        const pct = Math.max(0, Math.min(100, (value / max) * 100));
        return (
          <div key={`${row.ingredient_product_id}-${valueKey}`} className="mb-2">
            <div className="mb-1 flex justify-between gap-2 text-[10px] font-semibold">
              <span className="truncate text-[#324443]">{row.ingredient_name || "-"}</span>
              <span className={danger ? "text-[#B42318]" : "text-[#0C1B1B]"}>{money(row.currency || currency, value)}</span>
            </div>
            <div className="h-2 border border-[#E4E8E8] bg-[#F2F4F7]"><div className="h-full" style={{ width: `${pct}%`, background: danger ? "#F04438" : "#006B6B" }} /></div>
          </div>
        );
      })}
    </div>
  );
}

function ManufacturingFormModal({ open, title, subtitle, onClose, busy = false, footer, pageClass, width, children }) {
  return (
    <EnterpriseFormModal open={open} title={title} subtitle={subtitle} onClose={onClose} busy={busy} width={width}>
      <div className={`${pageClass} flex h-[78vh] min-h-[480px] max-h-[760px] flex-col`}>
        <div className="min-h-0 flex-1 overflow-auto bg-[#F7F8FA] p-3">{children}</div>
        <div className="flex shrink-0 justify-end gap-1 border-t border-[#ACB8B8] bg-[#EEF2F2] px-3 py-2">{footer}</div>
      </div>
    </EnterpriseFormModal>
  );
}

function ActionButton({ label, onClick, primary = false, disabled = false }) {
  return <button type="button" onClick={onClick} disabled={disabled} className={`h-7 border px-2 text-[11px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${primary ? "border-[#027A48] bg-[#027A48] text-white" : "border-[#AAB5B5] bg-white text-[#324443]"}`}>{label}</button>;
}
function Field({ label, children }) { return <label className="block"><div className="mb-1 text-[9px] font-semibold uppercase text-[#627474]">{label}</div>{children}</label>; }
function Metric({ label, value, ok = false, danger = false }) { return <div className={`border px-3 py-2 ${danger ? "border-[#FDA29B] bg-[#FEF3F2]" : ok ? "border-[#B7E4C7] bg-[#ECFDF3]" : "border-[#D8DFDF] bg-white"}`}><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-1 truncate text-[14px] font-semibold ${danger ? "text-[#B42318]" : ok ? "text-[#027A48]" : "text-[#004343]"}`}>{value}</div></div>; }
function Panel({ title, children }) { return <section className="border border-[#AFB9B9] bg-white p-3 text-[12px] leading-5 text-[#324443]"><div className="mb-2 text-[12px] font-semibold uppercase text-[#324443]">{title}</div>{children}</section>; }
function Th({ children }) { return <th className="border-r border-[#465857] px-2 py-1.5 text-left font-semibold">{children}</th>; }
function Td({ children, strong = false, danger = false }) { return <td className={`border-r border-[#CFD9D8] px-2 py-1.5 ${danger ? "font-semibold text-[#B42318]" : strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}>{children}</td>; }
function Empty({ colSpan, text }) { return <tr><td colSpan={colSpan} className="px-3 py-10 text-center text-[#627474]">{text}</td></tr>; }

function ManufacturingRowActionMenu({ label = "Row actions", actions = [] }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="action-menu-wrap relative inline-flex" onClick={(event) => event.stopPropagation()} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}>
      <button type="button" aria-label={label} aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((current) => !current)} className="flex h-7 w-7 items-center justify-center border border-[#AAB5B5] bg-white text-[18px] font-bold leading-none text-[#324443] hover:bg-[#F2F4F7]">⋮</button>
      {open && <div role="menu" className="absolute right-0 top-8 z-[90] min-w-[140px] border border-[#96A5A4] bg-white p-1 text-left shadow-xl">
        {actions.map((action) => <button key={action.label} type="button" role="menuitem" disabled={action.disabled} onClick={(event) => { event.stopPropagation(); setOpen(false); action.onClick?.(); }} className={`block h-8 w-full px-2 text-left text-[10px] font-semibold hover:bg-[#F2F4F7] disabled:opacity-50 ${action.danger ? "text-[#B42318]" : "text-[#324443]"}`}>{action.label}</button>)}
      </div>}
    </div>
  );
}

function MaterialTable({ lines, currency, shortageOnly = false, exploded = false }) {
  return <table className="sx-enterprise-table w-full min-w-[1260px] border-collapse bg-white text-left text-[11px]"><thead className="sticky top-0 bg-[#0E4D4D] text-white"><tr><Th>#</Th><Th>Ingredient</Th><Th>Component Role</Th><Th>Stock Type</Th><Th>UOM</Th><Th>Consume From</Th><Th>Net</Th><Th>Wastage</Th><Th>Required</Th><Th>Available</Th><Th>Shortage</Th><Th>Unit Cost</Th><Th>Required Value</Th><Th>Shortage Value</Th></tr></thead><tbody>{!lines.length ? <Empty colSpan={14} text={shortageOnly ? "No shortages found." : exploded ? "No exploded BOM requirements found." : "No material plan loaded."} /> : lines.map((line, index) => <tr key={`${line.ingredient_product_id}-${line.line_type || "ingredient"}-${index}`} className={`border-b border-[#CFD9D8] ${line.shortageQty > 0 ? "bg-[#FFF1F0]" : line.cost_missing ? "bg-[#FFFAEB]" : "bg-white"}`}><Td>{index + 1}</Td><Td strong>{line.ingredient_name}<div className="text-[9px] font-normal text-[#627474]">{line.ingredient_code}{line.cost_missing ? " • COST MISSING" : ""}</div></Td><Td>{String(line.line_type || "ingredient").replaceAll("_", " ")}</Td><Td>{String(line.ingredient_type || "-").replaceAll("_", " ")}</Td><Td>{line.uom || "EACH"}</Td><Td>{line.source_stock_area || "-"}</Td><Td>{qty(line.net_quantity)}</Td><Td>{qty(line.wastage_quantity)}{line.wastage_percent !== undefined ? ` / ${qty(line.wastage_percent)}%` : ""}</Td><Td>{qty(line.requiredQty)}</Td><Td>{qty(line.availableQty)}</Td><Td danger={line.shortageQty > 0}>{qty(line.shortageQty)}</Td><Td>{line.cost_missing ? "Missing" : money(line.currency || currency, line.unitCost)}</Td><Td>{money(line.currency || currency, line.requiredValue)}</Td><Td>{money(line.currency || currency, line.shortageValue)}</Td></tr>)}</tbody></table>;
}

function PlansTable({ plans, currency, selectedPlanId, onOpen, onDelete }) {
  return <table className="sx-enterprise-table w-full min-w-[1180px] border-collapse bg-white text-left text-[11px]"><thead className="sticky top-0 bg-[#0E4D4D] text-white"><tr><Th>#</Th><Th>Plan</Th><Th>Date</Th><Th>Planned For</Th><Th>Recipe</Th><Th>Produced Item</Th><Th>Qty</Th><Th>Areas</Th><Th>Status</Th><Th>Short Lines</Th><Th>Short Value</Th><Th>Action</Th></tr></thead><tbody>{!plans.length ? <Empty colSpan={12} text="No saved production plans found." /> : plans.map((plan, index) => <tr key={plan.id || index} className={`border-b border-[#CFD9D8] ${String(selectedPlanId) === String(plan.id) ? "bg-[#E9F7EF] outline outline-2 outline-[#31B44B] outline-offset-[-2px]" : "bg-white hover:bg-[#F7FBF8]"}`}><Td>{index + 1}</Td><Td strong>{plan.plan_number || plan.id}</Td><Td>{plan.created_at || "-"}</Td><Td>{plan.planned_for || "-"}</Td><Td>{plan.recipe_name || plan.recipe?.name || "-"}</Td><Td>{plan.produced_product_name || plan.produced_product?.name || plan.producedProduct?.name || "-"}</Td><Td>{qty(plan.planned_quantity)}</Td><Td>{`${plan.source_stock_area || "-"} -> ${plan.destination_stock_area || "-"}`}</Td><Td>{plan.status || "-"}</Td><Td danger={Number(plan.shortage_line_count || 0) > 0}>{plan.shortage_line_count || 0}</Td><Td>{money(plan.currency || currency, plan.shortage_value)}</Td><Td><ManufacturingRowActionMenu label={`Actions for ${plan.plan_number || plan.id}`} actions={[{ label: "Edit", onClick: () => onOpen(plan) }, { label: "Delete", danger: true, onClick: () => onDelete(plan) }]} /></Td></tr>)}</tbody></table>;
}

function Styles() {
  return <style>{`.food-plan-page button,.food-plan-page input,.food-plan-page select,.food-plan-page textarea{border-radius:0!important}.plan-input{height:2rem;width:100%;border:1px solid #AAB5B5;background:#fff;padding:0 .5rem;font-size:11px;outline:none}.plan-input:focus{border-color:#004343;box-shadow:inset 0 0 0 1px #004343}`}</style>;
}

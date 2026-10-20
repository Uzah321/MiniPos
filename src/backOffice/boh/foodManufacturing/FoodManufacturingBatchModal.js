import React, { useEffect, useMemo, useState } from "react";
import { CheckCircle2, RefreshCw, Save, Search } from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getProducts } from "../../../services/ProductService";
import {
  getFoodRecipes,
  getFoodManufacturingBatches,
  createFoodManufacturingBatch,
  getFoodManufacturingProductionPlan,
  saveFoodManufacturingProductionPlan,
  buildFoodManufacturingBatchPayload,
  buildFoodManufacturingPlanPayload,
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
const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

const getRecipeName = (recipe) => recipe?.name || recipe?.recipe?.name || recipe?.recipe_name || "-";
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

export default function FoodManufacturingBatchModal({ open, onClose, currentUser = null }) {
  const defaultBranchId = getBranchId(currentUser);
  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);
  const [batches, setBatches] = useState([]);
  const [branches, setBranches] = useState([]);
  const [search, setSearch] = useState("");
  const [selectedRecipeId, setSelectedRecipeId] = useState("");
  const [producedProductId, setProducedProductId] = useState("");
  const [branchId, setBranchId] = useState(defaultBranchId);
  const [plannedYield, setPlannedYield] = useState("");
  const [actualYield, setActualYield] = useState("");
  const [actualWasEdited, setActualWasEdited] = useState(false);
  const [sourceStockArea, setSourceStockArea] = useState("back");
  const [destinationStockArea, setDestinationStockArea] = useState("back");
  const [currency, setCurrency] = useState("USD");
  const [notes, setNotes] = useState("");
  const [selectedBatchId, setSelectedBatchId] = useState(null);
  const [savedPlanId, setSavedPlanId] = useState(null);
  const [materialPlan, setMaterialPlan] = useState(null);
  const [planningLoading, setPlanningLoading] = useState(false);
  const [loading, setLoading] = useState(false);
  const [productionEditorOpen, setProductionEditorOpen] = useState(false);
  const [messageModal, setMessageModal] = useState({ open: false, type: "success", title: "", message: "" });

  const showMessage = ({ type = "success", title, message }) =>
    setMessageModal({ open: true, type, title, message });

  const selectedRecipe = useMemo(
    () => recipes.find((r) => String(r.id) === String(selectedRecipeId)) || null,
    [recipes, selectedRecipeId]
  );

  const selectedBatch = useMemo(
    () => batches.find((batch) => String(batch.id) === String(selectedBatchId)) || null,
    [batches, selectedBatchId]
  );

  const filteredBatches = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return batches;
    return batches.filter(
      (b) =>
        String(b.batch_number || b.id || "").toLowerCase().includes(q) ||
        String(getRecipeName(b.recipe || b.food_recipe || b)).toLowerCase().includes(q) ||
        String(b.produced_product?.name || b.producedProduct?.name || "").toLowerCase().includes(q)
    );
  }, [batches, search]);

  const materialLines = useMemo(() => {
    const lines = Array.isArray(materialPlan?.lines) ? materialPlan.lines : [];
    return lines.map((row) => ({
      ...row,
      requiredQty: Number(row.required_quantity || 0),
      availableQty: Number(row.available_quantity || 0),
      shortageQty: Number(row.shortage_quantity || 0),
      unitCost: Number(row.unit_cost || 0),
      shortageValue: Number(row.shortage_value || 0),
    }));
  }, [materialPlan]);

  const planTotals = useMemo(() => {
    const summary = materialPlan?.summary || {};
    const shortageLines =
      summary.shortage_line_count !== undefined
        ? Number(summary.shortage_line_count || 0)
        : materialLines.filter((row) => row.shortageQty > 0.000001).length;
    const shortageValue =
      summary.shortage_value !== undefined
        ? Number(summary.shortage_value || 0)
        : materialLines.reduce((sum, row) => sum + Number(row.shortageValue || 0), 0);

    return {
      requiredLines: materialLines.length,
      shortageLines,
      shortageValue,
      ready: materialLines.length > 0 && shortageLines === 0,
    };
  }, [materialPlan, materialLines]);

  const totals = useMemo(
    () => ({
      batches: batches.length,
      yield: batches.reduce((sum, b) => sum + Number(b.actual_yield_quantity || b.yield_quantity || 0), 0),
      cost: batches.reduce((sum, b) => sum + Number(b.total_cost || b.batch_cost || 0), 0),
    }),
    [batches]
  );

  useEffect(() => {
    if (open) loadAll();
  }, [open]);

  useEffect(() => {
    if (!open || !productionEditorOpen) return;
    if (!selectedRecipeId || !branchId || !Number(plannedYield || actualYield || 0)) {
      setMaterialPlan(null);
      return;
    }

    const timer = setTimeout(() => loadMaterialPlan(), 250);
    return () => clearTimeout(timer);
  }, [open, productionEditorOpen, selectedRecipeId, branchId, producedProductId, plannedYield, actualYield, sourceStockArea, destinationStockArea]);

  const loadAll = async () => {
    try {
      setLoading(true);

      const [manufacturingRes, prepRes, batchRes, companyRes, productRes] = await Promise.all([
        getFoodRecipes({ per_page: 500, recipe_type: "manufacturing", with_ingredients: true }),
        getFoodRecipes({ per_page: 500, recipe_type: "prep", with_ingredients: true }),
        getFoodManufacturingBatches({ per_page: 150 }),
        getAuthorisedCompany(),
        getProducts({
          per_page: 500,
          product_types: "semi_finished",
          is_active: true,
        }),
      ]);

      const mergedRecipes = [...normalizeRows(manufacturingRes), ...normalizeRows(prepRes)]
        .filter((recipe) => ["manufacturing", "prep"].includes(String(recipe?.recipe_type || "").toLowerCase()))
        .filter((recipe, index, list) => list.findIndex((item) => String(item.id) === String(recipe.id)) === index);

      setRecipes(mergedRecipes);
      setProducts(normalizeRows(productRes).filter((product) => String(product.product_type || "").toLowerCase() === "semi_finished"));
      setBatches(normalizeRows(batchRes));

      const company = companyRes?.company || companyRes?.data?.company || null;
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

  const loadMaterialPlan = async () => {
    if (!selectedRecipeId || !branchId) return;

    try {
      setPlanningLoading(true);
      const res = await getFoodManufacturingProductionPlan({
        branch_id: branchId,
        recipe_id: selectedRecipeId,
        produced_product_id: producedProductId || undefined,
        source_stock_area: sourceStockArea,
        destination_stock_area: destinationStockArea,
        planned_quantity: Number(plannedYield || actualYield || 0),
      });
      setMaterialPlan(unwrapData(res));
    } catch (error) {
      setMaterialPlan(null);
      showMessage({ type: "error", title: "Planning Failed", message: getErrorMessage(error) });
    } finally {
      setPlanningLoading(false);
    }
  };

  const selectRecipe = (recipeId) => {
    setSelectedRecipeId(recipeId);
    const recipe = recipes.find((x) => String(x.id) === String(recipeId));
    const outputProductId = getRecipeOutputProductId(recipe);
    const recipeQty = recipe?.yield_quantity ? String(recipe.yield_quantity) : "";

    setProducedProductId(outputProductId || "");
    setCurrency(recipe?.currency || "USD");
    setPlannedYield(recipeQty);
    setActualYield(recipeQty);
    setActualWasEdited(false);
    setSourceStockArea(recipe?.source_stock_area || "back");
    setDestinationStockArea(recipe?.destination_stock_area || "back");
    setSavedPlanId(null);
    setMaterialPlan(null);
  };

  const handlePlannedYieldChange = (value) => {
    setPlannedYield(value);
    setSavedPlanId(null);
    if (!actualWasEdited || !Number(actualYield || 0)) {
      setActualYield(value);
    }
  };

  const handleActualYieldChange = (value) => {
    setActualYield(value);
    setActualWasEdited(true);
  };

  const openNewProduction = () => {
    setSelectedRecipeId("");
    setProducedProductId("");
    setBranchId(defaultBranchId);
    setPlannedYield("");
    setActualYield("");
    setActualWasEdited(false);
    setSourceStockArea("back");
    setDestinationStockArea("back");
    setCurrency("USD");
    setNotes("");
    setSavedPlanId(null);
    setMaterialPlan(null);
    setProductionEditorOpen(true);
  };

  const savePlan = async () => {
    if (!selectedRecipeId) return showMessage({ type: "error", title: "Validation", message: "Select production recipe." });
    if (!producedProductId) return showMessage({ type: "error", title: "Validation", message: "Select the stock item to be produced." });
    if (!branchId) return showMessage({ type: "error", title: "Validation", message: "Select branch." });
    if (!Number(plannedYield || 0)) return showMessage({ type: "error", title: "Validation", message: "Planned quantity must be greater than zero." });

    try {
      setLoading(true);
      const payload = buildFoodManufacturingPlanPayload({
        recipe_id: selectedRecipeId,
        branch_id: branchId,
        produced_product_id: producedProductId,
        source_stock_area: sourceStockArea,
        destination_stock_area: destinationStockArea,
        planned_quantity: plannedYield,
        notes,
      });
      const res = await saveFoodManufacturingProductionPlan(payload);
      const saved = unwrapData(res);
      setSavedPlanId(saved?.id || null);
      showMessage({ title: "Plan Saved", message: res?.message || "Production plan saved for reporting." });
      await loadMaterialPlan();
    } catch (error) {
      showMessage({ type: "error", title: "Save Plan Failed", message: getErrorMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const recordBatch = async () => {
    if (!selectedRecipeId) return showMessage({ type: "error", title: "Validation", message: "Select production recipe." });
    if (!producedProductId) return showMessage({ type: "error", title: "Validation", message: "Select the stock item to be produced." });
    if (!branchId) return showMessage({ type: "error", title: "Validation", message: "Select branch." });

    const plannedQty = Number(plannedYield || 0);
    const actualQty = Number(actualYield || plannedYield || 0);

    if (!actualQty) return showMessage({ type: "error", title: "Validation", message: "Actual production quantity must be greater than zero." });
    if (planTotals.shortageLines > 0) {
      return showMessage({
        type: "error",
        title: "Material Shortage",
        message: `${planTotals.shortageLines} ingredient line(s) do not have enough ${sourceStockArea.toUpperCase()} stock. Receive or transfer stock before recording production.`,
      });
    }

    try {
      setLoading(true);
      const payload = buildFoodManufacturingBatchPayload({
        recipe_id: selectedRecipeId,
        branch_id: branchId,
        produced_product_id: producedProductId,
        source_stock_area: sourceStockArea,
        destination_stock_area: destinationStockArea,
        planned_yield_quantity: plannedQty || actualQty,
        actual_yield_quantity: actualQty,
        currency,
        notes,
        plan_id: savedPlanId,
      });
      const res = await createFoodManufacturingBatch(payload);
      showMessage({ title: "Production Recorded", message: res?.message || "Production batch recorded and stock updated." });
      setActualYield("");
      setPlannedYield("");
      setActualWasEdited(false);
      setSavedPlanId(null);
      setNotes("");
      setMaterialPlan(null);
      await loadAll();
      const saved = unwrapData(res);
      if (saved?.id) setSelectedBatchId(saved.id);
      setProductionEditorOpen(false);
    } catch (error) {
      showMessage({ type: "error", title: "Record Failed", message: getErrorMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title="FOOD PRODUCTION" subtitle="Production planning, material requirements and stock output">
        <div className="food-batch-page h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
          <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
            <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
              <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
                <div className="flex items-center gap-2">
                  <div className="text-[13px] font-semibold text-[#0C1B1B]">Production workbench</div>
                  <div className="h-4 border-l border-[#AAB5B5]" />
                  <div className="text-[10px] font-medium uppercase text-[#627474]">Plan materials, save plans, deduct ingredients and increase produced stock.</div>
                </div>
                <div className="flex items-center gap-1">
                  <ActionButton onClick={openNewProduction} label="Record Production" icon={CheckCircle2} primary />
                  <ActionButton onClick={loadAll} label={loading ? "Loading" : "Refresh"} icon={RefreshCw} />
                </div>
              </div>
              <div className="grid grid-cols-[minmax(260px,1fr)_150px_170px_170px_170px_170px] gap-2 px-2 py-2">
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search production batches..." className="food-input pl-8" />
                </div>
                <SummaryBox label="Batches" value={totals.batches} />
                <SummaryBox label="Produced Qty" value={qty(totals.yield)} />
                <SummaryBox label="Recorded Cost" value={money(currency, totals.cost)} />
                <SummaryBox label="Short Lines" value={planTotals.shortageLines} danger={planTotals.shortageLines > 0} />
                <SummaryBox label="Plan Status" value={savedPlanId ? "Saved" : planTotals.ready ? "Ready" : "Check"} ok={!!savedPlanId || planTotals.ready} danger={!planTotals.ready && materialLines.length > 0} />
              </div>
            </div>

            <div className="min-h-0 flex-1 border-b border-[#AAB5B5]">
              <section className="flex h-full min-w-0 flex-col">
                <SectionTitle title="Production Register" subtitle="Recorded outputs" />
                <div className="min-h-0 flex-1 overflow-auto">
                  <table className="sx-enterprise-table w-full min-w-[920px] border-collapse text-left text-[11px]">
                    <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
                      <tr><GridTh>#</GridTh><GridTh>Batch</GridTh><GridTh>Recipe</GridTh><GridTh>Produced Item</GridTh><GridTh>Planned</GridTh><GridTh>Actual</GridTh><GridTh>Cost</GridTh><GridTh>Areas</GridTh></tr>
                    </thead>
                    <tbody>
                      {filteredBatches.length === 0 ? (
                        <tr><td colSpan={8} className="px-3 py-10 text-center text-[#627474]">No production batches found.</td></tr>
                      ) : filteredBatches.map((b, index) => (
                        <tr key={b.id} onClick={() => setSelectedBatchId(b.id)} className={`cursor-pointer border-b border-[#CFD9D8] ${selectedBatchId === b.id ? "bg-[#E9F7EF] text-[#0C1B1B] outline outline-2 outline-[#31B44B] outline-offset-[-2px]" : "bg-white text-[#324443] hover:bg-[#F2F8F2]"}`}>
                          <GridTd>{index + 1}</GridTd>
                          <GridTd strong>{b.batch_number || b.id}</GridTd>
                          <GridTd>{getRecipeName(b.recipe || b.food_recipe || b)}</GridTd>
                          <GridTd>{b.produced_product?.name || b.producedProduct?.name || getRecipeProduct(b.recipe || b)}</GridTd>
                          <GridTd>{qty(b.planned_yield_quantity || b.planned_quantity || 0)}</GridTd>
                          <GridTd>{qty(b.actual_yield_quantity || b.yield_quantity)}</GridTd>
                          <GridTd>{money(b.currency || currency, b.total_cost || b.batch_cost)}</GridTd>
                          <GridTd>{`${b.source_stock_area || "back"} -> ${b.destination_stock_area || "back"}`}</GridTd>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            </div>

            <div className="shrink-0 overflow-auto bg-[#F7F8FA] p-2">
              <div className="grid gap-2 xl:grid-cols-3">
                <Panel title="Selected Batch" subtitle="Read-only register detail">
                  <p>{selectedBatch ? `${selectedBatch.batch_number || selectedBatch.id} · ${getRecipeName(selectedBatch.recipe || selectedBatch.food_recipe || selectedBatch)}` : "Select a recorded batch in the register to inspect it."}</p>
                </Panel>
                <Panel title="Recorded Output" subtitle="Stock increase">
                  <p>{selectedBatch ? `${qty(selectedBatch.actual_yield_quantity || selectedBatch.yield_quantity)} of ${selectedBatch.produced_product?.name || selectedBatch.producedProduct?.name || getRecipeProduct(selectedBatch.recipe || selectedBatch)}` : "Production output is displayed after selecting a batch."}</p>
                </Panel>
                <Panel title="Record Production" subtitle="Modal-only data entry">
                  <p>Use Record Production to calculate materials, save the plan and record output in a centered dialog. The register never doubles as an entry form.</p>
                </Panel>
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>

      <ManufacturingFormModal
        open={open && productionEditorOpen}
        title="Record Food Production"
        subtitle="Calculate requirements before committing stock movement"
        onClose={() => setProductionEditorOpen(false)}
        busy={loading || planningLoading}
        pageClass="food-batch-page"
        width="1180px"
        footer={(
          <>
            <ActionButton onClick={() => setProductionEditorOpen(false)} label="Cancel" disabled={loading || planningLoading} />
            <ActionButton onClick={savePlan} label={loading ? "Saving" : "Save Plan"} icon={Save} disabled={loading || planningLoading} />
            <ActionButton onClick={recordBatch} label={loading ? "Recording" : "Record Production"} icon={CheckCircle2} primary disabled={loading || planningLoading} />
          </>
        )}
      >
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <BomField label="Recipe">
            <select value={selectedRecipeId} onChange={(e) => selectRecipe(e.target.value)} className="food-input">
              <option value="">Select Recipe</option>
              {recipes.map((recipe) => {
                const recipeName = recipe.name || recipe.recipe_name || "Unnamed Recipe";
                return <option key={recipe.id} value={recipe.id}>{recipeName} - {getRecipeProduct(recipe)}</option>;
              })}
            </select>
          </BomField>
          <BomField label="Produced Stock Item">
            <select value={producedProductId} onChange={(e) => { setProducedProductId(e.target.value); setSavedPlanId(null); }} className="food-input">
              <option value="">Select Item To Produce</option>
              {products.map((product) => (
                <option key={product.id} value={product.id}>{getProductName(product)} - {getProductCode(product)} - {getProductType(product)}</option>
              ))}
            </select>
          </BomField>
          <BomField label="Branch">
            <select value={branchId} onChange={(e) => { setBranchId(e.target.value); setSavedPlanId(null); }} className="food-input">
              <option value="">Select Branch</option>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
            </select>
          </BomField>
          <BomField label="Consume From">
            <select value={sourceStockArea} onChange={(e) => { setSourceStockArea(e.target.value); setSavedPlanId(null); }} className="food-input"><option value="back">Back Stock</option><option value="front">Front Stock</option></select>
          </BomField>
          <BomField label="Add Output To">
            <select value={destinationStockArea} onChange={(e) => { setDestinationStockArea(e.target.value); setSavedPlanId(null); }} className="food-input"><option value="back">Back Stock</option><option value="front">Front Stock</option></select>
          </BomField>
          <BomField label="Planned Quantity">
            <input type="number" step="0.0001" value={plannedYield} onChange={(e) => handlePlannedYieldChange(e.target.value)} className="food-input" />
          </BomField>
          <BomField label="Actual Quantity Produced">
            <input type="number" step="0.0001" value={actualYield} onChange={(e) => handleActualYieldChange(e.target.value)} className="food-input" />
          </BomField>
          <BomField label="Recipe Yield">
            <input value={qty(selectedRecipe?.yield_quantity || 0)} disabled readOnly className="food-input bg-[#EEF2F2]" />
          </BomField>
        </div>

        <div className={`mt-3 border px-2 py-2 text-[11px] font-semibold ${planTotals.shortageLines > 0 ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : "border-[#B7E4C7] bg-[#ECFDF3] text-[#027A48]"}`}>
          {planningLoading
            ? "Calculating material requirements from selected stock area..."
            : materialLines.length === 0
            ? "Select a recipe, produced item, branch and planned quantity to calculate material requirements."
            : planTotals.shortageLines > 0
            ? `${planTotals.shortageLines} shortage line(s). Missing value: ${money(currency, planTotals.shortageValue)}.`
            : savedPlanId
            ? "Materials are available and this production plan is saved."
            : "Materials are available for the planned production quantity. Save the plan or record production."}
        </div>

        <div className="mt-3 max-h-[38vh] overflow-auto border border-[#AAB5B5] bg-white">
          <table className="sx-enterprise-table w-full min-w-[980px] border-collapse text-left text-[11px]">
            <thead className="sticky top-0 bg-[#0E4D4D] text-white">
              <tr><GridTh>Ingredient</GridTh><GridTh>UOM</GridTh><GridTh>Consume From</GridTh><GridTh>Required</GridTh><GridTh>Available</GridTh><GridTh>Shortage</GridTh><GridTh>Unit Cost</GridTh><GridTh>Shortage Value</GridTh></tr>
            </thead>
            <tbody>
              {materialLines.length === 0 ? <tr><td colSpan={8} className="px-3 py-8 text-center text-[#627474]">No plan loaded.</td></tr> : materialLines.map((row) => (
                <tr key={row.ingredient_product_id} className={`border-b border-[#CFD9D8] ${row.shortageQty > 0 ? "bg-[#FFF1F0]" : "bg-white"}`}>
                  <GridTd strong>{row.ingredient_name}<div className="text-[9px] font-normal text-[#627474]">{row.ingredient_code}</div></GridTd>
                  <GridTd>{row.uom || "EACH"}</GridTd>
                  <GridTd>{row.source_stock_area || sourceStockArea}</GridTd>
                  <GridTd>{qty(row.requiredQty)}</GridTd>
                  <GridTd>{qty(row.availableQty)}</GridTd>
                  <GridTd strong={row.shortageQty > 0}>{qty(row.shortageQty)}</GridTd>
                  <GridTd>{money(row.currency || currency, row.unitCost)}</GridTd>
                  <GridTd>{money(row.currency || currency, row.shortageValue)}</GridTd>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <label className="mt-3 block">
          <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Production Notes</div>
          <textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="min-h-[64px] w-full border border-[#AAB5B5] p-2 text-[11px] outline-none" />
        </label>
      </ManufacturingFormModal>

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={() => setMessageModal((p) => ({ ...p, open: false }))} />
      <Styles />
    </>
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

function ActionButton({ label, icon: Icon, onClick, primary = false, disabled = false }) {
  return <button type="button" onClick={onClick} disabled={disabled} className={`inline-flex h-7 items-center gap-1 border px-2 text-[11px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${primary ? "border-[#027A48] bg-[#027A48] text-white" : "border-[#AAB5B5] bg-white text-[#324443]"}`}>{Icon && <Icon className="h-3.5 w-3.5" />}{label}</button>;
}
function SummaryBox({ label, value, danger = false, ok = false }) {
  return <div className={`border px-3 py-2 ${danger ? "border-[#FDA29B] bg-[#FEF3F2]" : ok ? "border-[#B7E4C7] bg-[#ECFDF3]" : "border-[#D8DFDF] bg-white"}`}><div className="text-[10px] font-semibold uppercase text-[#627474]">{label}</div><div className={`mt-1 truncate text-[14px] font-semibold ${danger ? "text-[#B42318]" : ok ? "text-[#027A48]" : "text-[#004343]"}`}>{value}</div></div>;
}
function SectionTitle({ title, subtitle }) { return <div className="flex h-7 items-center justify-between border-b border-[#AAB5B5] bg-[#EEF2F2] px-2"><div className="text-[11px] font-semibold uppercase text-[#324443]">{title}</div><div className="text-[10px] font-medium uppercase text-[#627474]">{subtitle}</div></div>; }
function BomField({ label, children }) { return <label className="block"><div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>{children}</label>; }
function GridTh({ children }) { return <th className="border-r border-[#465857] px-2 py-1.5 text-left font-semibold">{children}</th>; }
function GridTd({ children, strong = false }) { return <td className={`border-r border-[#CFD9D8] px-2 py-1.5 ${strong ? "font-semibold" : ""}`}>{children}</td>; }
function Panel({ title, subtitle, children }) { return <section className="border border-[#AFB9B9] bg-white p-3 text-[12px] leading-5 text-[#324443]"><div className="mb-2"><div className="text-[12px] font-semibold uppercase text-[#324443]">{title}</div><div className="text-[10px] text-[#627474]">{subtitle}</div></div>{children}</section>; }
function Styles() { return <style>{`.food-batch-page button,.food-batch-page input,.food-batch-page select,.food-batch-page textarea{border-radius:0!important}.food-input{height:2rem;width:100%;border:1px solid #AAB5B5;background:#fff;padding:0 .5rem;font-size:11px;outline:none}.food-input:focus{border-color:#004343;box-shadow:inset 0 0 0 1px #004343}`}</style>; }

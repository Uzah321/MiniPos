// src/backOffice/boh/menuItems/MenuItemRecipeCostReportModal.jsx

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Download, RefreshCw, Search, CheckSquare, Square, MoreVertical } from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, { useHospitalityReportLookups } from "../../../components/HospitalityReportScopePanel";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import HospitalityReportNavigator, {
  HOSPITALITY_REPORT_KEYS,
} from "../../../components/HospitalityReportNavigator";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { getProducts } from "../../../services/ProductService";
import {
  getFoodRecipes,
  getFoodRecipe,
  getFoodRecipeCost,
  downloadMenuItemRecipeCostReport,
} from "../../../services/FoodManufacturingService";

const MENU_TYPES = ["finished_good"];

const getBranchId = (user) =>
  user?.selectedBranchId ||
  user?.selected_branch_id ||
  user?.selectedBranch?.id ||
  user?.branch?.id ||
  user?.branch_id ||
  user?.branchId ||
  "";

const normalizeRows = (response) => {
  const payload = response?.data ?? response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

const payloadData = (response) => response?.data?.data || response?.data || response;

const getProductName = (product) => product?.name || product?.product_name || "-";
const getProductCode = (product) => product?.code || product?.sku || product?.barcode || "-";
const getBranchName = (branch) =>
  branch?.branch_name || branch?.name || branch?.display_name || branch?.code || "Selected branch";
const money = (currency, value) => `${currency || "USD"} ${Number(value || 0).toFixed(4)}`;
const qty = (value) => Number(value || 0).toFixed(4);

const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

const getRecipeMenuProductId = (recipe) =>
  recipe?.menu_product_id || recipe?.menuProduct?.id || recipe?.menu_product?.id || recipe?.product?.id || "";

const recipeRows = (recipe) => recipe?.ingredients || recipe?.recipe_ingredients || recipe?.items || [];

const getIngredientName = (line) => {
  const p = line?.ingredient_product || line?.product || line?.material || null;
  return p?.name || line?.ingredient_name || line?.product_name || line?.metadata?.product_name || "-";
};

const getIngredientCode = (line) => {
  const p = line?.ingredient_product || line?.product || line?.material || null;
  return p?.code || p?.sku || line?.product_code || line?.metadata?.product_code || "-";
};

const getIngredientUom = (line) => {
  const product = line?.ingredient_product || line?.product || line?.material || null;
  const uom =
    line?.backend_uom ||
    line?.uom?.code ||
    line?.uom?.name ||
    line?.uom_code ||
    line?.uom_name ||
    line?.unit_code ||
    line?.unit_name ||
    line?.metadata?.unit ||
    line?.metadata?.uom ||
    line?.unit ||
    product?.uom?.code ||
    product?.uom?.name ||
    product?.base_uom?.code ||
    product?.base_uom?.name ||
    product?.unit?.code ||
    product?.unit?.name ||
    product?.uom_code ||
    product?.unit_code ||
    "-";

  return uom;
};

const getLineQuantity = (line) => Number(line?.quantity ?? line?.quantity_required ?? line?.net_quantity ?? 0);
const getLineWaste = (line) => Number(line?.wastage_percent ?? 0);
const getLineUnitCost = (line) =>
  Number(
    line?.unit_cost ??
      line?.metadata?.unit_cost ??
      line?.cost?.average_cost ??
      line?.ingredient_product?.costs?.[0]?.average_cost ??
      line?.ingredient_product?.average_cost ??
      0,
  );

const getLineCurrency = (line, fallback = "USD") =>
  line?.currency || line?.metadata?.currency || line?.ingredient_product?.costs?.[0]?.currency || fallback || "USD";

const mergeRecipeWithCosting = (recipe, costing) => {
  if (!recipe || !costing || !Array.isArray(costing.lines)) return recipe;

  const costByProduct = new Map(
    costing.lines.map((line) => [String(line.ingredient_product_id || line.product_id || ""), line]),
  );

  const mergedRows = recipeRows(recipe).map((line) => {
    const id = String(line.ingredient_product_id || line.product_id || line.ingredient_product?.id || line.product?.id || "");
    const costLine = costByProduct.get(id);
    if (!costLine) return line;

    return {
      ...line,
      unit_cost: Number(costLine.unit_cost ?? getLineUnitCost(line)),
      currency: costLine.currency || getLineCurrency(line, costing.currency),
      backend_uom: costLine.uom || costLine.uom_code || costLine.unit || getIngredientUom(line),
      unit: costLine.uom || costLine.uom_code || costLine.unit || line.unit,
      backend_line_cost: Number(costLine.line_cost ?? 0),
      backend_gross_quantity: Number(costLine.gross_quantity ?? 0),
      backend_net_cost: Number(costLine.net_cost ?? 0),
      backend_wastage_cost: Number(costLine.wastage_cost ?? 0),
    };
  });

  return {
    ...recipe,
    ingredients: mergedRows,
    recipe_ingredients: mergedRows,
  };
};

const calculateRecipeTotals = (recipe, costing = null) => {
  if (costing) {
    return {
      currency: costing.currency || recipe?.currency || "USD",
      ingredientCost: Number(costing.ingredient_cost || 0),
      packagingCost: Number(costing.packaging_cost || 0),
      labourCost: Number(costing.labour_cost || 0),
      overheadCost: Number(costing.overhead_cost || 0),
      totalCost: Number(costing.total_cost || 0),
      costPerUnit: Number(costing.cost_per_unit || 0),
      yieldQuantity: Number(costing.yield_quantity || recipe?.yield_quantity || 1),
    };
  }

  const currency = recipe?.currency || "USD";
  const ingredientCost = recipeRows(recipe).reduce((sum, line) => {
    const q = getLineQuantity(line);
    const waste = getLineWaste(line);
    const gross = q + q * (waste / 100);
    const lineCost = Number(line?.backend_line_cost ?? gross * getLineUnitCost(line));
    return sum + lineCost;
  }, 0);

  const packagingCost = Number(recipe?.packaging_cost || 0);
  const labourCost = Number(recipe?.labour_cost || 0);
  const overheadCost = Number(recipe?.overhead_cost || 0);
  const totalCost = ingredientCost + packagingCost + labourCost + overheadCost;
  const yieldQuantity = Math.max(Number(recipe?.yield_quantity || 1), 1);

  return {
    currency,
    ingredientCost,
    packagingCost,
    labourCost,
    overheadCost,
    totalCost,
    costPerUnit: totalCost / yieldQuantity,
    yieldQuantity,
  };
};

export default function MenuItemRecipeCostReportModal({ open, onClose, currentUser = null, onNavigateReport }) {
  const branchId = getBranchId(currentUser);
  const today = new Date().toISOString().slice(0, 10);
  const [scopeFilters, setScopeFilters] = useState({ date_from: today, date_to: today, branch_id: branchId, till_id: "", cashier_id: "", shift_session_id: "", currency_codes: [], business_mode: "fast_food_boh" });
  const [menuItems, setMenuItems] = useState([]);
  const [recipes, setRecipes] = useState([]);
  const [recipeDetails, setRecipeDetails] = useState({});
  const [costings, setCostings] = useState({});
  const [selectedIds, setSelectedIds] = useState([]);
  const [activeMenuId, setActiveMenuId] = useState(null);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(false);
  const [recipeLoadingKey, setRecipeLoadingKey] = useState("");
  const [exportingFormat, setExportingFormat] = useState("");
  const [generated, setGenerated] = useState(false);
  const [outputChooserOpen, setOutputChooserOpen] = useState(false);
  const [outputChooserContext, setOutputChooserContext] = useState("generate");
  const [appliedScopeFilters, setAppliedScopeFilters] = useState(null);
  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });
  const hydrationRequestRef = useRef(0);
  const lookups = useHospitalityReportLookups(open, scopeFilters);

  const showMessage = ({ type = "success", title, message }) =>
    setMessageModal({ open: true, type, title, message });

  const recipeByMenuItemId = useMemo(() => {
    const map = {};
    recipes.forEach((recipe) => {
      const productId = getRecipeMenuProductId(recipe);
      if (!productId) return;
      if (!map[productId]) map[productId] = recipe;
    });
    return map;
  }, [recipes]);

  const filteredMenuItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    const rows = menuItems.filter((item) => MENU_TYPES.includes(item.product_type));
    if (!q) return rows;
    return rows.filter(
      (item) =>
        String(getProductName(item)).toLowerCase().includes(q) ||
        String(getProductCode(item)).toLowerCase().includes(q) ||
        String(item.product_type || "").toLowerCase().includes(q),
    );
  }, [menuItems, search]);

  const activeMenuItem = useMemo(() => {
    return menuItems.find((item) => String(item.id) === String(activeMenuId)) || null;
  }, [menuItems, activeMenuId]);

  const activeBaseRecipe = activeMenuItem ? recipeByMenuItemId[activeMenuItem.id] : null;
  const costingBranchId =
    (generated ? appliedScopeFilters?.branch_id : scopeFilters.branch_id) ||
    branchId ||
    "company";
  const activeCostingKey = activeBaseRecipe?.id
    ? `${costingBranchId}:${activeBaseRecipe.id}`
    : "";
  const activeRecipeDetail = activeBaseRecipe
    ? recipeDetails[activeBaseRecipe.id] || activeBaseRecipe
    : null;
  const activeCosting = activeCostingKey ? costings[activeCostingKey] || null : null;
  const activeRecipe = activeRecipeDetail
    ? mergeRecipeWithCosting(activeRecipeDetail, activeCosting)
    : null;
  const activeTotals = calculateRecipeTotals(activeRecipe, activeCosting);
  const reportBranchName = useMemo(() => {
    const selectedBranchId = appliedScopeFilters?.branch_id || branchId || "";
    if (!selectedBranchId) return "All accessible branches";
    return getBranchName(lookups?.branchById?.[selectedBranchId]);
  }, [appliedScopeFilters?.branch_id, branchId, lookups?.branchById]);

  useEffect(() => {
    if (!open) return;
    setMenuItems([]);
    setRecipes([]);
    setRecipeDetails({});
    setCostings({});
    setSelectedIds([]);
    setActiveMenuId(null);
    setRecipeLoadingKey("");
    hydrationRequestRef.current += 1;
    setGenerated(false);
    setAppliedScopeFilters(null);
    setOutputChooserOpen(false);
  }, [open]);

  const loadAll = async (nextScope = scopeFilters) => {
    const selectedBranchId = nextScope.branch_id || branchId;
    try {
      setLoading(true);
      setGenerated(false);
      const [productsRes, recipesRes] = await Promise.all([
        getProducts({
          q: "",
          search: "",
          product_types: MENU_TYPES.join(","),
          branch_id: selectedBranchId || undefined,
          branch_only: true,
          is_active: true,
          per_page: 500,
        }),
        getFoodRecipes({
          recipe_type: "menu",
          branch_id: selectedBranchId || undefined,
          per_page: 500,
        }),
      ]);

      const products = normalizeRows(productsRes).filter((item) => MENU_TYPES.includes(item.product_type));
      const recipeRows = normalizeRows(recipesRes);
      setMenuItems(products);
      setRecipes(recipeRows);
      setRecipeDetails({});
      setCostings({});
      setSelectedIds([]);
      setActiveMenuId(products?.[0]?.id || null);
      setAppliedScopeFilters({ ...nextScope, branch_id: selectedBranchId || "" });
      setGenerated(true);
      return true;
    } catch (error) {
      setGenerated(false);
      setAppliedScopeFilters(null);
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getErrorMessage(error),
      });
      return false;
    } finally {
      setLoading(false);
    }
  };

  const hydrateRecipe = useCallback(async (recipeId, selectedBranchId) => {
    if (!recipeId) return null;
    const requestId = ++hydrationRequestRef.current;
    const costKey = `${selectedBranchId || "company"}:${recipeId}`;
    setRecipeLoadingKey(costKey);

    try {
      const [detailRes, costRes] = await Promise.all([
        getFoodRecipe(recipeId),
        getFoodRecipeCost(recipeId, { branch_id: selectedBranchId || undefined }),
      ]);

      if (requestId !== hydrationRequestRef.current) return null;

      const detail = payloadData(detailRes);
      const costing = payloadData(costRes);

      setRecipeDetails((prev) => ({ ...prev, [recipeId]: detail }));
      setCostings((prev) => ({ ...prev, [costKey]: costing }));
      return { recipe: mergeRecipeWithCosting(detail, costing), costing };
    } catch (error) {
      if (requestId === hydrationRequestRef.current) {
        setMessageModal({
          open: true,
          type: "error",
          title: "Recipe Cost Failed",
          message: getErrorMessage(error),
        });
      }
      return null;
    } finally {
      if (requestId === hydrationRequestRef.current) setRecipeLoadingKey("");
    }
  }, []);

  useEffect(() => {
    if (!activeBaseRecipe?.id || !generated) return;
    hydrateRecipe(
      activeBaseRecipe.id,
      appliedScopeFilters?.branch_id || branchId || "",
    );
  }, [activeBaseRecipe?.id, appliedScopeFilters?.branch_id, branchId, generated, hydrateRecipe]);

  const toggleSelect = (menuId) => {
    setSelectedIds((prev) => {
      const id = String(menuId);
      if (prev.map(String).includes(id)) return prev.filter((item) => String(item) !== id);
      return [...prev, menuId];
    });
  };

  const selectAllVisible = () => {
    const ids = filteredMenuItems.map((item) => item.id);
    setSelectedIds(ids);
    if (!activeMenuId && ids.length) setActiveMenuId(ids[0]);
  };

  const clearSelected = () => setSelectedIds([]);

  const exportMenuItemIds = async (
    menuItemIds,
    format = "xlsx",
    exportBranchId = appliedScopeFilters?.branch_id || branchId || undefined,
  ) => {
    if (!menuItemIds.length) {
      showMessage({
        type: "error",
        title: "No Menu Items Selected",
        message: "No menu items are available for this report scope.",
      });
      return false;
    }

    try {
      setExportingFormat(format);
      await downloadMenuItemRecipeCostReport(menuItemIds, format, {
        branch_id: exportBranchId,
      });

      showMessage({
        title: `${format === "pdf" ? "PDF" : "Excel"} Downloaded`,
        message: `${menuItemIds.length} menu item recipe(s) exported successfully from the backend.`,
      });
      return true;
    } catch (error) {
      showMessage({
        type: "error",
        title: "Export Failed",
        message: getErrorMessage(error),
      });
      return false;
    } finally {
      setExportingFormat("");
    }
  };

  const exportSelected = async (format = "xlsx") =>
    exportMenuItemIds(selectedIds, format);

  const openGenerateChooser = () => {
    setOutputChooserContext("generate");
    setOutputChooserOpen(true);
  };

  const openDownloadChooser = () => {
    setOutputChooserContext("download");
    setOutputChooserOpen(true);
  };

  const viewConfiguredReport = async () => {
    const viewed = await loadAll({ ...scopeFilters });
    if (viewed) setOutputChooserOpen(false);
  };

  const downloadChosenFormat = async (format) => {
    setOutputChooserOpen(false);
    await exportSelected(format);
  };

  const downloadConfiguredFormat = async (format) => {
    const selectedBranchId = scopeFilters.branch_id || branchId;
    try {
      setExportingFormat(format);
      const productsRes = await getProducts({
        q: "",
        search: "",
        product_types: MENU_TYPES.join(","),
        branch_id: selectedBranchId || undefined,
        branch_only: true,
        is_active: true,
        per_page: 500,
      });
      const menuItemIds = normalizeRows(productsRes)
        .filter((item) => MENU_TYPES.includes(item.product_type))
        .map((item) => item.id);

      if (!menuItemIds.length) {
        throw new Error("No menu items are available for the selected branch.");
      }

      await downloadMenuItemRecipeCostReport(menuItemIds, format, {
        branch_id: selectedBranchId || undefined,
      });
      setOutputChooserOpen(false);
      showMessage({
        title: `${format === "pdf" ? "PDF" : "Excel"} Downloaded`,
        message: `${menuItemIds.length} menu item recipe(s) exported successfully from the backend.`,
      });
    } catch (error) {
      setOutputChooserOpen(false);
      showMessage({
        type: "error",
        title: "Export Failed",
        message: getErrorMessage(error),
      });
    } finally {
      setExportingFormat("");
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="MENU ITEM RECIPE COST REPORT"
        subtitle="Select menu items, view linked recipes and export ingredient cost workbook"
      >
        <div className="menu-recipe-report flex h-full min-h-0 overflow-hidden bg-[#EEF1F1] text-[#1C2C2B]">
          <HospitalityReportNavigator
            section="inventory"
            activeKey={HOSPITALITY_REPORT_KEYS.MENU_RECIPE_COST}
            onNavigate={onNavigateReport}
          />
          <div className="m-2 flex min-h-0 min-w-0 flex-1 flex-col border border-[#AAB5B5] bg-white">
            {!generated ? (
              <>
              <HospitalityReportScopePanel
                filters={scopeFilters}
                setFilters={(next) => {
                  setScopeFilters(next);
                  setAppliedScopeFilters(null);
                }}
                lookups={lookups}
                loading={loading}
                onApply={openGenerateChooser}
                supported={{ date_from: false, date_to: false, till_id: false, cashier_id: false, shift_session_id: false, currency_codes: false }}
              />
              <ReportGenerationPrompt
                title="Configure Menu Recipe Cost Report"
                message="Choose the branch, then select Generate to View the report or download its backend-generated PDF or Excel file."
              />
              </>
            ) : (
            <ReportViewShell
              title="Menu Recipe Cost Report"
              subtitle={`Branch: ${reportBranchName}`}
              onBackToFilters={() => setGenerated(false)}
              busy={loading || !!exportingFormat}
              actions={(
                <>
                  <button type="button" onClick={() => loadAll(appliedScopeFilters || scopeFilters)} disabled={loading || !!exportingFormat} className="inline-flex h-8 items-center gap-1 border border-[#AAB5B5] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-60"><RefreshCw className="h-3.5 w-3.5" />{loading ? "Refreshing..." : "Refresh"}</button>
                  <button type="button" onClick={openDownloadChooser} disabled={!selectedIds.length || loading || !!exportingFormat} className="inline-flex h-8 items-center gap-1 border border-[#027A48] bg-[#027A48] px-3 text-[11px] font-semibold text-white disabled:opacity-60"><Download className="h-3.5 w-3.5" />Download</button>
                </>
              )}
            >
            <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-[#AAB5B5] bg-[#F5F7FA] p-2">
              <div className="relative min-w-[220px] flex-1 md:max-w-[420px]">
                <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                <input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search menu item, code or recipe"
                  aria-label="Search menu items"
                  className="report-input pl-8"
                />
              </div>
              <div className="mr-auto whitespace-nowrap text-[10px] font-medium text-[#627474]">
                {filteredMenuItems.length} menu item{filteredMenuItems.length === 1 ? "" : "s"}
                <span className="mx-1.5 text-[#AAB5B5]">|</span>
                <span className={selectedIds.length ? "font-semibold text-[#027A48]" : ""}>{selectedIds.length} selected</span>
              </div>
              <div className="flex items-center gap-1">
                <ActionButton onClick={selectAllVisible} label="Select visible" icon={CheckSquare} />
                <ActionButton onClick={clearSelected} label="Clear" icon={Square} disabled={!selectedIds.length} />
              </div>
            </div>

            <div className="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto border-b border-[#AAB5B5]">
              <div className="grid min-h-full min-w-0 grid-cols-1 xl:h-full xl:min-h-[440px] xl:grid-cols-[minmax(300px,36%)_minmax(0,64%)]">
              <section className="flex min-h-[260px] min-w-0 flex-col border-b border-[#AAB5B5] xl:min-h-0 xl:border-b-0 xl:border-r">
                <SectionTitle title="Menu Items" subtitle="Select for export · open for costing" />
                <div className="min-h-0 flex-1 overflow-auto">
                  <table className="sx-enterprise-table w-full table-fixed border-collapse text-left text-[11px]">
                    <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
                      <tr>
                        <GridTh className="w-11 text-center"><span className="sr-only">Select</span></GridTh>
                        <GridTh>Menu Item</GridTh>
                        <GridTh className="w-[36%]">Linked Recipe</GridTh>
                        <GridTh className="w-10 text-center"><span className="sr-only">Actions</span></GridTh>
                      </tr>
                    </thead>
                    <tbody>
                      {loading ? (
                        <tr><td colSpan={4} className="px-3 py-10 text-center text-[#627474]">Loading menu items...</td></tr>
                      ) : filteredMenuItems.length === 0 ? (
                        <tr><td colSpan={4} className="px-3 py-10 text-center text-[#627474]">No menu items found.</td></tr>
                      ) : (
                        filteredMenuItems.map((item) => {
                          const recipe = recipeByMenuItemId[item.id];
                          const selected = selectedIds.map(String).includes(String(item.id));
                          const active = String(activeMenuId) === String(item.id);
                          return (
                            <tr
                              key={item.id}
                              onClick={() => setActiveMenuId(item.id)}
                              className={`cursor-pointer border-b border-[#CFD9D8] ${active ? "bg-[#EDF6F5] text-[#004343]" : "bg-white text-[#324443] hover:bg-[#F8FAFC]"}`}
                            >
                              <GridTd className="text-center">
                                <input
                                  type="checkbox"
                                  checked={selected}
                                  onChange={() => toggleSelect(item.id)}
                                  onClick={(event) => event.stopPropagation()}
                                  aria-label={`Select ${getProductName(item)} for export`}
                                  className="h-3.5 w-3.5 accent-[#027A48]"
                                />
                              </GridTd>
                              <GridTd strong>
                                <div className="truncate">{getProductName(item)}</div>
                                <div className="mt-0.5 truncate text-[9px] font-normal text-[#627474]">{getProductCode(item)}</div>
                              </GridTd>
                              <GridTd>
                                <div className={`truncate ${recipe ? "" : "text-[#B54708]"}`}>{recipe?.name || "Not configured"}</div>
                              </GridTd>
                              <GridTd className="text-center">
                                <button
                                  type="button"
                                  onClick={(event) => {
                                    event.stopPropagation();
                                    setActiveMenuId(item.id);
                                  }}
                                  aria-label={`View recipe cost for ${getProductName(item)}`}
                                  title="View recipe cost"
                                  className="inline-flex h-7 w-7 items-center justify-center text-[#465757] hover:bg-white"
                                >
                                  <MoreVertical className="h-4 w-4" />
                                </button>
                              </GridTd>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>
              </section>

              <section className="flex min-h-[420px] min-w-0 flex-col overflow-hidden bg-[#F7F8FA] xl:min-h-0">
                <SectionTitle title="Recipe Cost Detail" subtitle="Backend average-cost valuation" />
                {!activeMenuItem ? (
                  <div className="p-4 text-[12px] text-[#627474]">Select a menu item to view its linked recipe.</div>
                ) : !activeRecipe ? (
                  <div className="p-4">
                    <div className="border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[12px] font-semibold text-[#B54708]">
                      {getProductName(activeMenuItem)} has no linked recipe/BOM yet.
                    </div>
                  </div>
                ) : recipeLoadingKey === activeCostingKey ? (
                  <div className="flex min-h-[220px] flex-1 items-center justify-center p-4 text-[12px] text-[#627474]">
                    Loading recipe ingredients and backend costing...
                  </div>
                ) : (
                  <RecipePreview
                    menuItem={activeMenuItem}
                    recipe={activeRecipe}
                    costing={activeCosting}
                    totals={activeTotals}
                  />
                )}
              </section>
              </div>
            </div>
            </ReportViewShell>
            )}
          </div>
        </div>
      </FullScreenModal>

      <ReportOutputChooser
        open={outputChooserOpen}
        reportTitle="Menu Recipe Cost Report"
        busy={loading || !!exportingFormat}
        busyLabel={loading ? "Loading recipe costs from the backend…" : "Generating backend download…"}
        onClose={() => setOutputChooserOpen(false)}
        onView={outputChooserContext === "generate" ? viewConfiguredReport : undefined}
        onDownloadPdf={outputChooserContext === "download" ? () => downloadChosenFormat("pdf") : () => downloadConfiguredFormat("pdf")}
        onDownloadExcel={outputChooserContext === "download" ? () => downloadChosenFormat("xlsx") : () => downloadConfiguredFormat("xlsx")}
        outputs={{
          view: outputChooserContext === "generate"
            ? true
            : { available: false, reason: "The report is already open." },
          pdf: outputChooserContext === "download"
            ? { available: selectedIds.length > 0, reason: selectedIds.length ? "" : "Select at least one menu item." }
            : true,
          excel: outputChooserContext === "download"
            ? { available: selectedIds.length > 0, reason: selectedIds.length ? "" : "Select at least one menu item." }
            : true,
        }}
      />

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={() => setMessageModal((prev) => ({ ...prev, open: false }))}
      />
      <MenuRecipeReportStyles />
    </>
  );
}

function RecipePreview({ menuItem, recipe, costing, totals }) {
  const lines = recipeRows(recipe);

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden">
      <div className="shrink-0 border-b border-[#CFD9D8] bg-white px-3 py-2.5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <h3 className="truncate text-[14px] font-semibold text-[#004343]">{getProductName(menuItem)}</h3>
              <span className="border border-[#CFD9D8] bg-[#F8FAFC] px-1.5 py-0.5 text-[9px] font-semibold text-[#627474]">
                {getProductCode(menuItem)}
              </span>
              {costing && (
                <span className="border border-[#ABEFC6] bg-[#ECFDF3] px-1.5 py-0.5 text-[9px] font-semibold text-[#027A48]">
                  Backend cost loaded
                </span>
              )}
            </div>
            <div className="mt-1 truncate text-[10px] text-[#627474]">
              Linked recipe: <span className="font-semibold text-[#324443]">{recipe?.name || "-"}</span>
            </div>
          </div>
          <div className="grid shrink-0 grid-cols-2 divide-x divide-[#CFD9D8] border border-[#CFD9D8] bg-[#F8FAFC]">
            <CostHeadline label="Total recipe cost" value={money(totals.currency, totals.totalCost)} />
            <CostHeadline label="Average cost / unit" value={money(totals.currency, totals.costPerUnit)} />
          </div>
        </div>
        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-[#E9EDED] pt-2">
          <div className="text-[9px] font-semibold uppercase text-[#627474]">Cost breakdown</div>
          <BreakdownValue label="Ingredients" value={money(totals.currency, totals.ingredientCost)} />
          <BreakdownValue label="Packaging" value={money(totals.currency, totals.packagingCost)} />
          <BreakdownValue label="Labour" value={money(totals.currency, totals.labourCost)} />
          <BreakdownValue label="Overhead" value={money(totals.currency, totals.overheadCost)} />
          <BreakdownValue label="Yield" value={qty(totals.yieldQuantity)} />
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="flex shrink-0 items-center justify-between border-b border-[#CFD9D8] bg-[#EEF2F2] px-3 py-1.5">
          <div className="text-[10px] font-semibold uppercase text-[#324443]">Ingredient costing</div>
          <div className="text-[9px] text-[#627474]">{lines.length} ingredient{lines.length === 1 ? "" : "s"}</div>
        </div>
        <div className="min-h-0 flex-1 overflow-x-auto overflow-y-auto">
        <table className="sx-enterprise-table w-full min-w-[720px] table-fixed border-collapse text-left text-[10px]">
          <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
            <tr>
              <GridTh className="w-[27%]">Ingredient</GridTh>
              <GridTh className="w-[10%]">UOM</GridTh>
              <GridTh className="w-[12%]" align="right">Net Qty</GridTh>
              <GridTh className="w-[11%]" align="right">Waste</GridTh>
              <GridTh className="w-[12%]" align="right">Gross Qty</GridTh>
              <GridTh className="w-[14%]" align="right">Avg Unit Cost</GridTh>
              <GridTh className="w-[14%]" align="right">Line Cost</GridTh>
            </tr>
          </thead>
          <tbody>
            {lines.length === 0 ? (
              <tr><td colSpan={7} className="px-3 py-10 text-center text-[#627474]">No ingredients found.</td></tr>
            ) : (
              lines.map((line, index) => {
                const q = getLineQuantity(line);
                const waste = getLineWaste(line);
                const gross = Number(line?.backend_gross_quantity ?? q + q * (waste / 100));
                const unitCost = getLineUnitCost(line);
                const lineCost = Number(line?.backend_line_cost ?? gross * unitCost);
                const currency = getLineCurrency(line, totals.currency);
                return (
                  <tr key={line.id || line.line_id || `${getIngredientCode(line)}-${index}`} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
                    <GridTd strong>
                      <div className="truncate">{getIngredientName(line)}</div>
                      <div className="mt-0.5 truncate text-[9px] font-normal text-[#627474]">{getIngredientCode(line)}</div>
                    </GridTd>
                    <GridTd>{getIngredientUom(line)}</GridTd>
                    <GridTd right>{qty(q)}</GridTd>
                    <GridTd right>{qty(waste)}%</GridTd>
                    <GridTd right>{qty(gross)}</GridTd>
                    <GridTd right>{money(currency, unitCost)}</GridTd>
                    <GridTd right>{money(currency, lineCost)}</GridTd>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
        </div>
      </div>
    </div>
  );
}

function ActionButton({ label, icon: Icon, onClick, primary = false, disabled = false }) {
  const cls = primary
    ? "border-[#027A48] bg-[#027A48] text-white"
    : "border-[#AAB5B5] bg-white text-[#324443]";

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex h-7 items-center gap-1 border px-2 text-[11px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${cls}`}
    >
      {Icon && <Icon className="h-3.5 w-3.5" />}
      {label}
    </button>
  );
}

function CostHeadline({ label, value }) {
  return (
    <div className="min-w-[145px] px-3 py-2">
      <div className="text-[8px] font-semibold uppercase text-[#627474]">{label}</div>
      <div className="mt-0.5 whitespace-nowrap text-[13px] font-semibold text-[#004343]">{value}</div>
    </div>
  );
}

function BreakdownValue({ label, value }) {
  return (
    <div className="whitespace-nowrap text-[9px] text-[#627474]">
      {label} <span className="ml-1 font-semibold text-[#324443]">{value}</span>
    </div>
  );
}

function SectionTitle({ title, subtitle }) {
  return (
    <div className="flex min-h-7 flex-wrap items-center justify-between gap-1 border-b border-[#AAB5B5] bg-[#EEF2F2] px-2 py-1">
      <div className="text-[11px] font-semibold uppercase text-[#324443]">{title}</div>
      <div className="text-[10px] font-medium uppercase text-[#627474]">{subtitle}</div>
    </div>
  );
}

function GridTh({ children, align = "left", className = "" }) {
  return (
    <th className={`border-r border-[#465857] px-2 py-1.5 font-semibold ${align === "right" ? "text-right" : "text-left"} ${className}`}>
      {children}
    </th>
  );
}

function GridTd({ children, right = false, strong = false, className = "" }) {
  return (
    <td className={`border-r border-[#CFD9D8] px-2 py-1.5 ${right ? "text-right" : "text-left"} ${strong ? "font-semibold" : ""} ${className}`}>
      {children}
    </td>
  );
}

function MenuRecipeReportStyles() {
  return (
    <style>{`
      .menu-recipe-report button,
      .menu-recipe-report input,
      .menu-recipe-report select,
      .menu-recipe-report textarea { border-radius: 0 !important; }
      .menu-recipe-report .report-input { height: 2rem; width: 100%; border: 1px solid #AAB5B5; background: #fff; padding: 0 .5rem; font-size: 11px; outline: none; }
      .menu-recipe-report .report-input:focus { border-color: #004343; box-shadow: inset 0 0 0 1px #004343; }
    `}</style>
  );
}

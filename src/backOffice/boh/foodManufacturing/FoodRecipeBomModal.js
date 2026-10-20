import React, { useEffect, useMemo, useState } from "react";
import { CheckCircle2, Plus, RefreshCw, Search, Trash2 } from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import NewIngredientDialog from "./NewIngredientDialog";
import { getProducts } from "../../../services/ProductService";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import {
  getFoodRecipes,
  createFoodRecipe,
  updateFoodRecipe,
  deleteFoodRecipe,
  getFoodRecipeCost,
  buildFoodRecipePayload,
} from "../../../services/FoodManufacturingService";

const normalizeRows = (response) => {
  const payload = response?.data ?? response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

const payloadData = (response) => response?.data?.data || response?.data || response;
const qty = (value) => Number(value || 0).toFixed(4);
const money = (currency, value) => `${currency || "USD"} ${Number(value || 0).toFixed(4)}`;
const getBranchId = (user) => user?.branch?.id || user?.branch_id || user?.branchId || "";
const getProductName = (product) => product?.name || product?.product_name || "-";
const getProductCode = (product) => product?.code || product?.sku || product?.barcode || "-";
const getProductUom = (product) => {
  const uom = product?.uom || product?.base_uom || product?.unit;
  if (!uom) return product?.uom_code || "-";
  return `${uom.name || "UOM"}${uom.code ? ` (${uom.code})` : ""}`;
};
const getAverageCost = (product) =>
  Number(
    product?.costs?.[0]?.average_cost ||
      product?.cost?.average_cost ||
      product?.average_cost ||
      product?.current_average_cost ||
      product?.unit_cost ||
      product?.cost_price ||
      product?.purchase_price ||
      0,
  );
const getCurrency = (product, fallback = "USD") =>
  product?.costs?.[0]?.currency ||
  product?.cost?.currency ||
  product?.currency ||
  fallback ||
  "USD";
const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

const emptyForm = (branchId = "", currency = "USD") => ({
  id: null,
  branch_id: branchId,
  menu_product_id: "",
  menu_product_name: "",
  menu_product_code: "",
  produced_product_id: null,
  name: "",
  recipe_type: "prep",
  yield_quantity: 1,
  yield_uom_id: null,
  currency,
  labour_cost: 0,
  overhead_cost: 0,
  packaging_cost: 0,
  notes: "",
  is_active: true,
});

const MATERIAL_TYPES = ["raw_material", "semi_finished", "semi_finished_good", "packaging", "consumable"];
const MENU_TYPES = ["finished_good", "menu_item", "simple", "service"];
const PRODUCTION_OUTPUT_TYPES = ["semi_finished", "semi_finished_good", "finished_good", "menu_item", "simple"];
const RECIPE_TYPES = [
  ["menu", "Menu Sale Recipe"],
  ["prep", "Modifier / Prep Recipe"],
  ["manufacturing", "Prep / Manufacturing Recipe"],
];
const COMPONENT_TYPES = [
  ["ingredient", "Ingredient"],
  ["sub_recipe", "Prepared / Sub-recipe"],
  ["packaging", "Packaging"],
  ["consumable", "Indirect Consumable"],
];

const componentTypeForProduct = (productType) => {
  const type = String(productType || "").trim().toLowerCase();
  if (type === "packaging") return "packaging";
  if (["semi_finished", "semi_finished_good"].includes(type)) return "sub_recipe";
  if (["consumable", "consumables"].includes(type)) return "consumable";
  return "ingredient";
};

const findBackendCostLine = (costLines = [], ingredientLine = {}) => {
  return (
    costLines.find(
      (item) =>
        String(item.ingredient_product_id || item.product_id || "") ===
        String(ingredientLine.ingredient_product_id || ingredientLine.product_id || ""),
    ) || null
  );
};

const mergeIngredientCostsFromCosting = (ingredientRows = [], costingData = null) => {
  const costLines = Array.isArray(costingData?.lines) ? costingData.lines : [];
  if (!costLines.length) return ingredientRows;

  return ingredientRows.map((line) => {
    const costLine = findBackendCostLine(costLines, line);
    if (!costLine) return line;

    const unitCost = Number(costLine.unit_cost ?? line.unit_cost ?? 0);
    const currency = costLine.currency || line.currency || costingData?.currency || "USD";

    return {
      ...line,
      unit_cost: unitCost,
      currency,
      backend_line_cost: Number(costLine.line_cost ?? 0),
      backend_net_cost: Number(costLine.net_cost ?? 0),
      backend_wastage_cost: Number(costLine.wastage_cost ?? 0),
      backend_gross_quantity: Number(costLine.gross_quantity ?? 0),
      line_type: costLine.line_type || line.line_type,
    };
  });
};

export default function FoodRecipeBomModal({
  open,
  onClose,
  currentUser = null,
  initialMenuItem = null,
}) {
  const branchId = getBranchId(currentUser);
  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);
  const [baseCurrencies, setBaseCurrencies] = useState([]);
  const [form, setForm] = useState(emptyForm(branchId, "USD"));
  const [ingredients, setIngredients] = useState([]);
  const [search, setSearch] = useState("");
  const [productSearch, setProductSearch] = useState("");
  const [lookupMode, setLookupMode] = useState("ingredient");
  const [lookupOpen, setLookupOpen] = useState(false);
  const [newIngredientOpen, setNewIngredientOpen] = useState(false);
  const [activeTab, setActiveTab] = useState("ingredients");
  const [selectedRecipeId, setSelectedRecipeId] = useState(null);
  const [loading, setLoading] = useState(false);
  const [costing, setCosting] = useState(null);
  const [recipeEditorOpen, setRecipeEditorOpen] = useState(false);
  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const defaultCurrency = baseCurrencies?.[0]?.code || "USD";
  const showMessage = ({ type = "success", title, message }) =>
    setMessageModal({ open: true, type, title, message });

  const totals = useMemo(() => {
    const ingredientCost = ingredients.reduce((sum, line) => {
      if (line.line_type === "packaging") return sum;
      const q = Number(line.quantity || 0);
      const waste = Number(line.wastage_percent || 0);
      const gross = q + q * (waste / 100);
      return sum + gross * Number(line.unit_cost || 0);
    }, 0);
    const packagingComponentCost = ingredients.reduce((sum, line) => {
      if (line.line_type !== "packaging") return sum;
      const q = Number(line.quantity || 0);
      const waste = Number(line.wastage_percent || 0);
      const gross = q + q * (waste / 100);
      return sum + gross * Number(line.unit_cost || 0);
    }, 0);
    const packagingCost = packagingComponentCost + Number(form.packaging_cost || 0);
    const totalCost =
      ingredientCost +
      Number(form.labour_cost || 0) +
      Number(form.overhead_cost || 0) +
      packagingCost;
    const costPerUnit = totalCost / Math.max(Number(form.yield_quantity || 1), 1);
    return {
      ingredientCost,
      packagingCost,
      packagingComponentCost,
      totalCost,
      costPerUnit,
      lines: ingredients.length,
    };
  }, [ingredients, form.labour_cost, form.overhead_cost, form.packaging_cost, form.yield_quantity]);

  const displayTotals = useMemo(() => {
    if (!costing) return totals;
    return {
      ingredientCost: Number(costing.ingredient_cost ?? totals.ingredientCost),
      totalCost: Number(costing.total_cost ?? totals.totalCost),
      costPerUnit: Number(costing.cost_per_unit ?? totals.costPerUnit),
      otherCosts:
        Number(costing.packaging_cost || 0) +
        Number(costing.labour_cost || 0) +
        Number(costing.overhead_cost || 0),
      wastageCost: Number(costing.wastage_cost || 0),
      lines: Array.isArray(costing.lines) ? costing.lines.length : totals.lines,
    };
  }, [costing, totals]);

  const filteredRecipes = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return recipes;
    return recipes.filter(
      (recipe) =>
        String(recipe.name || "").toLowerCase().includes(q) ||
        String(
          recipe.menu_product?.name ||
            recipe.menuProduct?.name ||
            recipe.produced_product?.name ||
            recipe.producedProduct?.name ||
            recipe.menu_product_name ||
            "",
        )
          .toLowerCase()
          .includes(q) ||
        String(recipe.recipe_type || "").toLowerCase().includes(q),
    );
  }, [recipes, search]);

  useEffect(() => {
    if (open) loadAll();
  }, [open]);

  const startRecipeForMenuItem = (product) => {
    if (!product?.id) return;

    setSelectedRecipeId(null);
    setCosting(null);
    setIngredients([]);
    setActiveTab("ingredients");
    setForm({
      ...emptyForm(branchId, defaultCurrency),
      menu_product_id: product.id,
      menu_product_name: getProductName(product),
      menu_product_code: getProductCode(product),
      produced_product_id: null,
      name: `${getProductName(product)} Recipe`,
      recipe_type: "menu",
      yield_quantity: 1,
      yield_uom_id: product?.base_uom_id || product?.uom?.id || null,
      currency: defaultCurrency,
      is_active: true,
    });
    setRecipeEditorOpen(true);
  };

  useEffect(() => {
    if (!open || !initialMenuItem?.id) return;
    startRecipeForMenuItem(initialMenuItem);
  }, [open, initialMenuItem?.id, defaultCurrency]);

  // The register shows the newest 100 recipes; searching asks the server, so
  // every recipe can be found.
  const recipeQuery = (q = search) => ({
    per_page: 100,
    branch_id: branchId || undefined,
    q: q.trim() || undefined,
  });

  useEffect(() => {
    if (!open) return undefined;
    let current = true;
    const timer = setTimeout(async () => {
      try {
        const rows = normalizeRows(await getFoodRecipes(recipeQuery(search)));
        if (current) setRecipes(rows);
      } catch (error) {
        if (current) showMessage({ type: "error", title: "Search Failed", message: getErrorMessage(error) });
      }
    }, 300);
    return () => {
      current = false;
      clearTimeout(timer);
    };
  }, [search]);

  const loadAll = async () => {
    try {
      setLoading(true);
      const [recipesRes, currencyRes] = await Promise.all([
        getFoodRecipes(recipeQuery()),
        getBaseCurrencies(),
      ]);
      const currencies = normalizeRows(currencyRes);
      setBaseCurrencies(currencies);
      setRecipes(normalizeRows(recipesRes));
      if (!form.id && !selectedRecipeId && !initialMenuItem?.id && !recipeEditorOpen) {
        setForm(emptyForm(branchId, currencies?.[0]?.code || "USD"));
      }
    } catch (error) {
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getErrorMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  const searchProducts = async (q = "") => {
    try {
      const allowed = lookupMode === "menu"
        ? (form.recipe_type === "menu" ? MENU_TYPES : PRODUCTION_OUTPUT_TYPES)
        : MATERIAL_TYPES;
      const res = await getProducts({
        q,
        search: q,
        branch_id: branchId || undefined,
        per_page: 100,
        is_active: true,
        product_types: allowed.join(","),
      });
      setProducts(
        normalizeRows(res).filter((p) => {
          const type = String(p.product_type || "").toLowerCase();
          return allowed.includes(type);
        })
      );
    } catch (error) {
      showMessage({
        type: "error",
        title: "Product Search Failed",
        message: getErrorMessage(error),
      });
    }
  };

  const openLookup = (mode) => {
    setLookupMode(mode);
    setProductSearch("");
    setLookupOpen(true);
    setTimeout(() => searchProducts(""), 0);
  };

  const selectProduct = (product) => {
    if (lookupMode === "menu") {
      const isMenuRecipe = form.recipe_type === "menu";
      setForm((prev) => ({
        ...prev,
        menu_product_id: isMenuRecipe ? product.id : "",
        produced_product_id: isMenuRecipe ? null : product.id,
        menu_product_name: getProductName(product),
        menu_product_code: getProductCode(product),
        yield_uom_id: product?.base_uom_id || product?.uom?.id || null,
      }));
      setLookupOpen(false);
      return;
    }

    const currency = getCurrency(product, form.currency || defaultCurrency);
    const unitCost = getAverageCost(product);
    setIngredients((prev) => [
      ...prev,
      {
        line_id: `${product.id}-${Date.now()}`,
        ingredient_product_id: product.id,
        product_name: getProductName(product),
        product_code: getProductCode(product),
        product_type: product.product_type,
        quantity: 1,
        uom_id: product?.base_uom_id || product?.uom?.id || null,
        unit: getProductUom(product),
        wastage_percent: 0,
        unit_cost: unitCost,
        currency,
        line_type: componentTypeForProduct(product.product_type),
        notes: "",
      },
    ]);
    setCosting(null);
    setLookupOpen(false);
  };

  const buildIngredientRowsFromRecipe = (recipe) => {
    const rows = recipe.ingredients || recipe.recipe_ingredients || recipe.items || [];

    return rows.map((item, index) => {
      const product = item.ingredient_product || item.product || item.material || null;
      const metadata = item.metadata || {};
      const unitCost = Number(
        item.unit_cost ??
          item.average_cost ??
          item.cost ??
          item.cost_price ??
          metadata.unit_cost ??
          metadata.average_cost ??
          getAverageCost(product) ??
          0,
      );

      return {
        line_id: item.id || `${item.ingredient_product_id || item.product_id || index}-${Date.now()}`,
        id: item.id,
        ingredient_product_id: item.ingredient_product_id || item.product_id || product?.id,
        product_name: product?.name || item.product_name || metadata.product_name || "",
        product_code: product?.code || product?.sku || item.product_code || metadata.product_code || "",
        product_type: product?.product_type || item.product_type || metadata.product_type || "",
        quantity: Number(item.quantity ?? item.quantity_required ?? item.net_quantity ?? 0),
        uom_id: item.uom_id || product?.base_uom_id || product?.uom?.id || null,
        unit: item.uom?.code || item.uom?.name || metadata.unit || getProductUom(product),
        wastage_percent: Number(item.wastage_percent || 0),
        unit_cost: unitCost,
        currency: item.currency || metadata.currency || getCurrency(product, recipe.currency || defaultCurrency),
        line_type: item.line_type || componentTypeForProduct(product?.product_type || item.product_type),
        notes: item.notes || "",
      };
    });
  };

  const fetchAndApplyCosting = async (recipeId, rowsForMerge = null, switchTab = false) => {
    if (!recipeId) return null;

    try {
      const res = await getFoodRecipeCost(recipeId, {
        branch_id: branchId || undefined,
      });
      const data = payloadData(res);
      setCosting(data);
      setIngredients((prev) => mergeIngredientCostsFromCosting(rowsForMerge || prev, data));
      if (switchTab) setActiveTab("costing");
      return data;
    } catch (error) {
      showMessage({
        type: "error",
        title: "Cost Failed",
        message: getErrorMessage(error),
      });
      return null;
    }
  };

  const selectRecipe = async (recipe) => {
    const menu = recipe.menu_product || recipe.menuProduct || recipe.product || null;
    const produced = recipe.produced_product || recipe.producedProduct || null;
    const isMenuRecipe = recipe.recipe_type === "menu";
    const output = isMenuRecipe ? (menu || produced) : (produced || menu);
    const builtRows = buildIngredientRowsFromRecipe(recipe);

    setSelectedRecipeId(recipe.id);
    setCosting(null);
    setForm({
      ...emptyForm(branchId, recipe.currency || defaultCurrency),
      id: recipe.id,
      branch_id: recipe.branch_id || branchId,
      menu_product_id: isMenuRecipe ? (recipe.menu_product_id || menu?.id || "") : "",
      menu_product_name: output?.name || recipe.menu_product_name || "",
      menu_product_code: output?.code || output?.sku || recipe.menu_product_code || "",
      produced_product_id: isMenuRecipe ? null : (recipe.produced_product_id || produced?.id || menu?.id || null),
      name: recipe.name || "",
      recipe_type: recipe.recipe_type || "menu",
      yield_quantity: Number(recipe.yield_quantity || 1),
      yield_uom_id: recipe.yield_uom_id || null,
      currency: recipe.currency || defaultCurrency,
      labour_cost: Number(recipe.labour_cost || 0),
      overhead_cost: Number(recipe.overhead_cost || 0),
      packaging_cost: Number(recipe.packaging_cost || 0),
      notes: recipe.notes || "",
      is_active: recipe.is_active ?? true,
    });
    setIngredients(builtRows);
    setActiveTab("ingredients");

    // Important: backend recipe rows may not include saved unit_cost, but the
    // costing endpoint does. Fetch it after selecting the recipe and merge the
    // unit_cost/line_cost back into the ingredient grid.
    await fetchAndApplyCosting(recipe.id, builtRows, false);
  };

  const newRecipe = (showEditor = true) => {
    setSelectedRecipeId(null);
    setCosting(null);
    setForm(emptyForm(branchId, defaultCurrency));
    setIngredients([]);
    setActiveTab("ingredients");
    setRecipeEditorOpen(showEditor);
  };

  const editSelectedRecipe = () => {
    if (!form.id) {
      showMessage({ type: "error", title: "Select Recipe", message: "Select a recipe from the register first." });
      return;
    }
    setRecipeEditorOpen(true);
  };

  const updateIngredient = (lineId, patch) => {
    setIngredients((prev) => prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line)));
    setCosting(null);
  };

  const removeIngredient = (lineId) => {
    setIngredients((prev) => prev.filter((line) => line.line_id !== lineId));
    setCosting(null);
  };

  const saveRecipe = async () => {
    if (!form.name.trim()) {
      return showMessage({
        type: "error",
        title: "Validation",
        message: "Recipe name is required.",
      });
    }
    if (!ingredients.length) {
      return showMessage({
        type: "error",
        title: "Validation",
        message: "Add at least one recipe ingredient.",
      });
    }

    const isMenuRecipe = form.recipe_type === "menu";
    const payload = buildFoodRecipePayload({
      ...form,
      menu_product_id: isMenuRecipe ? (form.menu_product_id || null) : null,
      produced_product_id: isMenuRecipe ? null : (form.produced_product_id || null),
      ingredients,
    });

    try {
      setLoading(true);
      const res = form.id ? await updateFoodRecipe(form.id, payload) : await createFoodRecipe(payload);
      showMessage({
        title: "Saved",
        message: res?.message || "Food recipe saved successfully.",
      });

      const saved = payloadData(res);
      const savedId = saved?.id || form.id;

      setForm((prev) => ({ ...prev, id: savedId || prev.id }));
      setSelectedRecipeId(savedId || selectedRecipeId);
      await loadAll();

      if (savedId) {
        await fetchAndApplyCosting(savedId, ingredients, false);
      }
      setRecipeEditorOpen(false);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Save Failed",
        message: getErrorMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  const deleteRecipe = async () => {
    if (!form.id) {
      return showMessage({
        type: "error",
        title: "Select Recipe",
        message: "Select a recipe first.",
      });
    }
    if (!await requestEnterpriseConfirmation({
      title: "Delete Recipe",
      message: `Delete recipe ${form.name}?`,
      confirmLabel: "Delete recipe",
    })) return;
    try {
      setLoading(true);
      const res = await deleteFoodRecipe(form.id);
      showMessage({
        title: "Deleted",
        message: res?.message || "Recipe deleted.",
      });
      newRecipe(false);
      await loadAll();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getErrorMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  const loadCost = async () => {
    if (!form.id) {
      return showMessage({
        type: "error",
        title: "Costing",
        message: "Save/select recipe first.",
      });
    }
    try {
      setLoading(true);
      await fetchAndApplyCosting(form.id, ingredients, true);
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="FOOD RECIPE / BOM MANAGEMENT"
        subtitle="Menu recipe, wastage, currency and food cost"
      >
        <div className="food-bom-page h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
          <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
            <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
              <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
                <div className="flex items-center gap-2">
                  <div className="text-[13px] font-semibold text-[#0C1B1B]">Food BOM workbench</div>
                  <div className="h-4 border-l border-[#AAB5B5]" />
                  <div className="text-[10px] font-medium uppercase text-[#627474]">
                    Recipes can be unlinked. Menu recipes link to menu items; modifier recipes can be selected later on a modifier.
                  </div>
                </div>
                <div className="flex items-center gap-1">
                  <ActionButton onClick={() => newRecipe(true)} label="New Recipe" icon={Plus} primary />
                  <ActionButton onClick={editSelectedRecipe} label="Edit Recipe" icon={CheckCircle2} />
                  <ActionButton onClick={loadAll} label="Refresh" icon={RefreshCw} />
                  <ActionButton onClick={deleteRecipe} label="Delete" icon={Trash2} danger />
                </div>
              </div>
              <div className="grid grid-cols-[minmax(260px,1fr)_170px_190px_190px_190px] gap-2 px-2 py-2">
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search recipe, menu item..."
                    className="food-input pl-8"
                  />
                </div>
                <SummaryBox label="Recipes" value={recipes.length} />
                <SummaryBox label="Ingredients" value={ingredients.length} />
                <SummaryBox label="Recipe Cost" value={money(form.currency, displayTotals.totalCost)} />
                <SummaryBox label="Cost / Unit" value={money(form.currency, displayTotals.costPerUnit)} />
              </div>
            </div>

            <div className="min-h-0 flex-1 border-b border-[#AAB5B5]">
              <section className="flex h-full min-w-0 flex-col">
                <SectionTitle title="Recipe Register" subtitle="Menu BOMs" />
                <div className="min-h-0 flex-1 overflow-auto">
                  <table className="sx-enterprise-table w-full min-w-[760px] border-collapse text-left text-[11px]">
                    <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
                      <tr>
                        <GridTh>#</GridTh>
                        <GridTh>Recipe</GridTh>
                        <GridTh>Output Item</GridTh>
                        <GridTh>Type</GridTh>
                        <GridTh>Status</GridTh>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredRecipes.length === 0 ? (
                        <tr>
                          <td colSpan={5} className="px-3 py-10 text-center text-[#627474]">
                            No food recipes found.
                          </td>
                        </tr>
                      ) : (
                        filteredRecipes.map((recipe, index) => {
                          const menu = recipe.menu_product || recipe.menuProduct || {};
                          const produced = recipe.produced_product || recipe.producedProduct || {};
                          const selected = selectedRecipeId === recipe.id;
                          return (
                            <tr
                              key={recipe.id}
                              onClick={() => selectRecipe(recipe)}
                              className={`cursor-pointer border-b border-[#CFD9D8] ${
                                selected ? "bg-[#64A84F] text-white" : "bg-white text-[#324443] hover:bg-[#F2F8F2]"
                              }`}
                            >
                              <GridTd>{index + 1}</GridTd>
                              <GridTd strong>{recipe.name || "-"}</GridTd>
                              <GridTd>{menu.name || produced.name || recipe.menu_product_name || "Unlinked"}</GridTd>
                              <GridTd>{recipe.recipe_type || "menu"}</GridTd>
                              <GridTd>{recipe.is_active === false ? "Inactive" : "Active"}</GridTd>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>
              </section>
            </div>

            <div className="shrink-0 bg-[#F7F8FA] p-2">
              <div className="grid gap-2 xl:grid-cols-3">
                <Panel title="Selected Recipe" subtitle="Read-only register selection">
                  <p>{form.id ? `${form.name} · ${form.recipe_type}` : "Select a recipe in the register, then use Edit Recipe."}</p>
                </Panel>
                <Panel title="Linked Item" subtitle="Menu or produced stock item">
                  <p>{form.id ? (form.menu_product_name || "Unlinked recipe") : "No recipe selected."}</p>
                </Panel>
                <Panel title="Recipe Editing" subtitle="Modal-only data entry">
                  <p>New and Edit open the full recipe header, ingredients and costing workflow in a centered dialog.</p>
                </Panel>
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>

      <ManufacturingFormModal
        open={open && recipeEditorOpen}
        title={form.id ? "Edit Food Recipe / BOM" : "New Food Recipe / BOM"}
        subtitle="Recipe header, ingredients and costing"
        onClose={() => setRecipeEditorOpen(false)}
        busy={loading}
        pageClass="food-bom-page"
        width="1280px"
        footer={(
          <>
            <ActionButton onClick={() => setRecipeEditorOpen(false)} label="Cancel" disabled={loading} />
            <ActionButton onClick={saveRecipe} label={loading ? "Saving" : "Save Recipe"} icon={CheckCircle2} primary disabled={loading} />
          </>
        )}
      >
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <BomField label="Recipe Name">
            <input value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))} className="food-input" />
          </BomField>
          <BomField label="Type">
            <select
              value={form.recipe_type}
              onChange={(e) => {
                const recipeType = e.target.value;
                setForm((prev) => {
                  const outputId = prev.menu_product_id || prev.produced_product_id || null;
                  return {
                    ...prev,
                    recipe_type: recipeType,
                    menu_product_id: recipeType === "menu" ? outputId : "",
                    produced_product_id: recipeType === "menu" ? null : outputId,
                  };
                });
              }}
              className="food-input"
            >
              {RECIPE_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </BomField>
          <BomField label="Menu / Produced Item">
            <div className="grid grid-cols-[1fr_auto] gap-1">
              <button type="button" onClick={() => openLookup("menu")} className="food-lookup">
                {form.menu_product_name || (form.recipe_type === "menu" ? "Select menu item" : "Select produced item")}
              </button>
              <button
                type="button"
                onClick={() => setForm((p) => ({ ...p, menu_product_id: "", menu_product_name: "", menu_product_code: "", produced_product_id: null, yield_uom_id: null }))}
                className="h-8 border border-[#AAB5B5] bg-white px-2 text-[10px] font-semibold text-[#324443]"
              >
                Clear
              </button>
            </div>
          </BomField>
          <BomField label="Currency">
            <select value={form.currency} onChange={(e) => setForm((p) => ({ ...p, currency: e.target.value }))} className="food-input">
              {(baseCurrencies.length ? baseCurrencies : [{ code: "USD" }]).map((currencyItem) => <option key={currencyItem.id || currencyItem.code} value={currencyItem.code}>{currencyItem.code}</option>)}
            </select>
          </BomField>
          <BomField label="Yield Qty">
            <input type="number" step="0.0001" value={form.yield_quantity} onChange={(e) => setForm((p) => ({ ...p, yield_quantity: Number(e.target.value || 0) }))} className="food-input" />
          </BomField>
          <BomField label="Labour Cost">
            <input type="number" step="0.0001" value={form.labour_cost} onChange={(e) => setForm((p) => ({ ...p, labour_cost: Number(e.target.value || 0) }))} className="food-input" />
          </BomField>
          <BomField label="Overhead Cost">
            <input type="number" step="0.0001" value={form.overhead_cost} onChange={(e) => setForm((p) => ({ ...p, overhead_cost: Number(e.target.value || 0) }))} className="food-input" />
          </BomField>
          <BomField label="Packaging Cost">
            <input type="number" step="0.0001" value={form.packaging_cost} onChange={(e) => setForm((p) => ({ ...p, packaging_cost: Number(e.target.value || 0) }))} className="food-input" />
          </BomField>
        </div>

        <div className="mt-3 border border-[#A4E5E4] bg-[#F1F8F8] px-2 py-2 text-[11px] font-semibold leading-5 text-[#057B7B]">
          Every BOM needs an output item. Menu sale recipes deduct FRONT stock; prep and manufacturing recipes consume BACK stock and create a prepared output item.
        </div>

        <div className="mt-3 flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
          {[["ingredients", "Ingredients"], ["costing", "Costing"], ["rules", "Rules"]].map(([key, label]) => (
            <button
              key={key}
              type="button"
              onClick={() => (key === "costing" ? loadCost() : setActiveTab(key))}
              className={`h-7 border border-b-0 px-4 text-[11px] font-semibold ${activeTab === key ? "border-[#31B44B] bg-[#31B44B] text-white" : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#EEF2F2]"}`}
            >
              {label}
            </button>
          ))}
        </div>
        <div className="h-[46vh] min-h-[320px] overflow-hidden border border-t-0 border-[#AAB5B5] bg-white">
          {activeTab === "ingredients" && (
            <IngredientsTable ingredients={ingredients} updateIngredient={updateIngredient} removeIngredient={removeIngredient} openLookup={() => openLookup("ingredient")} />
          )}
          {activeTab === "costing" && <CostingPanel totals={displayTotals} costing={costing} currency={form.currency} />}
          {activeTab === "rules" && <RulesPanel />}
        </div>
      </ManufacturingFormModal>

      {lookupOpen && (
        <ProductLookupModal
          title={lookupMode === "menu" ? (form.recipe_type === "menu" ? "Select Menu Item" : "Select Produced Item") : "Select Ingredient"}
          search={productSearch}
          setSearch={setProductSearch}
          products={products}
          searchProducts={searchProducts}
          onSelect={selectProduct}
          onCreateNew={lookupMode === "ingredient" ? () => setNewIngredientOpen(true) : null}
          onClose={() => setLookupOpen(false)}
        />
      )}
      <NewIngredientDialog
        open={newIngredientOpen}
        initialName={productSearch}
        branchId={branchId}
        onClose={() => setNewIngredientOpen(false)}
        onCreated={(product) => {
          setNewIngredientOpen(false);
          selectProduct(product);
          showMessage({ title: "Ingredient Added", message: `${getProductName(product)} was created and added to this recipe.` });
        }}
      />
      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={() => setMessageModal((p) => ({ ...p, open: false }))}
      />
      <FoodBomStyles />
    </>
  );
}

function IngredientsTable({ ingredients, updateIngredient, removeIngredient, openLookup }) {
  return (
    <div className="h-full overflow-auto">
      <div className="flex items-center justify-between border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5">
        <div className="text-[11px] font-medium text-[#057B7B]">
          Add raw materials, semi-finished items, packaging and consumables. Wastage % is included in cost and deduction quantity.
        </div>
        <SmallButton onClick={openLookup}>Add Ingredient</SmallButton>
      </div>
      <table className="sx-enterprise-table w-full min-w-[1460px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Code</GridTh>
            <GridTh>Ingredient</GridTh>
            <GridTh>Product Type</GridTh>
            <GridTh>Component Role</GridTh>
            <GridTh>UOM</GridTh>
            <GridTh align="right">Qty</GridTh>
            <GridTh align="right">Wastage %</GridTh>
            <GridTh align="right">Gross Qty</GridTh>
            <GridTh align="right">Unit Cost</GridTh>
            <GridTh align="right">Line Cost</GridTh>
            <GridTh>Action</GridTh>
          </tr>
        </thead>
        <tbody>
          {ingredients.length === 0 ? (
            <tr>
              <td colSpan={12} className="px-3 py-10 text-center text-[#627474]">
                No ingredients added.
              </td>
            </tr>
          ) : (
            ingredients.map((line, index) => {
              const q = Number(line.quantity || 0);
              const waste = Number(line.wastage_percent || 0);
              const gross = q + (q * waste) / 100;
              const unitCost = Number(line.unit_cost || 0);
              const lineCost = gross * unitCost;
              return (
                <tr key={line.line_id} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
                  <GridTd>{index + 1}</GridTd>
                  <GridTd>{line.product_code || "-"}</GridTd>
                  <GridTd strong>{line.product_name}</GridTd>
                  <GridTd>{String(line.product_type || "-").replaceAll("_", " ")}</GridTd>
                  <GridTd>
                    <select
                      value={line.line_type || componentTypeForProduct(line.product_type)}
                      onChange={(event) => updateIngredient(line.line_id, { line_type: event.target.value })}
                      className="h-7 w-full min-w-[145px] border border-[#B9C3C3] bg-white px-1 text-[10px] text-[#324443]"
                    >
                      {COMPONENT_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                  </GridTd>
                  <GridTd>{line.unit || "-"}</GridTd>
                  <GridTd right>
                    <MiniInput
                      value={line.quantity}
                      onChange={(value) => updateIngredient(line.line_id, { quantity: Number(value || 0) })}
                    />
                  </GridTd>
                  <GridTd right>
                    <MiniInput
                      value={line.wastage_percent}
                      onChange={(value) => updateIngredient(line.line_id, { wastage_percent: Number(value || 0) })}
                    />
                  </GridTd>
                  <GridTd right>{qty(gross)}</GridTd>
                  <GridTd right>
                    <div className={unitCost > 0 ? "font-semibold text-[#027A48]" : "font-semibold text-[#B42318]"}>
                      {money(line.currency, unitCost)}
                    </div>
                  </GridTd>
                  <GridTd right>
                    <div className={lineCost > 0 ? "font-semibold text-[#027A48]" : "font-semibold text-[#B42318]"}>
                      {money(line.currency, lineCost)}
                    </div>
                  </GridTd>
                  <GridTd>
                    <RecipeRowActionMenu
                      label={`Actions for ${line.product_name}`}
                      actions={[{ label: "Remove", danger: true, onClick: () => removeIngredient(line.line_id) }]}
                    />
                  </GridTd>
                </tr>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}

function RecipeRowActionMenu({ label = "Row actions", actions = [] }) {
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

function CostingPanel({ totals, costing, currency }) {
  const lines = Array.isArray(costing?.lines) ? costing.lines : [];
  const otherCosts = Number(totals.otherCosts ?? Number(totals.totalCost || 0) - Number(totals.ingredientCost || 0));

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-5">
        <MetricCard title="Ingredient Cost" value={money(currency, totals.ingredientCost)} />
        <MetricCard title="Wastage Cost" value={money(currency, totals.wastageCost || 0)} />
        <MetricCard title="Packaging + Other" value={money(currency, otherCosts)} />
        <MetricCard title="Total Cost" value={money(currency, totals.totalCost)} />
        <MetricCard title="Cost / Unit" value={money(currency, totals.costPerUnit)} />
      </div>

      <div className="mt-2 border border-[#AFB9B9] bg-white">
        <div className="flex h-8 items-center justify-between border-b border-[#CFD9D8] bg-[#EEF2F2] px-2">
          <div className="text-[11px] font-semibold uppercase text-[#324443]">Cost Breakdown</div>
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            {lines.length ? `${lines.length} backend cost line(s)` : "Using current frontend totals"}
          </div>
        </div>
        <div className="overflow-auto">
          <table className="sx-enterprise-table w-full min-w-[1100px] border-collapse text-left text-[11px]">
            <thead className="bg-[#0E4D4D] text-white">
              <tr>
                <GridTh>Ingredient</GridTh>
                <GridTh>Component Role</GridTh>
                <GridTh>UOM</GridTh>
                <GridTh align="right">Net Qty</GridTh>
                <GridTh align="right">Wastage %</GridTh>
                <GridTh align="right">Gross Qty</GridTh>
                <GridTh align="right">Unit Cost</GridTh>
                <GridTh align="right">Net Cost</GridTh>
                <GridTh align="right">Wastage Cost</GridTh>
                <GridTh align="right">Line Cost</GridTh>
              </tr>
            </thead>
            <tbody>
              {lines.length === 0 ? (
                <tr>
                  <td colSpan={10} className="px-3 py-8 text-center text-[#627474]">
                    Select a saved recipe or click Costing to load the backend cost breakdown.
                  </td>
                </tr>
              ) : (
                lines.map((line, index) => (
                  <tr key={`${line.ingredient_product_id || index}-${index}`} className="border-b border-[#CFD9D8] bg-white">
                    <GridTd strong>{line.ingredient_name || line.product_name || "-"}</GridTd>
                    <GridTd>{String(line.line_type || "ingredient").replaceAll("_", " ")}</GridTd>
                    <GridTd>{line.uom || "-"}</GridTd>
                    <GridTd right>{qty(line.net_quantity)}</GridTd>
                    <GridTd right>{qty(line.wastage_percent)}</GridTd>
                    <GridTd right>{qty(line.gross_quantity)}</GridTd>
                    <GridTd right>{money(line.currency || currency, line.unit_cost)}</GridTd>
                    <GridTd right>{money(line.currency || currency, line.net_cost)}</GridTd>
                    <GridTd right>{money(line.currency || currency, line.wastage_cost)}</GridTd>
                    <GridTd right>
                      <span className="font-semibold text-[#027A48]">{money(line.currency || currency, line.line_cost)}</span>
                    </GridTd>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function RulesPanel() {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-3">
      <div className="grid gap-2 xl:grid-cols-3">
        <Panel title="Sale Recipe" subtitle="POS consumption">
          <p>When a menu item is sold, the active linked recipe deducts ingredients from FRONT stock only.</p>
        </Panel>
        <Panel title="Manufacturing Recipe" subtitle="Prep batch">
          <p>Ingredients, packaging and prepared sub-recipes are classified separately, deducted from BACK stock, and the produced item is added to its selected stock area.</p>
        </Panel>
        <Panel title="Cost Rule" subtitle="Wastage included">
          <p>Gross quantity = recipe quantity + wastage %. Cost = gross quantity x average ingredient cost plus labour, overhead and packaging.</p>
        </Panel>
      </div>
    </div>
  );
}

function ManufacturingFormModal({ open, title, subtitle, onClose, busy = false, footer, pageClass, width, children }) {
  return (
    <EnterpriseFormModal open={open} title={title} subtitle={subtitle} onClose={onClose} busy={busy} width={width}>
      <div className={`${pageClass} flex h-[78vh] min-h-[520px] max-h-[760px] flex-col`}>
        <div className="min-h-0 flex-1 overflow-auto bg-[#F7F8FA] p-3">{children}</div>
        <div className="flex shrink-0 justify-end gap-1 border-t border-[#ACB8B8] bg-[#EEF2F2] px-3 py-2">{footer}</div>
      </div>
    </EnterpriseFormModal>
  );
}

function ProductLookupModal({ title, search, setSearch, products, searchProducts, onSelect, onCreateNew = null, onClose }) {
  useEffect(() => {
    const t = setTimeout(() => searchProducts(search), 250);
    return () => clearTimeout(t);
  }, [search]);

  return (
    <div className="fixed inset-0 z-[100010] flex items-center justify-center bg-black/50 p-4">
      <div className="sx-large-modal-window border border-[#ACB8B8] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={title}>
        <div className="flex h-11 items-center justify-between bg-[#004142] px-3 text-white">
          <div className="text-[12px] font-semibold uppercase">{title}</div>
          <button type="button" onClick={onClose} className="text-xl">
            x
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto p-2">
          <div className="mb-2 flex gap-1">
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search product..."
              className="food-input"
            />
            {onCreateNew && (
              <button
                type="button"
                onClick={onCreateNew}
                className="inline-flex h-8 shrink-0 items-center gap-1 border border-[#067C7C] bg-[#069494] px-3 text-[11px] font-semibold text-white hover:bg-[#067C7C]"
              >
                <Plus className="h-3.5 w-3.5" />
                New Ingredient
              </button>
            )}
          </div>
          <div className="max-h-[60vh] overflow-auto border border-[#CFD9D8]">
            <table className="sx-enterprise-table w-full min-w-[760px] text-left text-[11px]">
              <thead className="sticky top-0 bg-[#0E4D4D] text-white">
                <tr>
                  <GridTh>Code</GridTh>
                  <GridTh>Product</GridTh>
                  <GridTh>Type</GridTh>
                  <GridTh>UOM</GridTh>
                  <GridTh>Cost</GridTh>
                </tr>
              </thead>
              <tbody>
                {products.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-3 py-8 text-center text-[#627474]">
                      {onCreateNew ? "No matching stock items. Use New Ingredient to create one." : "No matching items."}
                    </td>
                  </tr>
                )}
                {products.map((p) => (
                  <tr key={p.id} onClick={() => onSelect(p)} className="cursor-pointer border-b border-[#CFD9D8] hover:bg-[#F2F8F2]">
                    <GridTd>{getProductCode(p)}</GridTd>
                    <GridTd strong>{getProductName(p)}</GridTd>
                    <GridTd>{p.product_type}</GridTd>
                    <GridTd>{getProductUom(p)}</GridTd>
                    <GridTd>{money(getCurrency(p), getAverageCost(p))}</GridTd>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}

function ActionButton({ label, icon: Icon, onClick, primary = false, danger = false, disabled = false }) {
  let cls = "border-[#AAB5B5] bg-white text-[#324443]";
  if (primary) cls = "border-[#027A48] bg-[#027A48] text-white";
  if (danger) cls = "border-[#DC2626] bg-white text-[#B91C1C]";
  return (
    <button type="button" onClick={onClick} disabled={disabled} className={`inline-flex h-7 items-center gap-1 border px-2 text-[11px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${cls}`}>
      {Icon && <Icon className="h-3.5 w-3.5" />}
      {label}
    </button>
  );
}

function SmallButton({ children, onClick }) {
  return (
    <button type="button" onClick={onClick} className="h-7 border border-[#027A48] bg-[#027A48] px-3 text-[11px] font-semibold text-white">
      {children}
    </button>
  );
}

function MiniInput({ value, onChange }) {
  return (
    <input
      type="number"
      step="0.0001"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="h-7 w-full border border-[#B9C3C3] bg-white px-2 text-right text-[11px]"
    />
  );
}

function SummaryBox({ label, value }) {
  return (
    <div className="border border-[#D8DFDF] bg-white px-3 py-2">
      <div className="text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <div className="mt-1 truncate text-[14px] font-semibold text-[#004343]">{value}</div>
    </div>
  );
}

function SectionTitle({ title, subtitle }) {
  return (
    <div className="flex h-7 items-center justify-between border-b border-[#AAB5B5] bg-[#EEF2F2] px-2">
      <div className="text-[11px] font-semibold uppercase text-[#324443]">{title}</div>
      <div className="text-[10px] font-medium uppercase text-[#627474]">{subtitle}</div>
    </div>
  );
}

function BomField({ label, children }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      {children}
    </label>
  );
}

function GridTh({ children, align = "left" }) {
  return (
    <th className={`border-r border-[#465857] px-2 py-1.5 font-semibold ${align === "right" ? "text-right" : "text-left"}`}>
      {children}
    </th>
  );
}

function GridTd({ children, right = false, strong = false }) {
  return (
    <td className={`border-r border-[#CFD9D8] px-2 py-1.5 ${right ? "text-right" : "text-left"} ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}>
      {children}
    </td>
  );
}

function MetricCard({ title, value }) {
  return (
    <div className="border border-[#AFB9B9] bg-white p-3">
      <div className="text-[10px] font-semibold uppercase text-[#627474]">{title}</div>
      <div className="mt-2 truncate text-[22px] font-semibold text-[#004343]">{value}</div>
    </div>
  );
}

function Panel({ title, subtitle, children }) {
  return (
    <section className="border border-[#AFB9B9] bg-white p-3 text-[12px] leading-5 text-[#324443]">
      <div className="mb-2">
        <div className="text-[12px] font-semibold uppercase text-[#324443]">{title}</div>
        <div className="text-[10px] text-[#627474]">{subtitle}</div>
      </div>
      {children}
    </section>
  );
}

function FoodBomStyles() {
  return (
    <style>{`.food-bom-page button,.food-bom-page input,.food-bom-page select,.food-bom-page textarea{border-radius:0!important}.food-input{height:2rem;width:100%;border:1px solid #AAB5B5;background:#fff;padding:0 .5rem;font-size:11px;outline:none}.food-input:focus{border-color:#004343;box-shadow:inset 0 0 0 1px #004343}.food-lookup{height:2rem;width:100%;border:1px solid #AAB5B5;background:#fff;padding:0 .5rem;text-align:left;font-size:11px;font-weight:600;color:#004343}`}</style>
  );
}

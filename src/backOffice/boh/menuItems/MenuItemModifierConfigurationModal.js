import React, { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";

import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import { getFoodRecipes } from "../../../services/FoodManufacturingService";
import {
  assignModifierToProducts,
  createModifier,
  deleteModifier,
  getFastFoodProductOptions,
  getFinishedGoodMenuItemsForConfiguration,
  getModifiers,
  removeModifierFromProduct,
  updateModifier,
} from "../../../services/FastFoodPrepModifierService";

const emptyModifierForm = {
  id: null,
  branch_id: "",
  name: "",
  code: "",
  modifier_product_id: "",
  recipe_id: "",
  price: "0.0000",
  currency_code: "",
  deduct_recipe_stock: true,
  print_on_kitchen: true,
  print_on_customer_receipt: true,
  is_active: true,
};

const normalizeRows = (response) => {
  const payload = response?.data || response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
};

const normalizeOptions = (response) => {
  const payload = response?.data || response;
  return payload?.data || payload || {};
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;
  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }
  return responseData?.message || responseData?.error || source?.message || fallback;
};

const itemIdentity = (item) => [item?.code, item?.sku, item?.barcode].filter(Boolean).join(" | ") || "-";
const money = (value, currency = "USD") => `${currency || "USD"} ${Number(value || 0).toFixed(4)}`;

export default function MenuItemModifierConfigurationModal({
  open,
  onClose,
  currentUser = null,
}) {
  const [products, setProducts] = useState([]);
  const [modifiers, setModifiers] = useState([]);
  const [currencies, setCurrencies] = useState([]);
  const [recipes, setRecipes] = useState([]);
  const [selectedProductId, setSelectedProductId] = useState("");
  const [assignedIds, setAssignedIds] = useState([]);
  const originalAssignedIds = useRef([]);

  const [modifierForm, setModifierForm] = useState(emptyModifierForm);
  const [selectedModifierId, setSelectedModifierId] = useState(null);
  const [modifierFormOpen, setModifierFormOpen] = useState(false);

  const [productSearch, setProductSearch] = useState("");
  const [modifierSearch, setModifierSearch] = useState("");
  const [recipeSearch, setRecipeSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("active");
  const [loading, setLoading] = useState(false);
  const [assignmentLoading, setAssignmentLoading] = useState(false);
  const [savingConfig, setSavingConfig] = useState(false);
  const [savingModifier, setSavingModifier] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [confirmAction, setConfirmAction] = useState(null);
  const [messageModal, setMessageModal] = useState({ open: false, type: "success", title: "", message: "" });
  const assignmentRequestRef = useRef(0);

  const branchId =
    currentUser?.selectedBranchId ||
    currentUser?.selected_branch_id ||
    currentUser?.selectedBranch?.id ||
    currentUser?.branch_id ||
    currentUser?.branch?.id ||
    "";
  const defaultCurrency = useMemo(() => currencies?.[0]?.code || "USD", [currencies]);

  const showMessage = ({ type = "success", title = "Notice", message = "" }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessage = () => setMessageModal((prev) => ({ ...prev, open: false }));

  const selectedProduct = useMemo(
    () => products.find((product) => String(product.id) === String(selectedProductId)) || null,
    [products, selectedProductId],
  );

  const selectedModifier = useMemo(
    () => modifiers.find((row) => String(row.id) === String(selectedModifierId)) || null,
    [modifiers, selectedModifierId],
  );

  const selectedRecipe = useMemo(
    () => recipes.find((row) => String(row.id) === String(modifierForm.recipe_id)) || null,
    [recipes, modifierForm.recipe_id],
  );

  const visibleRecipes = useMemo(() => {
    const q = recipeSearch.trim().toLowerCase();
    return recipes.filter((recipe) => {
      const searchOk = !q || [
        recipe.name,
        recipe.recipe_type,
        recipe.menu_product?.name,
        recipe.menuProduct?.name,
        recipe.menu_product_name,
      ]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(q));
      return searchOk;
    });
  }, [recipes, recipeSearch]);

  const visibleProducts = useMemo(() => {
    const q = productSearch.trim().toLowerCase();
    if (!q) return products;
    return products.filter((product) => [product.name, product.code, product.sku, product.barcode]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(q)));
  }, [products, productSearch]);

  const visibleModifiers = useMemo(() => {
    const q = modifierSearch.trim().toLowerCase();
    return modifiers.filter((row) => {
      const statusOk = statusFilter === "all" || (statusFilter === "active" ? row.is_active !== false : row.is_active === false);
      const searchOk = !q || [row.name, row.code, recipes.find((recipe) => String(recipe.id) === String(row.recipe_id))?.name, row.modifier_product?.name, row.modifierProduct?.name]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(q));
      return statusOk && searchOk;
    });
  }, [modifiers, modifierSearch, statusFilter, recipes]);

  const selectedAssigned = useMemo(
    () => modifiers.filter((row) => assignedIds.includes(String(row.id))),
    [modifiers, assignedIds],
  );

  const assignmentsDirty = useMemo(() => {
    const current = assignedIds.map(String).sort();
    const original = originalAssignedIds.current.map(String).sort();
    return current.length !== original.length || current.some((id, index) => id !== original[index]);
  }, [assignedIds]);

  const loadProducts = async () => {
    const response = await getFinishedGoodMenuItemsForConfiguration({
      per_page: 1000,
      branch_id: branchId || undefined,
      branch_only: true,
    });
    const rows = normalizeRows(response).filter((product) => product?.product_type === "finished_good");
    setProducts(rows);
    setSelectedProductId((current) => (
      rows.some((product) => String(product.id) === String(current))
        ? current
        : rows[0]?.id || ""
    ));
  };

  const loadModifiers = async () => {
    const response = await getModifiers({
      per_page: 1000,
      branch_id: branchId || undefined,
    });
    setModifiers(normalizeRows(response));
  };

  const loadCurrencies = async () => {
    const response = await getBaseCurrencies();
    setCurrencies(normalizeRows(response));
  };

  const loadRecipes = async () => {
    const response = await getFoodRecipes({
      per_page: 1000,
      branch_id: branchId || undefined,
    });
    setRecipes(normalizeRows(response));
  };

  const loadProductAssignments = async (productId) => {
    const requestId = ++assignmentRequestRef.current;

    if (!productId) {
      setAssignedIds([]);
      originalAssignedIds.current = [];
      setAssignmentLoading(false);
      return;
    }

    setAssignmentLoading(true);

    try {
      const response = await getFastFoodProductOptions(productId, { branch_id: branchId || undefined });
      if (requestId !== assignmentRequestRef.current) return;

      const options = normalizeOptions(response);
      const ids = normalizeRows(options?.modifiers)
        .map((row) => String(row.id))
        .filter(Boolean);
      setAssignedIds(ids);
      originalAssignedIds.current = ids;
    } catch (error) {
      if (requestId === assignmentRequestRef.current) throw error;
    } finally {
      if (requestId === assignmentRequestRef.current) setAssignmentLoading(false);
    }
  };

  const loadData = async () => {
    try {
      setLoading(true);
      await Promise.all([loadProducts(), loadModifiers(), loadCurrencies(), loadRecipes()]);
    } catch (error) {
      showMessage({ type: "error", title: "Load Failed", message: getApiMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const reloadWorkspace = async () => {
    await loadData();

    if (!selectedProductId) return;

    try {
      await loadProductAssignments(selectedProductId);
    } catch (error) {
      showMessage({ type: "error", title: "Assignment Load Failed", message: getApiMessage(error) });
    }
  };

  const requestWorkspaceRefresh = () => {
    if (assignmentsDirty) {
      setConfirmAction({
        type: "refresh-workspace",
        title: "Discard Unsaved Assignment Changes?",
        message: `Refreshing will discard the unsaved modifier changes for ${selectedProduct?.name || "the selected menu item"}.`,
        confirmText: "Discard & Refresh",
      });
      return;
    }

    reloadWorkspace();
  };

  const requestWorkspaceClose = () => {
    if (assignmentsDirty) {
      setConfirmAction({
        type: "close-workspace",
        title: "Discard Unsaved Assignment Changes?",
        message: `Closing will discard the unsaved modifier changes for ${selectedProduct?.name || "the selected menu item"}.`,
        confirmText: "Discard & Close",
      });
      return;
    }

    onClose?.();
  };

  useEffect(() => {
    if (open) loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchId, open]);

  useEffect(() => {
    if (open && selectedProductId) {
      loadProductAssignments(selectedProductId).catch((error) => {
        showMessage({ type: "error", title: "Assignment Load Failed", message: getApiMessage(error) });
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchId, open, selectedProductId]);

  useEffect(() => {
    if (!selectedModifier) return;
    setModifierForm({
      id: selectedModifier.id,
      branch_id: selectedModifier.branch_id || "",
      name: selectedModifier.name || "",
      code: selectedModifier.code || "",
      modifier_product_id: selectedModifier.modifier_product_id || "",
      recipe_id: selectedModifier.recipe_id || "",
      price: selectedModifier.price ?? "0.0000",
      currency_code: selectedModifier.currency_code || defaultCurrency,
      deduct_recipe_stock: selectedModifier.deduct_recipe_stock !== false,
      print_on_kitchen: selectedModifier.print_on_kitchen !== false,
      print_on_customer_receipt: selectedModifier.print_on_customer_receipt !== false,
      is_active: selectedModifier.is_active !== false,
    });
  }, [selectedModifier, defaultCurrency]);

  const updateModifierForm = (field, value) => {
    setModifierForm((prev) => ({ ...prev, [field]: value }));
  };

  const newModifier = () => {
    setSelectedModifierId(null);
    setModifierForm({ ...emptyModifierForm, branch_id: branchId, currency_code: defaultCurrency });
    setModifierFormOpen(true);
  };

  const editModifier = (row) => {
    setSelectedModifierId(row.id);
    setModifierForm({
      id: row.id,
      branch_id: row.branch_id || "",
      name: row.name || "",
      code: row.code || "",
      modifier_product_id: row.modifier_product_id || "",
      recipe_id: row.recipe_id || "",
      price: row.price ?? "0.0000",
      currency_code: row.currency_code || defaultCurrency,
      deduct_recipe_stock: row.deduct_recipe_stock !== false,
      print_on_kitchen: row.print_on_kitchen !== false,
      print_on_customer_receipt: row.print_on_customer_receipt !== false,
      is_active: row.is_active !== false,
    });
    setModifierFormOpen(true);
  };

  const selectProduct = (product) => {
    if (!product || String(product.id) === String(selectedProductId)) return;

    if (assignmentsDirty) {
      setConfirmAction({
        type: "switch-product",
        product,
        title: "Discard Unsaved Assignment Changes?",
        message: `The modifier changes for ${selectedProduct?.name || "the selected menu item"} have not been saved. Continue to ${product.name}?`,
        confirmText: "Discard & Continue",
      });
      return;
    }

    setSelectedProductId(product.id);
  };

  const toggleAssigned = (id) => {
    const value = String(id);
    setAssignedIds((prev) => prev.includes(value) ? prev.filter((item) => item !== value) : [...prev, value]);
  };

  const selectAllVisible = () => {
    const ids = visibleModifiers.map((row) => String(row.id));
    setAssignedIds((prev) => Array.from(new Set([...prev, ...ids])));
  };

  const clearVisible = () => {
    const ids = new Set(visibleModifiers.map((row) => String(row.id)));
    setAssignedIds((prev) => prev.filter((id) => !ids.has(String(id))));
  };

  const saveProductConfiguration = async () => {
    if (!selectedProductId) {
      showMessage({ type: "error", title: "Select Product", message: "Select a finished good menu item first." });
      return;
    }

    try {
      setSavingConfig(true);
      const before = new Set(originalAssignedIds.current.map(String));
      const after = new Set(assignedIds.map(String));
      const addIds = [...after].filter((id) => !before.has(id));
      const removeIds = [...before].filter((id) => !after.has(id));

      await Promise.all(addIds.map((id) => assignModifierToProducts(id, [selectedProductId], { branch_id: branchId || null })));
      await Promise.all(removeIds.map((id) => removeModifierFromProduct(id, selectedProductId, { branch_id: branchId || null })));

      await Promise.all([loadModifiers(), loadProductAssignments(selectedProductId)]);
      showMessage({ type: "success", title: "Configuration Saved", message: `${selectedProduct?.name || "Menu item"} now has ${after.size} modifier(s).` });
    } catch (error) {
      await loadProductAssignments(selectedProductId).catch(() => {});
      showMessage({ type: "error", title: "Save Failed", message: getApiMessage(error) });
    } finally {
      setSavingConfig(false);
    }
  };

  const saveModifier = async () => {
    if (!modifierForm.name.trim()) {
      showMessage({ type: "error", title: "Validation Error", message: "Modifier name is required." });
      return;
    }

    try {
      setSavingModifier(true);
      const payload = {
        branch_id: modifierForm.branch_id || null,
        name: modifierForm.name.trim(),
        code: modifierForm.code.trim() || null,
        modifier_product_id: null,
        recipe_id: modifierForm.recipe_id || null,
        price: Number(modifierForm.price || 0),
        currency_code: modifierForm.currency_code || defaultCurrency,
        deduct_recipe_stock: Boolean(modifierForm.deduct_recipe_stock),
        print_on_kitchen: Boolean(modifierForm.print_on_kitchen),
        print_on_customer_receipt: Boolean(modifierForm.print_on_customer_receipt),
        is_active: Boolean(modifierForm.is_active),
      };

      const response = modifierForm.id
        ? await updateModifier(modifierForm.id, payload)
        : await createModifier(payload);

      const saved = response?.data || response?.data?.data || response;
      await loadModifiers();
      if (saved?.id) {
        setSelectedModifierId(saved.id);
        if (!modifierForm.id && selectedProductId) {
          setAssignedIds((prev) => Array.from(new Set([...prev, String(saved.id)])));
        }
      }
      setModifierFormOpen(false);
      showMessage({ type: "success", title: modifierForm.id ? "Modifier Updated" : "Modifier Created", message: getApiMessage(response, "Modifier saved successfully.") });
    } catch (error) {
      showMessage({ type: "error", title: "Save Failed", message: getApiMessage(error) });
    } finally {
      setSavingModifier(false);
    }
  };

  const deleteSelectedModifier = async (modifier = selectedModifier) => {
    if (!modifier) return;
    try {
      setDeletingId(modifier.id);
      const response = await deleteModifier(modifier.id);
      setAssignedIds((prev) => prev.filter((id) => id !== String(modifier.id)));
      originalAssignedIds.current = originalAssignedIds.current.filter((id) => id !== String(modifier.id));
      setSelectedModifierId(null);
      setModifierForm({ ...emptyModifierForm, currency_code: defaultCurrency });
      setModifierFormOpen(false);
      await loadModifiers();
      showMessage({ type: "success", title: "Modifier Deactivated", message: getApiMessage(response, "Modifier deactivated successfully.") });
    } catch (error) {
      showMessage({ type: "error", title: "Delete Failed", message: getApiMessage(error) });
    } finally {
      setDeletingId(null);
    }
  };

  const requestDeactivate = (modifier) => {
    if (!modifier) return;
    setSelectedModifierId(modifier.id);
    setConfirmAction({
      type: "deactivate",
      modifier,
      title: "Deactivate Modifier?",
      message: `${modifier.name} will be removed from every assigned menu item.`,
      confirmText: "Deactivate",
      danger: true,
    });
  };

  const confirmPendingAction = () => {
    const action = confirmAction;
    setConfirmAction(null);

    if (action?.type === "switch-product") {
      setSelectedProductId(action.product.id);
      return;
    }

    if (action?.type === "refresh-workspace") {
      reloadWorkspace();
      return;
    }

    if (action?.type === "close-workspace") {
      onClose?.();
      return;
    }

    if (action?.type === "deactivate") {
      deleteSelectedModifier(action.modifier);
    }
  };

  if (!open) return null;

  return (
    <>
      <FullScreenModal open={open} onClose={requestWorkspaceClose} title="MODIFIER CONFIGURATION" subtitle="Assign many pre-configured modifiers to each finished good menu item">
        <div className="menu-modifier-workspace h-full min-h-0 overflow-hidden bg-[#D8DFDF] text-[#1C2C2B] font-normal">
          <div className="grid h-full min-h-0 grid-rows-[auto_minmax(0,1fr)_auto] gap-2 p-2">
            <div className="min-w-0 shrink-0 overflow-x-auto">
              <div className="grid min-w-[980px] grid-cols-[280px_minmax(360px,1fr)_280px] gap-2">
              <DeskPanel title="Selected Finished Good">
                <div className="grid grid-cols-[86px_1fr] gap-1 text-[11px]">
                  <DeskLabel>Product</DeskLabel>
                  <DeskField value={selectedProduct?.name || "None"} />
                  <DeskLabel>Identity</DeskLabel>
                  <DeskField value={itemIdentity(selectedProduct)} />
                  <DeskLabel>Modifiers</DeskLabel>
                  <DeskField value={assignedIds.length} />
                </div>
              </DeskPanel>
              <DeskPanel title="Search Finished Goods">
                <div className="grid grid-cols-[90px_1fr] gap-1 text-[11px]">
                  <DeskLabel>Search</DeskLabel>
                  <input value={productSearch} onChange={(e) => setProductSearch(e.target.value)} className="desk-input" placeholder="Search menu item" />
                  <DeskLabel>Type</DeskLabel>
                  <DeskField value="finished_good only" />
                </div>
              </DeskPanel>
              <DeskPanel title="Modifier Search">
                <div className="grid grid-cols-[90px_1fr] gap-1 text-[11px]">
                  <DeskLabel>Search</DeskLabel>
                  <input value={modifierSearch} onChange={(e) => setModifierSearch(e.target.value)} className="desk-input" placeholder="Search modifiers" />
                  <DeskLabel>Status</DeskLabel>
                  <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="desk-input">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="all">All</option>
                  </select>
                </div>
              </DeskPanel>
              </div>
            </div>

            <div className="min-h-0 min-w-0 overflow-auto">
              <div className="grid h-full min-h-[440px] min-w-[1180px] grid-cols-[300px_minmax(840px,1fr)] grid-rows-[auto_minmax(0,1fr)] gap-2">
              <section className="row-span-2 flex min-h-0 min-w-0 flex-col overflow-hidden border border-[#AFB9B9] bg-white">
                <PanelHeader>
                  <div className="flex items-center justify-between gap-2">
                    <span>Menu Item Navigation</span>
                    <span className="text-[10px] font-normal text-[#627474]">{visibleProducts.length} items</span>
                  </div>
                </PanelHeader>
                <div className="min-h-0 flex-1 overflow-auto">
                  {loading ? <Empty message="Loading products..." /> : visibleProducts.length === 0 ? <Empty message="No finished good menu items found." /> : visibleProducts.map((product) => {
                    const active = String(product.id) === String(selectedProductId);
                    return (
                      <button key={product.id} type="button" onClick={() => selectProduct(product)} className={`block w-full border-b border-[#EFF2F2] px-2 py-2 text-left text-[11px] ${active ? "bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                        <div className="font-semibold text-[#0C1B1B]">{product.name}</div>
                        <div className="text-[10px] text-[#627474]">{itemIdentity(product)}</div>
                      </button>
                    );
                  })}
                </div>
              </section>

              <section className="col-start-2 row-start-2 flex min-h-0 min-w-0 flex-col overflow-hidden border border-[#AFB9B9] bg-white">
                <PanelHeader>
                  <div className="flex items-center justify-between gap-2">
                    <span>Modifier Register &amp; Assignment</span>
                    <span className="text-[10px] font-normal text-[#627474]">{assignedIds.length} selected</span>
                  </div>
                </PanelHeader>
                <div className="flex min-h-0 flex-1 flex-col">
                  <div className="flex shrink-0 flex-wrap gap-1 border-b border-[#D8DFDF] bg-[#F8FAFC] p-2">
                    <DeskTool onClick={selectAllVisible} disabled={loading || assignmentLoading}>Select Visible</DeskTool>
                    <DeskTool onClick={clearVisible} disabled={loading || assignmentLoading}>Clear Visible</DeskTool>
                    <DeskTool onClick={() => setAssignedIds([])} disabled={loading || assignmentLoading}>Clear All</DeskTool>
                    <DeskTool green onClick={saveProductConfiguration} disabled={savingConfig || assignmentLoading || !selectedProductId}>{savingConfig ? "Saving..." : assignmentLoading ? "Loading..." : "Save Product Config"}</DeskTool>
                  </div>
                  <div className="min-h-0 flex-1 overflow-auto">
                    <div className="min-w-[820px]">
                    <div className="sx-enterprise-grid-head sticky top-0 z-10 grid grid-cols-[46px_minmax(340px,1fr)_130px_140px_120px_64px]">
                      <div className="sx-enterprise-grid-heading" aria-label="Assign" />
                      <div className="sx-enterprise-grid-heading">Modifier</div>
                      <div className="sx-enterprise-grid-heading">Code</div>
                      <div className="sx-enterprise-grid-heading">Price</div>
                      <div className="sx-enterprise-grid-heading">Status</div>
                      <div className="sx-enterprise-grid-heading text-center">⋮</div>
                    </div>
                    {loading ? <Empty message="Loading modifiers..." /> : assignmentLoading ? <Empty message="Loading product assignments..." /> : visibleModifiers.length === 0 ? <Empty message="No modifiers found." /> : visibleModifiers.map((row) => {
                      const checked = assignedIds.includes(String(row.id));
                      return (
                        <div key={row.id} onClick={() => setSelectedModifierId(row.id)} className={`sx-enterprise-grid-row grid cursor-pointer grid-cols-[46px_minmax(340px,1fr)_130px_140px_120px_64px] border-b border-[#EFF2F2] text-[11px] ${String(selectedModifierId) === String(row.id) ? "sx-enterprise-grid-selected" : checked ? "bg-[#ECFDF3]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                          <label className="sx-enterprise-grid-cell flex items-center justify-center border-r border-[#EFF2F2]" onClick={(event) => event.stopPropagation()}><input type="checkbox" aria-label={`Assign ${row.name}`} checked={checked} onChange={() => toggleAssigned(row.id)} /></label>
                          <div className="sx-enterprise-grid-cell min-w-0 px-2 py-2 text-left">
                            <div className="font-semibold text-[#0C1B1B]">{row.name}</div>
                            <div className="text-[10px] text-[#627474]">Recipe: {recipes.find((recipe) => String(recipe.id) === String(row.recipe_id))?.name || "No recipe linked"}</div>
                          </div>
                          <div className="sx-enterprise-grid-cell border-l border-[#EFF2F2] px-2 py-2">{row.code || "-"}</div>
                          <div className="sx-enterprise-grid-cell border-l border-[#EFF2F2] px-2 py-2">{money(row.price, row.currency_code || defaultCurrency)}</div>
                          <div className="sx-enterprise-grid-cell border-l border-[#EFF2F2] px-2 py-2"><Status active={row.is_active !== false} /></div>
                          <div className="sx-enterprise-grid-cell flex items-center justify-center border-l border-[#EFF2F2] px-1 py-1">
                            <RowActionMenu
                              label={`Actions for ${row.name}`}
                              actions={[
                                { label: "Edit", onClick: () => editModifier(row) },
                                { label: "Deactivate", danger: true, disabled: deletingId === row.id, onClick: () => requestDeactivate(row) },
                              ]}
                            />
                          </div>
                        </div>
                      );
                    })}
                    </div>
                  </div>
                </div>
              </section>

              <aside className="col-start-2 row-start-1 flex min-w-0 flex-col overflow-hidden border border-[#AFB9B9] bg-white">
                <PanelHeader>Selected Modifier &amp; Workflow</PanelHeader>
                <div className="grid min-w-[820px] grid-cols-[minmax(240px,.85fr)_minmax(300px,1fr)_minmax(320px,1.15fr)] gap-2 p-2 text-[11px]">
                  <div className="space-y-2">
                    <div className="grid grid-cols-2 gap-1">
                      <DeskTool green onClick={newModifier}>New Modifier</DeskTool>
                      <DeskTool onClick={() => selectedModifier && editModifier(selectedModifier)} disabled={!selectedModifier}>Edit Selected</DeskTool>
                    </div>
                    <div className="border border-[#D8DFDF] bg-[#F8FAFC] p-2">
                      <div className="mb-1 text-[10px] font-semibold uppercase text-[#465757]">Selected modifier</div>
                      <div className="font-semibold text-[#0C1B1B]">{selectedModifier?.name || "No modifier selected"}</div>
                      <div className="mt-1 text-[#627474]">{selectedModifier?.code || "No code"} · {selectedModifier ? money(selectedModifier.price, selectedModifier.currency_code || defaultCurrency) : "No price"}</div>
                      <div className="mt-1 text-[#627474]">Recipe: {selectedRecipe?.name || "No recipe linked"}</div>
                    </div>
                  </div>
                  <div className="border border-[#A4E5E4] bg-[#F1F8F8] p-3 text-[11px] leading-5 text-[#057B7B]">
                    Select a menu item on the left, tick its modifiers in the register, then save the product configuration. New and edit forms always open in a centered modal. Linked recipes deduct FRONT stock during sale.
                  </div>
                  <div className="max-h-[128px] overflow-auto border border-[#D8DFDF] bg-[#F8FAFC] p-2">
                    <div className="sticky top-0 mb-1 bg-[#F8FAFC] text-[10px] font-semibold uppercase text-[#465757]">Currently assigned to {selectedProduct?.name || "selected product"}</div>
                    {selectedAssigned.length ? selectedAssigned.map((row) => <div key={row.id} className="flex justify-between gap-2 border-b border-[#E2E9E9] py-1 text-[11px] last:border-0"><span>{row.name}</span><span>{money(row.price, row.currency_code || defaultCurrency)}</span></div>) : <div className="text-[11px] text-[#627474]">No modifiers assigned.</div>}
                  </div>
                </div>
              </aside>
              </div>
            </div>

            <div className="flex shrink-0 items-center justify-between border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
              <div className="text-[11px] text-[#465757]">A finished good can have many modifiers. Save Product Config after ticking all required modifiers.</div>
              <div className="flex gap-1">
                <DeskTool onClick={requestWorkspaceRefresh} disabled={loading || assignmentLoading}>{loading || assignmentLoading ? "Loading..." : "Refresh"}</DeskTool>
                <DeskTool green onClick={saveProductConfiguration} disabled={savingConfig || assignmentLoading || !selectedProductId}>{savingConfig ? "Saving..." : assignmentLoading ? "Loading..." : "Save Product Config"}</DeskTool>
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>
      <EnterpriseFormModal
        open={modifierFormOpen}
        title={modifierForm.id ? "Edit Modifier" : "New Modifier"}
        subtitle="Modifier master and recipe stock deduction"
        onClose={() => !savingModifier && setModifierFormOpen(false)}
        busy={savingModifier}
        width="760px"
      >
        <form onSubmit={(event) => { event.preventDefault(); saveModifier(); }} className="modifier-form space-y-3 p-4">
          <Field label="Modifier Name"><input value={modifierForm.name} onChange={(e) => updateModifierForm("name", e.target.value)} className="desk-input" placeholder="Example: EXTRA CHEESE" autoFocus /></Field>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Code"><input value={modifierForm.code} onChange={(e) => updateModifierForm("code", e.target.value.toUpperCase())} className="desk-input" placeholder="Optional" /></Field>
            <Field label="Price"><input type="number" step="0.0001" value={modifierForm.price} onChange={(e) => updateModifierForm("price", e.target.value)} className="desk-input" /></Field>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Currency">
              <select value={modifierForm.currency_code || defaultCurrency} onChange={(e) => updateModifierForm("currency_code", e.target.value)} className="desk-input">
                {(currencies.length ? currencies : [{ code: "USD", name: "USD" }]).map((currency) => <option key={currency.id || currency.code} value={currency.code}>{currency.code} - {currency.name || currency.code}</option>)}
              </select>
            </Field>
            <Field label="Modifier Selling Product"><input value="None - modifiers are not POS selling products" readOnly disabled className="desk-input disabled:bg-[#EEF2F2]" /></Field>
          </div>
          <Field label="Recipe For Modifier Stock Deduction">
            <select value={modifierForm.recipe_id} onChange={(e) => updateModifierForm("recipe_id", e.target.value)} className="desk-input">
              <option value="">No recipe linked</option>
              {visibleRecipes.map((recipe) => <option key={recipe.id} value={recipe.id}>{recipe.name} {recipe.menu_product?.name || recipe.menuProduct?.name || recipe.menu_product_name ? `- ${recipe.menu_product?.name || recipe.menuProduct?.name || recipe.menu_product_name}` : "- Unlinked"}</option>)}
            </select>
          </Field>
          <Field label="Find Recipe"><input value={recipeSearch} onChange={(e) => setRecipeSearch(e.target.value)} className="desk-input" placeholder="Search recipes" /></Field>
          <DeskField value={selectedRecipe ? `${selectedRecipe.name}${selectedRecipe.menu_product?.name || selectedRecipe.menuProduct?.name || selectedRecipe.menu_product_name ? ` - ${selectedRecipe.menu_product?.name || selectedRecipe.menuProduct?.name || selectedRecipe.menu_product_name}` : " - Unlinked"}` : "No recipe selected"} />
          <div className="grid grid-cols-2 gap-2">
            <Check checked={modifierForm.print_on_kitchen} onChange={(v) => updateModifierForm("print_on_kitchen", v)}>Print kitchen</Check>
            <Check checked={modifierForm.print_on_customer_receipt} onChange={(v) => updateModifierForm("print_on_customer_receipt", v)}>Print receipt</Check>
            <Check checked={modifierForm.deduct_recipe_stock} onChange={(v) => updateModifierForm("deduct_recipe_stock", v)}>Deduct recipe stock</Check>
            <Check checked={modifierForm.is_active} onChange={(v) => updateModifierForm("is_active", v)}>Active</Check>
          </div>
          <div className="flex justify-between gap-2 border-t border-[#D8DFDF] pt-3">
            <DeskTool danger onClick={() => requestDeactivate(selectedModifier)} disabled={!selectedModifier || deletingId === selectedModifier?.id}>{deletingId ? "Deleting..." : "Deactivate"}</DeskTool>
            <div className="flex gap-2">
              <DeskTool onClick={() => setModifierFormOpen(false)} disabled={savingModifier}>Cancel</DeskTool>
              <DeskTool green onClick={saveModifier} disabled={savingModifier}>{savingModifier ? "Saving..." : "Save Modifier"}</DeskTool>
            </div>
          </div>
        </form>
      </EnterpriseFormModal>
      <ConfirmActionModal
        open={!!confirmAction}
        title={confirmAction?.title}
        message={confirmAction?.message}
        confirmText={confirmAction?.confirmText}
        danger={confirmAction?.danger}
        busy={!!deletingId}
        onClose={() => !deletingId && setConfirmAction(null)}
        onConfirm={confirmPendingAction}
      />
      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={closeMessage} />
      <InlineStyles />
    </>
  );
}

function DeskPanel({ title, children }) { return <div className="border border-[#AFB9B9] bg-white"><div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">{title}</div><div className="p-2">{children}</div></div>; }
function PanelHeader({ children }) { return <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-2 text-[12px] font-semibold text-[#324443]">{children}</div>; }
function DeskLabel({ children }) { return <div className="text-[11px] text-[#465757]">{children}</div>; }
function DeskField({ value }) { return <input value={value ?? ""} readOnly disabled className="desk-input disabled:bg-[#EEF2F2]" />; }
function DeskTool({ children, onClick, disabled, green, danger }) { let cls = "border-[#B9C3C3] bg-white text-[#324443]"; if (green) cls = "border-[#027A48] bg-[#027A48] text-white"; if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]"; return <button type="button" disabled={disabled} onClick={onClick} className={`h-7 border px-3 text-[11px] font-semibold disabled:opacity-50 ${cls}`}>{children}</button>; }
function Empty({ message }) { return <div className="flex h-[220px] items-center justify-center text-[12px] text-[#627474]">{message}</div>; }
function Status({ active }) { return <span className={`inline-flex border px-2 py-1 text-[10px] font-semibold ${active ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]" : "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"}`}>{active ? "Active" : "Inactive"}</span>; }
function Field({ label, children }) { return <label className="block"><div className="mb-1 text-[10px] text-[#465757]">{label}</div>{children}</label>; }
function Check({ checked, onChange, children }) { return <label className="flex min-h-[32px] items-center gap-2 border border-[#CBD6D6] bg-[#F8FAFC] px-2 text-[11px]"><input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />{children}</label>; }
function RowActionMenu({ label, actions = [] }) {
  const triggerRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });

  const showMenu = () => {
    if (open) {
      setOpen(false);
      return;
    }

    const rect = triggerRef.current?.getBoundingClientRect();
    if (!rect) return;

    const width = 164;
    const height = Math.max(42, actions.length * 34 + 8);
    const left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width));
    const top = rect.bottom + height + 8 <= window.innerHeight
      ? rect.bottom + 4
      : Math.max(8, rect.top - height - 4);
    setPosition({ top, left });
    setOpen(true);
  };

  useEffect(() => {
    if (!open) return undefined;
    const close = () => setOpen(false);
    const onKeyDown = (event) => {
      if (event.key === "Escape") close();
    };
    window.addEventListener("resize", close);
    window.addEventListener("scroll", close, true);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      window.removeEventListener("resize", close);
      window.removeEventListener("scroll", close, true);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={(event) => {
          event.stopPropagation();
          showMenu();
        }}
        className="flex h-7 w-7 items-center justify-center border border-transparent bg-white text-[20px] font-bold leading-none text-[#324443] hover:border-[#069494] hover:bg-[#F3F8F8]"
      >
        ⋮
      </button>
      {open && createPortal(
        <div className="fixed inset-0 z-[100005]" onMouseDown={() => setOpen(false)}>
          <div
            role="menu"
            aria-label={label}
            className="fixed w-[164px] border border-[#96A5A4] bg-white p-1 text-left shadow-xl"
            style={position}
            onMouseDown={(event) => event.stopPropagation()}
          >
            {actions.filter(Boolean).map((action) => (
              <button
                key={action.label}
                type="button"
                role="menuitem"
                disabled={action.disabled}
                onClick={() => {
                  setOpen(false);
                  action.onClick?.();
                }}
                className={`block h-8 w-full px-2 text-left text-[10px] font-semibold hover:bg-[#F2F4F7] disabled:opacity-50 ${action.danger ? "text-[#B42318]" : "text-[#324443]"}`}
              >
                {action.label}
              </button>
            ))}
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}

function ConfirmActionModal({ open, title, message, confirmText = "Continue", danger = false, busy = false, onClose, onConfirm }) {
  return <EnterpriseConfirmModal open={open} title={title} message={message} confirmLabel={confirmText} danger={danger} busy={busy} onClose={onClose} onConfirm={onConfirm} />;
}

function InlineStyles() { return <style>{`
  .menu-modifier-workspace .desk-input,
  .modifier-form .desk-input { height:1.75rem;border:1px solid #B9C3C3;background:white;padding:0 .5rem;font-size:11px;outline:none;border-radius:0!important;width:100%; }
  .menu-modifier-workspace .desk-input:focus,
  .modifier-form .desk-input:focus { border-color:#069495;box-shadow:inset 0 0 0 1px #93F5F4; }
  .menu-modifier-workspace .modifier-master-scroll { scrollbar-width:thin;scrollbar-color:#93A6A6 #F1F5F5; }
  .menu-modifier-workspace .modifier-master-scroll::-webkit-scrollbar { width:9px; }
  .menu-modifier-workspace .modifier-master-scroll::-webkit-scrollbar-track { background:#F1F5F5;border-left:1px solid #E2E9E9; }
  .menu-modifier-workspace .modifier-master-scroll::-webkit-scrollbar-thumb { background:#93A6A6;border:2px solid #F1F5F5; }
`}</style>; }

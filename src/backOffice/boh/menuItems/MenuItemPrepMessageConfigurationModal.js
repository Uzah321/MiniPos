import React, { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";

import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import {
  assignPrepMessageToProducts,
  createPrepMessage,
  deletePrepMessage,
  getFastFoodProductOptions,
  getFinishedGoodMenuItemsForConfiguration,
  getPrepMessages,
  removePrepMessageFromProduct,
  updatePrepMessage,
} from "../../../services/FastFoodPrepModifierService";

const emptyMessageForm = {
  id: null,
  branch_id: "",
  message: "",
  code: "",
  allow_free_text: false,
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

export default function MenuItemPrepMessageConfigurationModal({
  open,
  onClose,
  currentUser = null,
}) {
  const [products, setProducts] = useState([]);
  const [prepMessages, setPrepMessages] = useState([]);
  const [selectedProductId, setSelectedProductId] = useState("");
  const [assignedIds, setAssignedIds] = useState([]);
  const originalAssignedIds = useRef([]);

  const [messageForm, setMessageForm] = useState(emptyMessageForm);
  const [selectedMessageId, setSelectedMessageId] = useState(null);
  const [messageFormOpen, setMessageFormOpen] = useState(false);

  const [productSearch, setProductSearch] = useState("");
  const [messageSearch, setMessageSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("active");
  const [loading, setLoading] = useState(false);
  const [assignmentLoading, setAssignmentLoading] = useState(false);
  const [savingConfig, setSavingConfig] = useState(false);
  const [savingMessage, setSavingMessage] = useState(false);
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

  const showMessage = ({ type = "success", title = "Notice", message = "" }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessage = () => setMessageModal((prev) => ({ ...prev, open: false }));

  const selectedProduct = useMemo(
    () => products.find((product) => String(product.id) === String(selectedProductId)) || null,
    [products, selectedProductId],
  );

  const selectedMessage = useMemo(
    () => prepMessages.find((row) => String(row.id) === String(selectedMessageId)) || null,
    [prepMessages, selectedMessageId],
  );

  const visibleProducts = useMemo(() => {
    const q = productSearch.trim().toLowerCase();
    if (!q) return products;
    return products.filter((product) => [product.name, product.code, product.sku, product.barcode]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(q)));
  }, [products, productSearch]);

  const visiblePrepMessages = useMemo(() => {
    const q = messageSearch.trim().toLowerCase();
    return prepMessages.filter((row) => {
      const statusOk = statusFilter === "all" || (statusFilter === "active" ? row.is_active !== false : row.is_active === false);
      const searchOk = !q || [row.message, row.code]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(q));
      return statusOk && searchOk;
    });
  }, [prepMessages, messageSearch, statusFilter]);

  const selectedAssigned = useMemo(
    () => prepMessages.filter((row) => assignedIds.includes(String(row.id))),
    [prepMessages, assignedIds],
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

  const loadPrepMessages = async () => {
    const response = await getPrepMessages({
      per_page: 1000,
      branch_id: branchId || undefined,
    });
    setPrepMessages(normalizeRows(response));
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
      const ids = normalizeRows(options?.prep_messages)
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
      await Promise.all([loadProducts(), loadPrepMessages()]);
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
        message: `Refreshing will discard the unsaved prep-message changes for ${selectedProduct?.name || "the selected menu item"}.`,
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
        message: `Closing will discard the unsaved prep-message changes for ${selectedProduct?.name || "the selected menu item"}.`,
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
    if (!selectedMessage) return;
    setMessageForm({
      id: selectedMessage.id,
      branch_id: selectedMessage.branch_id || "",
      message: selectedMessage.message || "",
      code: selectedMessage.code || "",
      allow_free_text: Boolean(selectedMessage.allow_free_text),
      is_active: selectedMessage.is_active !== false,
    });
  }, [selectedMessage]);

  const updateMessageForm = (field, value) => {
    setMessageForm((prev) => ({ ...prev, [field]: value }));
  };

  const newMessage = () => {
    setSelectedMessageId(null);
    setMessageForm({ ...emptyMessageForm, branch_id: branchId });
    setMessageFormOpen(true);
  };

  const editMessage = (row) => {
    setSelectedMessageId(row.id);
    setMessageForm({
      id: row.id,
      branch_id: row.branch_id || "",
      message: row.message || "",
      code: row.code || "",
      allow_free_text: Boolean(row.allow_free_text),
      is_active: row.is_active !== false,
    });
    setMessageFormOpen(true);
  };

  const selectProduct = (product) => {
    if (!product || String(product.id) === String(selectedProductId)) return;

    if (assignmentsDirty) {
      setConfirmAction({
        type: "switch-product",
        product,
        title: "Discard Unsaved Assignment Changes?",
        message: `The prep-message changes for ${selectedProduct?.name || "the selected menu item"} have not been saved. Continue to ${product.name}?`,
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
    const ids = visiblePrepMessages.map((row) => String(row.id));
    setAssignedIds((prev) => Array.from(new Set([...prev, ...ids])));
  };

  const clearVisible = () => {
    const ids = new Set(visiblePrepMessages.map((row) => String(row.id)));
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

      await Promise.all(addIds.map((id) => assignPrepMessageToProducts(id, [selectedProductId], { branch_id: branchId || null })));
      await Promise.all(removeIds.map((id) => removePrepMessageFromProduct(id, selectedProductId, { branch_id: branchId || null })));

      await Promise.all([loadPrepMessages(), loadProductAssignments(selectedProductId)]);
      showMessage({ type: "success", title: "Configuration Saved", message: `${selectedProduct?.name || "Menu item"} now has ${after.size} prep message(s).` });
    } catch (error) {
      await loadProductAssignments(selectedProductId).catch(() => {});
      showMessage({ type: "error", title: "Save Failed", message: getApiMessage(error) });
    } finally {
      setSavingConfig(false);
    }
  };

  const saveMessage = async () => {
    if (!messageForm.message.trim()) {
      showMessage({ type: "error", title: "Validation Error", message: "Prep message is required." });
      return;
    }

    try {
      setSavingMessage(true);
      const payload = {
        branch_id: messageForm.branch_id || null,
        message: messageForm.message.trim(),
        code: messageForm.code.trim() || null,
        allow_free_text: Boolean(messageForm.allow_free_text),
        is_active: Boolean(messageForm.is_active),
      };

      const response = messageForm.id
        ? await updatePrepMessage(messageForm.id, payload)
        : await createPrepMessage(payload);

      const saved = response?.data || response?.data?.data || response;
      await loadPrepMessages();
      if (saved?.id) {
        setSelectedMessageId(saved.id);
        if (!messageForm.id && selectedProductId) {
          setAssignedIds((prev) => Array.from(new Set([...prev, String(saved.id)])));
        }
      }
      setMessageFormOpen(false);
      showMessage({ type: "success", title: messageForm.id ? "Prep Message Updated" : "Prep Message Created", message: getApiMessage(response, "Prep message saved successfully.") });
    } catch (error) {
      showMessage({ type: "error", title: "Save Failed", message: getApiMessage(error) });
    } finally {
      setSavingMessage(false);
    }
  };

  const deleteMessage = async (message = selectedMessage) => {
    if (!message) return;
    try {
      setDeletingId(message.id);
      const response = await deletePrepMessage(message.id);
      setAssignedIds((prev) => prev.filter((id) => id !== String(message.id)));
      originalAssignedIds.current = originalAssignedIds.current.filter((id) => id !== String(message.id));
      setSelectedMessageId(null);
      setMessageForm(emptyMessageForm);
      setMessageFormOpen(false);
      await loadPrepMessages();
      showMessage({ type: "success", title: "Prep Message Deactivated", message: getApiMessage(response, "Prep message deactivated successfully.") });
    } catch (error) {
      showMessage({ type: "error", title: "Delete Failed", message: getApiMessage(error) });
    } finally {
      setDeletingId(null);
    }
  };

  const requestDeactivate = (message) => {
    if (!message) return;
    setSelectedMessageId(message.id);
    setConfirmAction({
      type: "deactivate",
      messageRecord: message,
      title: "Deactivate Prep Message?",
      message: `${message.message} will be removed from every assigned menu item.`,
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
      deleteMessage(action.messageRecord);
    }
  };

  if (!open) return null;

  return (
    <>
      <FullScreenModal open={open} onClose={requestWorkspaceClose} title="PREP MESSAGE CONFIGURATION" subtitle="Assign many prep messages to each finished good menu item">
        <div className="menu-prep-workspace h-full min-h-0 overflow-hidden bg-[#D8DFDF] text-[#1C2C2B] font-normal">
          <div className="grid h-full min-h-0 grid-rows-[auto_minmax(0,1fr)_auto] gap-2 p-2">
            <div className="min-w-0 shrink-0 overflow-x-auto">
              <div className="grid min-w-[980px] gap-2 grid-cols-[280px_minmax(360px,1fr)_280px]">
              <DeskPanel title="Selected Finished Good">
                <div className="grid grid-cols-[86px_1fr] gap-1 text-[11px]">
                  <DeskLabel>Product</DeskLabel>
                  <DeskField value={selectedProduct?.name || "None"} />
                  <DeskLabel>Identity</DeskLabel>
                  <DeskField value={itemIdentity(selectedProduct)} />
                  <DeskLabel>Assigned</DeskLabel>
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
              <DeskPanel title="Prep Message Search">
                <div className="grid grid-cols-[90px_1fr] gap-1 text-[11px]">
                  <DeskLabel>Search</DeskLabel>
                  <input value={messageSearch} onChange={(e) => setMessageSearch(e.target.value)} className="desk-input" placeholder="Search prep messages" />
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
              <div className="grid h-full min-h-[440px] min-w-[1120px] grid-cols-[300px_minmax(780px,1fr)] grid-rows-[auto_minmax(0,1fr)] gap-2">
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
                    <span>Prep Message Register &amp; Assignment</span>
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
                    <div className="min-w-[720px]">
                    <div className="sx-enterprise-grid-head sticky top-0 z-10 grid grid-cols-[46px_minmax(320px,1fr)_140px_120px_64px]">
                      <div className="sx-enterprise-grid-heading" aria-label="Assign" />
                      <div className="sx-enterprise-grid-heading">Prep Message</div>
                      <div className="sx-enterprise-grid-heading">Code</div>
                      <div className="sx-enterprise-grid-heading">Status</div>
                      <div className="sx-enterprise-grid-heading text-center">⋮</div>
                    </div>
                    {loading ? <Empty message="Loading prep messages..." /> : assignmentLoading ? <Empty message="Loading product assignments..." /> : visiblePrepMessages.length === 0 ? <Empty message="No prep messages found." /> : visiblePrepMessages.map((row) => {
                      const checked = assignedIds.includes(String(row.id));
                      return (
                        <div key={row.id} onClick={() => setSelectedMessageId(row.id)} className={`sx-enterprise-grid-row grid cursor-pointer grid-cols-[46px_minmax(320px,1fr)_140px_120px_64px] border-b border-[#EFF2F2] text-[11px] ${String(selectedMessageId) === String(row.id) ? "sx-enterprise-grid-selected" : checked ? "bg-[#ECFDF3]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                          <label className="sx-enterprise-grid-cell flex items-center justify-center border-r border-[#EFF2F2]" onClick={(event) => event.stopPropagation()}><input type="checkbox" aria-label={`Assign ${row.message}`} checked={checked} onChange={() => toggleAssigned(row.id)} /></label>
                          <div className="sx-enterprise-grid-cell min-w-0 px-2 py-2 text-left">
                            <div className="font-semibold text-[#0C1B1B]">{row.message}</div>
                            <div className="text-[10px] text-[#627474]">{row.allow_free_text ? "Allows free text" : "Preset message"}</div>
                          </div>
                          <div className="sx-enterprise-grid-cell border-l border-[#EFF2F2] px-2 py-2">{row.code || "-"}</div>
                          <div className="sx-enterprise-grid-cell border-l border-[#EFF2F2] px-2 py-2"><Status active={row.is_active !== false} /></div>
                          <div className="sx-enterprise-grid-cell flex items-center justify-center border-l border-[#EFF2F2] px-1 py-1">
                            <RowActionMenu
                              label={`Actions for ${row.message}`}
                              actions={[
                                { label: "Edit", onClick: () => editMessage(row) },
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
                <PanelHeader>Selected Prep Message &amp; Workflow</PanelHeader>
                <div className="grid min-w-[760px] grid-cols-[minmax(220px,.8fr)_minmax(260px,1fr)_minmax(300px,1.15fr)] gap-2 p-2 text-[11px]">
                  <div className="space-y-2">
                    <div className="grid grid-cols-2 gap-1">
                      <DeskTool green onClick={newMessage}>New Prep Message</DeskTool>
                      <DeskTool onClick={() => selectedMessage && editMessage(selectedMessage)} disabled={!selectedMessage}>Edit Selected</DeskTool>
                    </div>
                    <div className="border border-[#D8DFDF] bg-[#F8FAFC] p-2">
                      <div className="mb-1 text-[10px] font-semibold uppercase text-[#465757]">Selected message</div>
                      <div className="font-semibold text-[#0C1B1B]">{selectedMessage?.message || "No prep message selected"}</div>
                      <div className="mt-1 text-[#627474]">{selectedMessage?.code || "No code"} · {selectedMessage?.is_active === false ? "Inactive" : "Active"}</div>
                    </div>
                  </div>
                  <div className="border border-[#A4E5E4] bg-[#F1F8F8] p-3 text-[11px] leading-5 text-[#057B7B]">
                    Select a menu item on the left, tick its prep messages in the register, then save the product configuration. New and edit forms always open in a centered modal.
                  </div>
                  <div className="max-h-[112px] overflow-auto border border-[#D8DFDF] bg-[#F8FAFC] p-2">
                    <div className="sticky top-0 mb-1 bg-[#F8FAFC] text-[10px] font-semibold uppercase text-[#465757]">Currently assigned to {selectedProduct?.name || "selected product"}</div>
                    {selectedAssigned.length ? selectedAssigned.map((row) => <div key={row.id} className="border-b border-[#E2E9E9] py-1 text-[11px] last:border-0">{row.message}</div>) : <div className="text-[11px] text-[#627474]">No prep messages assigned.</div>}
                  </div>
                </div>
              </aside>
              </div>
            </div>

            <div className="flex shrink-0 items-center justify-between border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
              <div className="text-[11px] text-[#465757]">A finished good can have many prep messages. Save Product Config after ticking all required messages.</div>
              <div className="flex gap-1">
                <DeskTool onClick={requestWorkspaceRefresh} disabled={loading || assignmentLoading}>{loading || assignmentLoading ? "Loading..." : "Refresh"}</DeskTool>
                <DeskTool green onClick={saveProductConfiguration} disabled={savingConfig || assignmentLoading || !selectedProductId}>{savingConfig ? "Saving..." : assignmentLoading ? "Loading..." : "Save Product Config"}</DeskTool>
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>
      <EnterpriseFormModal
        open={messageFormOpen}
        title={messageForm.id ? "Edit Prep Message" : "New Prep Message"}
        subtitle="Prep message master"
        onClose={() => !savingMessage && setMessageFormOpen(false)}
        busy={savingMessage}
        width="560px"
      >
        <form onSubmit={(event) => { event.preventDefault(); saveMessage(); }} className="prep-message-form space-y-3 p-4">
          <Field label="Message"><input value={messageForm.message} onChange={(e) => updateMessageForm("message", e.target.value)} className="desk-input" placeholder="Example: NO SALT" autoFocus /></Field>
          <Field label="Code"><input value={messageForm.code} onChange={(e) => updateMessageForm("code", e.target.value.toUpperCase())} className="desk-input" placeholder="Optional" /></Field>
          <div className="grid grid-cols-2 gap-2">
            <Check checked={messageForm.allow_free_text} onChange={(v) => updateMessageForm("allow_free_text", v)}>Allow free text</Check>
            <Check checked={messageForm.is_active} onChange={(v) => updateMessageForm("is_active", v)}>Active</Check>
          </div>
          <div className="flex justify-between gap-2 border-t border-[#D8DFDF] pt-3">
            <DeskTool danger onClick={() => requestDeactivate(selectedMessage)} disabled={!selectedMessage || deletingId === selectedMessage?.id}>{deletingId ? "Deleting..." : "Deactivate"}</DeskTool>
            <div className="flex gap-2">
              <DeskTool onClick={() => setMessageFormOpen(false)} disabled={savingMessage}>Cancel</DeskTool>
              <DeskTool green onClick={saveMessage} disabled={savingMessage}>{savingMessage ? "Saving..." : "Save Message"}</DeskTool>
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
  .menu-prep-workspace .desk-input,
  .prep-message-form .desk-input { height:1.75rem;border:1px solid #B9C3C3;background:white;padding:0 .5rem;font-size:11px;outline:none;border-radius:0!important;width:100%; }
  .menu-prep-workspace .desk-input:focus,
  .prep-message-form .desk-input:focus { border-color:#069495;box-shadow:inset 0 0 0 1px #93F5F4; }
`}</style>; }

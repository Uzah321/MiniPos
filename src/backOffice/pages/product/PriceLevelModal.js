// src/backOffice/pages/product/PriceLevelModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";

import {
  getPriceLevels,
  createPriceLevel,
  updatePriceLevel,
  deletePriceLevel,
} from "../../../services/ProductService";

const emptyForm = {
  id: null,
  name: "",
  code: "",
  description: "",
  is_default: false,
  is_active: true,
};

const PriceLevelModal = ({ open, onClose }) => {
  const [priceLevels, setPriceLevels] = useState([]);
  const [formData, setFormData] = useState(emptyForm);
  const [formOpen, setFormOpen] = useState(false);

  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.error) return responseData.error;
    if (responseData?.message) return responseData.message;

    if (source?.errors && typeof source.errors === "object") {
      return Object.values(source.errors).flat().join("\n");
    }

    if (source?.data?.error) return source.data.error;
    if (source?.data?.message) return source.data.message;

    if (source?.message && !source?.isAxiosError) return source.message;

    return fallback;
  };

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({
      open: true,
      type,
      title,
      message,
    });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({
      ...prev,
      open: false,
    }));
  };

  const normalizeList = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;

    return [];
  };

  const loadPriceLevels = async () => {
    try {
      setLoading(true);

      const response = await getPriceLevels({
        q: search.trim() || undefined,
      });

      setPriceLevels(normalizeList(response));
    } catch (error) {
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      setFormData(emptyForm);
      setSearch("");
      loadPriceLevels();
    }
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadPriceLevels();
    }, 350);

    return () => clearTimeout(timer);
  }, [search]);

  const filteredPriceLevels = useMemo(() => {
    const q = search.toLowerCase();

    return priceLevels.filter((level) => {
      return (
        level?.name?.toLowerCase().includes(q) ||
        level?.code?.toLowerCase().includes(q) ||
        level?.description?.toLowerCase().includes(q)
      );
    });
  }, [priceLevels, search]);

  const handleClear = () => {
    setFormData(emptyForm);
  };

  const handleEdit = (level) => {
    setFormData({
      id: level.id,
      name: level.name || "",
      code: level.code || "",
      description: level.description || "",
      is_default: !!level.is_default,
      is_active: level.is_active ?? true,
    });
    setFormOpen(true);
  };

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target;

    setFormData((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const validateForm = () => {
    if (!formData.name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Price level name is required.",
      });

      return false;
    }

    return true;
  };

  const buildPayload = () => ({
    name: formData.name.trim(),
    code: formData.code.trim() || null,
    description: formData.description.trim() || null,
    is_default: !!formData.is_default,
    is_active: !!formData.is_active,
  });

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) return;

    try {
      setSaving(true);

      const payload = buildPayload();

      const response = isEditing
        ? await updatePriceLevel(formData.id, payload)
        : await createPriceLevel(payload);

      showMessage({
        type: "success",
        title: isEditing ? "Price Level Updated" : "Price Level Created",
        message: getApiMessage(
          response,
          isEditing
            ? "Price level updated successfully."
            : "Price level created successfully."
        ),
      });

      handleClear();
      setFormOpen(false);
      await loadPriceLevels();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (level) => {
    const ok = await requestEnterpriseConfirmation({
      title: "Delete Price Level",
      message: `Delete price level "${level.name}"?`,
      confirmLabel: "Delete price level",
    });
    if (!ok) return;

    try {
      setDeletingId(level.id);

      const response = await deletePriceLevel(level.id);

      showMessage({
        type: "success",
        title: "Price Level Deleted",
        message: getApiMessage(response, "Price level deleted successfully."),
      });

      if (formData.id === level.id) handleClear();

      await loadPriceLevels();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="PRICE LEVELS"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#DFE5E5] overflow-hidden">
          <div className="h-full">
            <EnterpriseFormModal open={formOpen} title={isEditing ? "Edit Price Level" : "Add Price Level"} onClose={() => { if (!saving) { handleClear(); setFormOpen(false); } }} busy={saving} width="620px">
            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col">
              <div className="h-10 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between shrink-0">
                <h2 className="text-[12px] font-black uppercase tracking-wide text-white">
                  {isEditing ? "Edit Price Level" : "Create Price Level"}
                </h2>

                <span className="text-[10px] font-black text-[#00BCBC]">
                  {isEditing ? "EDITING" : "NEW"}
                </span>
              </div>

              <form onSubmit={handleSubmit} className="p-3 space-y-3 overflow-y-auto">
                <Field label="Name">
                  <input
                    type="text"
                    name="name"
                    value={formData.name}
                    onChange={handleChange}
                    className="input-small"
                  />
                </Field>

                <Field label="Code">
                  <input
                    type="text"
                    name="code"
                    value={formData.code}
                    onChange={handleChange}
                    className="input-small"
                  />
                </Field>

                <Field label="Description">
                  <textarea
                    name="description"
                    value={formData.description}
                    onChange={handleChange}
                    rows={4}
                    className="textarea-small"
                  />
                </Field>

                <div className="grid grid-cols-1 gap-2">
                  <label className="h-8 border border-[#ACB8B8] bg-[#F8FAFC] px-2 flex items-center gap-2 text-[10px] font-black uppercase">
                    <input
                      type="checkbox"
                      name="is_default"
                      checked={formData.is_default}
                      onChange={handleChange}
                    />
                    Default Price Level
                  </label>

                  <label className="h-8 border border-[#ACB8B8] bg-[#F8FAFC] px-2 flex items-center gap-2 text-[10px] font-black uppercase">
                    <input
                      type="checkbox"
                      name="is_active"
                      checked={formData.is_active}
                      onChange={handleChange}
                    />
                    Active
                  </label>
                </div>

                <div className="grid grid-cols-2 gap-2 pt-2">
                  <button
                    type="submit"
                    disabled={saving}
                    className="h-9 bg-[#069495] text-white text-[11px] font-black uppercase disabled:opacity-60"
                  >
                    {saving ? "Saving..." : isEditing ? "Update" : "Save"}
                  </button>

                  <button
                    type="button"
                    onClick={handleClear}
                    className="h-9 bg-[#E4E8E8] border border-[#ACB8B8] text-[#003636] text-[11px] font-black uppercase"
                  >
                    Clear
                  </button>
                </div>
              </form>
            </section>
            </EnterpriseFormModal>

            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col">
              <div className="h-10 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between shrink-0">
                <h2 className="text-[12px] font-black uppercase tracking-wide text-white">
                  Price Level Register
                </h2>

                <div className="flex items-center gap-2">
                  <button type="button" onClick={() => { handleClear(); setFormOpen(true); }} className="h-7 bg-[#069494] px-3 text-[10px] font-bold text-white">Add Price Level</button>
                  <button
                    type="button"
                    onClick={loadPriceLevels}
                    disabled={loading}
                    className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                  >
                    {loading ? "Loading" : "Refresh"}
                  </button>

                  <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search price levels..."
                    className="h-7 w-[220px] border border-white/20 bg-[#003637] px-2 text-[11px] text-white outline-none placeholder:text-white/50"
                  />
                </div>
              </div>

              <div className="flex-1 min-h-0 overflow-auto">
                <table className="w-full border-collapse min-w-[900px]">
                  <thead className="sticky top-0 z-10">
                    <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                      <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                        Name
                      </th>
                      <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                        Code
                      </th>
                      <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                        Description
                      </th>
                      <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                        Default
                      </th>
                      <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                        Status
                      </th>
                      <th className="w-[58px] px-3 py-2 text-center text-[11px] font-black uppercase" aria-label="Actions">
                        ⋮
                      </th>
                    </tr>
                  </thead>

                  <tbody>
                    {loading ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-3 py-6 text-center text-[12px] text-[#637877]"
                        >
                          Loading price levels...
                        </td>
                      </tr>
                    ) : filteredPriceLevels.length === 0 ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-3 py-6 text-center text-[12px] text-[#637877]"
                        >
                          No price levels found.
                        </td>
                      </tr>
                    ) : (
                      filteredPriceLevels.map((level) => (
                        <tr
                          key={level.id}
                          className={`border-b border-[#EFF2F2] hover:bg-[#F8FAFC] ${
                            formData.id === level.id ? "bg-[#E8F7F5]" : ""
                          }`}
                        >
                          <td className="px-3 py-2 text-[11px] font-bold">
                            {level.name}
                          </td>
                          <td className="px-3 py-2 text-[11px]">
                            {level.code || "-"}
                          </td>
                          <td className="px-3 py-2 text-[11px] max-w-[360px] truncate">
                            {level.description || "-"}
                          </td>
                          <td className="px-3 py-2 text-[11px]">
                            <span
                              className={`px-2 py-1 text-[10px] font-black uppercase ${
                                level.is_default
                                  ? "bg-[#E8F7F5] text-[#005A5A]"
                                  : "bg-[#E4E8E8] text-[#324444]"
                              }`}
                            >
                              {level.is_default ? "Default" : "No"}
                            </span>
                          </td>
                          <td className="px-3 py-2 text-[11px]">
                            <span
                              className={`px-2 py-1 text-[10px] font-black uppercase ${
                                level.is_active
                                  ? "bg-[#DCFCE7] text-[#166534]"
                                  : "bg-red-100 text-red-700"
                              }`}
                            >
                              {level.is_active ? "Active" : "Inactive"}
                            </span>
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex gap-1">
                              <button
                                type="button"
                                onClick={() => handleEdit(level)}
                                className="h-7 px-3 bg-[#004142] text-white text-[10px] font-bold uppercase"
                              >
                                Edit
                              </button>

                              <button
                                type="button"
                                disabled={deletingId === level.id || level.is_default}
                                onClick={() => handleDelete(level)}
                                className="h-7 px-3 bg-red-700 text-white text-[10px] font-bold uppercase disabled:opacity-50"
                              >
                                {deletingId === level.id ? "Deleting" : "Delete"}
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>

        <AppInlineStyles />
      </FullScreenModal>

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={closeMessageModal}
      />
    </>
  );
};

const Field = ({ label, children }) => (
  <div>
    <label className="block text-[10px] font-black mb-1 uppercase text-[#324444]">
      {label}
    </label>
    {children}
  </div>
);

const AppInlineStyles = () => (
  <style>
    {`
      .input-small {
        width: 100%;
        height: 2rem;
        border: 1px solid #ACB8B8;
        padding: 0 0.5rem;
        font-size: 11px;
        outline: none;
        background: white;
        border-radius: 0;
      }

      .textarea-small {
        width: 100%;
        border: 1px solid #ACB8B8;
        padding: 0.5rem;
        font-size: 11px;
        outline: none;
        background: white;
        resize: none;
        border-radius: 0;
      }

      .input-small:focus,
      .textarea-small:focus {
        border-color: #00BCBC;
        box-shadow: inset 0 0 0 1px #00BCBC;
      }

      button,
      input,
      select,
      textarea {
        border-radius: 0 !important;
      }
    `}
  </style>
);

export default PriceLevelModal;

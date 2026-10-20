// src/backOffice/product/SupplierModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFilterBar from "../../../components/EnterpriseFilterBar";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseRowActionMenu from "../../../components/EnterpriseRowActionMenu";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import {
  getSuppliers,
  createSupplier,
  updateSupplier,
  deleteSupplier,
} from "../../../services/ProductService";

const emptyFormData = {
  id: null,
  name: "",
  code: "",
  contact_person: "",
  phone: "",
  email: "",
  tax_number: "",
  address: "",
  notes: "",
  is_active: true,
};

const SupplierModal = ({ open, onClose }) => {
  const [suppliers, setSuppliers] = useState([]);

  const [loading, setLoading] = useState(false);
  const [savingSupplier, setSavingSupplier] = useState(false);
  const [deletingSupplierId, setDeletingSupplierId] = useState(null);

  const [search, setSearch] = useState("");
  const [formData, setFormData] = useState(emptyFormData);
  const [formOpen, setFormOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

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

    if (responseData?.message) return responseData.message;
    if (responseData?.error) return responseData.error;

    if (source?.errors && typeof source.errors === "object") {
      return Object.values(source.errors).flat().join("\n");
    }

    if (source?.message && !source?.isAxiosError) return source.message;
    if (source?.data?.message) return source.data.message;
    if (source?.data?.error) return source.data.error;

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

  const normalizeSuppliersResponse = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;

    if (Array.isArray(payload?.data)) return payload.data;

    return [];
  };

  const loadSuppliers = async () => {
    try {
      setLoading(true);

      const response = await getSuppliers({ per_page: 100 });
      setSuppliers(normalizeSuppliersResponse(response));
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
      loadSuppliers();
    }
  }, [open]);

  const filteredSuppliers = useMemo(() => {
    const q = search.toLowerCase();

    return suppliers.filter((supplier) => {
      return (
        supplier?.name?.toLowerCase().includes(q) ||
        supplier?.code?.toLowerCase().includes(q) ||
        supplier?.contact_person?.toLowerCase().includes(q) ||
        supplier?.phone?.toLowerCase().includes(q) ||
        supplier?.email?.toLowerCase().includes(q) ||
        supplier?.tax_number?.toLowerCase().includes(q)
      );
    });
  }, [suppliers, search]);

  const handleChange = (e) => {
    const { name, value, checked, type } = e.target;

    setFormData((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleClear = () => {
    setFormData(emptyFormData);
  };

  const handleOpenCreate = () => {
    handleClear();
    setFormOpen(true);
  };

  const handleCloseForm = () => {
    if (savingSupplier) return;
    handleClear();
    setFormOpen(false);
  };

  const validateForm = () => {
    if (!formData.name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Supplier name is required.",
      });

      return false;
    }

    if (formData.email && !/^\S+@\S+\.\S+$/.test(formData.email)) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Enter a valid email address.",
      });

      return false;
    }

    return true;
  };

  const buildPayload = () => {
    return {
      name: formData.name,
      code: formData.code || null,
      contact_person: formData.contact_person || null,
      phone: formData.phone || null,
      email: formData.email || null,
      tax_number: formData.tax_number || null,
      address: formData.address || null,
      notes: formData.notes || null,
      is_active: !!formData.is_active,
    };
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) return;

    try {
      setSavingSupplier(true);

      const payload = buildPayload();
      let response;

      if (isEditing) {
        response = await updateSupplier(formData.id, payload);

        showMessage({
          type: "success",
          title: "Supplier Updated",
          message: getApiMessage(response),
        });
      } else {
        response = await createSupplier(payload);

        showMessage({
          type: "success",
          title: "Supplier Created",
          message: getApiMessage(response),
        });
      }

      handleClear();
      setFormOpen(false);
      await loadSuppliers();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingSupplier(false);
    }
  };

  const handleEdit = (supplier) => {
    setFormData({
      id: supplier.id,
      name: supplier.name || "",
      code: supplier.code || "",
      contact_person: supplier.contact_person || "",
      phone: supplier.phone || "",
      email: supplier.email || "",
      tax_number: supplier.tax_number || "",
      address: supplier.address || "",
      notes: supplier.notes || "",
      is_active: supplier.is_active ?? true,
    });
    setFormOpen(true);
  };

  const handleDelete = async (supplierId) => {
    try {
      setDeletingSupplierId(supplierId);

      const response = await deleteSupplier(supplierId);

      showMessage({
        type: "success",
        title: "Supplier Deleted",
        message: getApiMessage(response),
      });

      if (formData.id === supplierId) {
        handleClear();
      }

      await loadSuppliers();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingSupplierId(null);
      setDeleteTarget(null);
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="SUPPLIERS"
        subtitle="Supplier register"
      >
        <div className="relative h-full">
          <EnterpriseFormModal open={formOpen} title={isEditing ? "Edit Supplier" : "Create Supplier"} onClose={handleCloseForm} busy={savingSupplier}>
            <form onSubmit={handleSubmit} className="grid min-h-0 grid-cols-1 gap-3 overflow-y-auto p-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Supplier Name
                </label>

                <input
                  type="text"
                  name="name"
                  value={formData.name}
                  onChange={handleChange}
                  className="w-full h-9 border border-[#CAD5D5] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Code
                </label>

                <input
                  type="text"
                  name="code"
                  value={formData.code}
                  onChange={handleChange}
                  className="w-full h-9 border border-[#CAD5D5] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Contact Person
                </label>

                <input
                  type="text"
                  name="contact_person"
                  value={formData.contact_person}
                  onChange={handleChange}
                  className="w-full h-9 border border-[#CAD5D5] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Phone
                </label>

                <input
                  type="text"
                  name="phone"
                  value={formData.phone}
                  onChange={handleChange}
                  className="w-full h-9 border border-[#CAD5D5] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Email
                </label>

                <input
                  type="email"
                  name="email"
                  value={formData.email}
                  onChange={handleChange}
                  className="w-full h-9 border border-[#CAD5D5] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Tax Number
                </label>

                <input
                  type="text"
                  name="tax_number"
                  value={formData.tax_number}
                  onChange={handleChange}
                  className="w-full h-9 border border-[#CAD5D5] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Address
                </label>

                <textarea
                  name="address"
                  value={formData.address}
                  onChange={handleChange}
                  className="w-full h-20 border border-[#CAD5D5] p-2 text-[12px] outline-none resize-none focus:border-[#00BCBC]"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold mb-1 uppercase tracking-wide">
                  Notes
                </label>

                <textarea
                  name="notes"
                  value={formData.notes}
                  onChange={handleChange}
                  className="w-full h-20 border border-[#CAD5D5] p-2 text-[12px] outline-none resize-none focus:border-[#00BCBC]"
                />
              </div>

              <div className="space-y-2 pt-1 sm:col-span-2">
                <label className="flex items-center gap-2 text-[11px] font-semibold">
                  <input
                    type="checkbox"
                    name="is_active"
                    checked={formData.is_active}
                    onChange={handleChange}
                    className="w-3.5 h-3.5"
                  />
                  Active Supplier
                </label>
              </div>

              <div className="grid grid-cols-2 gap-2 border-t border-[#DAE1E1] pt-3 sm:col-span-2">
                <button
                  type="submit"
                  disabled={savingSupplier}
                  className="h-9 bg-[#069495] hover:bg-[#057B7C] text-white text-[11px] font-black tracking-wide uppercase disabled:opacity-60"
                >
                  {savingSupplier
                    ? "Saving..."
                    : isEditing
                    ? "Update Supplier"
                    : "Save Supplier"}
                </button>

                <button
                  type="button"
                  onClick={handleCloseForm}
                  className="h-9 bg-[#E4E8E8] hover:bg-[#D1D6D6] text-[#003636] text-[11px] font-black tracking-wide uppercase"
                >
                    Cancel
                </button>
              </div>
            </form>
          </EnterpriseFormModal>

          <div className="border border-[#CAD5D5] bg-white flex h-full flex-col">
            <EnterpriseFilterBar
              leading={<div className="text-[12px] font-semibold text-[#2B3C3C]">Supplier Register</div>}
              searchValue={search}
              onSearchChange={setSearch}
              searchLabel="Search suppliers"
              searchPlaceholder="Search suppliers"
              actions={(
                <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={handleOpenCreate}
                  className="h-7 bg-[#069494] px-3 text-[10px] font-bold text-white hover:bg-[#008585]"
                >
                  Add Supplier
                </button>
                <button
                  type="button"
                  onClick={loadSuppliers}
                  disabled={loading}
                  className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {loading ? "Loading..." : "Refresh"}
                </button>
                </div>
              )}
            />

            <div className="overflow-auto">
              <table className="w-full border-collapse">
                <thead>
                  <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                      Name
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                      Code
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                      Contact
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                      Phone
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                      Email
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                      Status
                    </th>

                    <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                      More
                    </th>
                  </tr>
                </thead>

                <tbody>
                  {loading ? (
                    <tr>
                      <td
                        colSpan={7}
                        className="px-3 py-6 text-center text-[12px] text-[#637877]"
                      >
                        Loading suppliers...
                      </td>
                    </tr>
                  ) : filteredSuppliers.length === 0 ? (
                    <tr>
                      <td
                        colSpan={7}
                        className="px-3 py-6 text-center text-[12px] text-[#637877]"
                      >
                        No suppliers found.
                      </td>
                    </tr>
                  ) : (
                    filteredSuppliers.map((supplier) => (
                      <tr
                        key={supplier.id}
                        className="border-b border-[#EFF2F2] hover:bg-[#F8FAFC]"
                      >
                        <td className="px-3 py-2 text-[11px] font-medium">
                          {supplier.name}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {supplier.code || "-"}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {supplier.contact_person || "-"}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {supplier.phone || "-"}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          {supplier.email || "-"}
                        </td>

                        <td className="px-3 py-2 text-[11px]">
                          <span
                            className={`px-2 py-1 text-[10px] font-bold ${
                              supplier.is_active
                                ? "bg-[#DCFCE7] text-[#166534]"
                                : "bg-red-100 text-red-700"
                            }`}
                          >
                            {supplier.is_active ? "Active" : "Inactive"}
                          </span>
                        </td>

                        <td className="px-3 py-2">
                          <EnterpriseRowActionMenu
                            label={`More actions for ${supplier.name}`}
                            actions={[
                              { label: "Edit supplier", onClick: () => handleEdit(supplier) },
                              { label: deletingSupplierId === supplier.id ? "Deleting supplier" : "Delete supplier", danger: true, disabled: deletingSupplierId === supplier.id, onClick: () => setDeleteTarget(supplier) },
                            ]}
                          />
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </FullScreenModal>

      <EnterpriseConfirmModal
        open={Boolean(deleteTarget)}
        title="Delete Supplier"
        message={deleteTarget ? `Delete ${deleteTarget.name}?` : ""}
        confirmLabel="Delete supplier"
        busy={Boolean(deletingSupplierId)}
        onClose={() => !deletingSupplierId && setDeleteTarget(null)}
        onConfirm={() => deleteTarget && handleDelete(deleteTarget.id)}
      />

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

export default SupplierModal;

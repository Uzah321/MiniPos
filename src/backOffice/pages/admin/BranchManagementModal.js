// src/backOffice/components/BranchManagementModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";

import {
  getResolvedCompanyBranches,
  createResolvedCompanyBranch,
  createCompanyBranch,
  updateBranch,
  deleteBranch,
  createBranchContact,
  updateBranchContact,
  deleteBranchContact,
} from "../../../services/CompanyService";

const emptyBranchForm = {
  branch_name: "",
  branch_code: "",
  branch_address: "",
  branch_city: "",
  branch_country: "",
  level: "",
  is_main_branch: false,
  status: "active",
};

const emptyContactForm = {
  contact_name: "",
  email: "",
  phone: "",
  position: "",
};

const BranchManagementModal = ({
  open,
  onClose,
  companyId = null,
  adminMode = false,
}) => {
  const [branches, setBranches] = useState([]);
  const [selectedBranch, setSelectedBranch] = useState(null);
  const [selectedContact, setSelectedContact] = useState(null);

  const [branchForm, setBranchForm] = useState(emptyBranchForm);
  const [contactForm, setContactForm] = useState(emptyContactForm);
  const [branchFormOpen, setBranchFormOpen] = useState(false);
  const [contactFormOpen, setContactFormOpen] = useState(false);

  const [activePanel, setActivePanel] = useState("branch");
  const [search, setSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [savingBranch, setSavingBranch] = useState(false);
  const [deletingBranch, setDeletingBranch] = useState(false);
  const [savingContact, setSavingContact] = useState(false);
  const [deletingContact, setDeletingContact] = useState(false);

  const [confirmDelete, setConfirmDelete] = useState(null);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.data && typeof responseData.data === "object") {
      const messages = Object.values(responseData.data).flat();

      if (messages.length > 0) {
        return messages.join("\n");
      }
    }

    if (responseData?.message) return responseData.message;
    if (responseData?.error) return responseData.error;

    if (source?.data?.message) return source.data.message;
    if (source?.data?.error) return source.data.error;

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

  const closeMessage = () => {
    setMessageModal((prev) => ({
      ...prev,
      open: false,
    }));
  };

  const normalizeBranches = (response) => {
    return response?.branches || response?.data?.branches || [];
  };

  const getStatusClass = (status) => {
    const normalized = String(status || "").toLowerCase();

    if (normalized === "active") {
      return "bg-[#DCFCE7] text-[#166534] border-[#86EFAC]";
    }

    if (normalized === "inactive") {
      return "bg-[#E6ECEC] text-[#465858] border-[#ACB8B8]";
    }

    if (normalized === "suspended") {
      return "bg-[#FEF3C7] text-[#92400E] border-[#FCD34D]";
    }

    return "bg-[#E6ECEC] text-[#003636] border-[#ACB8B8]";
  };

  const loadBranches = async () => {
    try {
      setLoading(true);

      const response = await getResolvedCompanyBranches();
      const data = normalizeBranches(response);

      setBranches(data);

      if (selectedBranch) {
        const freshBranch = data.find((item) => item.id === selectedBranch.id);
        setSelectedBranch(freshBranch || null);
      }
    } catch (error) {
      showMessage({
        type: "error",
        title: "Branches Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      loadBranches();
      setActivePanel("branch");
      setSelectedBranch(null);
      setSelectedContact(null);
      setBranchForm(emptyBranchForm);
      setContactForm(emptyContactForm);
      setConfirmDelete(null);
      setBranchFormOpen(false);
      setContactFormOpen(false);
      setSearch("");
    }
  }, [open]);

  const filteredBranches = useMemo(() => {
    const q = search.toLowerCase().trim();

    if (!q) return branches;

    return branches.filter((branch) => {
      return (
        String(branch.branch_name || "").toLowerCase().includes(q) ||
        String(branch.branch_code || "").toLowerCase().includes(q) ||
        String(branch.branch_city || "").toLowerCase().includes(q) ||
        String(branch.branch_country || "").toLowerCase().includes(q) ||
        String(branch.status || "").toLowerCase().includes(q)
      );
    });
  }, [branches, search]);

  const resetBranchForm = () => {
    setSelectedBranch(null);
    setSelectedContact(null);
    setBranchForm(emptyBranchForm);
    setContactForm(emptyContactForm);
    setActivePanel("branch");
  };

  const selectBranch = (branch) => {
    setSelectedBranch(branch);
    setSelectedContact(null);
    setBranchForm({
      branch_name: branch.branch_name || "",
      branch_code: branch.branch_code || "",
      branch_address: branch.branch_address || "",
      branch_city: branch.branch_city || "",
      branch_country: branch.branch_country || "",
      level: branch.level || "",
      is_main_branch: Boolean(branch.is_main_branch),
      status: branch.status || "active",
    });
    setContactForm(emptyContactForm);
    setActivePanel("branch");
  };

  const selectContact = (contact) => {
    setSelectedContact(contact);
    setContactForm({
      contact_name: contact.contact_name || "",
      email: contact.email || "",
      phone: contact.phone || "",
      position: contact.position || "",
    });
    setActivePanel("contact");
    setContactFormOpen(true);
  };

  const handleBranchChange = (e) => {
    const { name, value, type, checked } = e.target;

    setBranchForm((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleContactChange = (e) => {
    const { name, value } = e.target;

    setContactForm((prev) => ({
      ...prev,
      [name]: value,
    }));
  };

  const validateBranchForm = () => {
    if (!branchForm.branch_name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Branch name is required.",
      });

      return false;
    }

    return true;
  };

  const buildBranchPayload = () => {
    const payload = {
      branch_name: branchForm.branch_name.trim(),
      branch_code: branchForm.branch_code || null,
      branch_address: branchForm.branch_address || null,
      branch_city: branchForm.branch_city || null,
      branch_country: branchForm.branch_country || null,
      level: branchForm.level || null,
      is_main_branch: Boolean(branchForm.is_main_branch),
    };

    if (selectedBranch) {
      payload.status = branchForm.status || "active";
    }

    return payload;
  };

  const handleSaveBranch = async (e) => {
    e.preventDefault();

    if (savingBranch) return;
    if (!validateBranchForm()) return;

    try {
      setSavingBranch(true);

      const payload = buildBranchPayload();
      let response;

      if (selectedBranch) {
        response = await updateBranch(selectedBranch.id, payload);
      } else if (adminMode && companyId) {
        response = await createCompanyBranch(companyId, {
          ...payload,
          contacts: [],
        });
      } else {
        response = await createResolvedCompanyBranch({
          ...payload,
          contacts: [],
        });
      }

      showMessage({
        type: "success",
        title: selectedBranch ? "Branch Updated" : "Branch Created",
        message: getApiMessage(
          response,
          selectedBranch
            ? "Branch updated successfully."
            : "Branch created successfully."
        ),
      });

      await loadBranches();

      const savedBranch = response?.branch || response?.data?.branch;

      if (savedBranch) {
        setSelectedBranch(savedBranch);
      }

      if (!selectedBranch) {
        setBranchForm(emptyBranchForm);
      }
      setBranchFormOpen(false);
    } catch (error) {
      showMessage({
        type: "error",
        title: selectedBranch ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingBranch(false);
    }
  };

  const handleDeleteBranch = async () => {
    if (!confirmDelete?.branch || deletingBranch) return;

    try {
      setDeletingBranch(true);

      const response = await deleteBranch(confirmDelete.branch.id);

      showMessage({
        type: "success",
        title: "Branch Deleted",
        message: getApiMessage(response, "Branch deleted successfully."),
      });

      setConfirmDelete(null);
      resetBranchForm();
      await loadBranches();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingBranch(false);
    }
  };

  const validateContactForm = () => {
    if (!selectedBranch) {
      showMessage({
        type: "error",
        title: "No Branch Selected",
        message: "Select a branch before saving a contact.",
      });

      return false;
    }

    if (contactForm.email && !/^\S+@\S+\.\S+$/.test(contactForm.email)) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Enter a valid email address.",
      });

      return false;
    }

    return true;
  };

  const buildContactPayload = () => {
    return {
      contact_name: contactForm.contact_name || null,
      email: contactForm.email || null,
      phone: contactForm.phone || null,
      position: contactForm.position || null,
    };
  };

  const handleSaveContact = async (e) => {
    e.preventDefault();

    if (savingContact) return;
    if (!validateContactForm()) return;

    try {
      setSavingContact(true);

      const payload = buildContactPayload();
      let response;

      if (selectedContact) {
        response = await updateBranchContact(selectedContact.id, payload);
      } else {
        response = await createBranchContact(selectedBranch.id, payload);
      }

      showMessage({
        type: "success",
        title: selectedContact ? "Contact Updated" : "Contact Created",
        message: getApiMessage(
          response,
          selectedContact
            ? "Branch contact updated successfully."
            : "Branch contact created successfully."
        ),
      });

      setSelectedContact(null);
      setContactForm(emptyContactForm);
      setContactFormOpen(false);
      await loadBranches();
    } catch (error) {
      showMessage({
        type: "error",
        title: selectedContact ? "Contact Update Failed" : "Contact Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingContact(false);
    }
  };

  const handleDeleteContact = async () => {
    if (!confirmDelete?.contact || deletingContact) return;

    try {
      setDeletingContact(true);

      const response = await deleteBranchContact(confirmDelete.contact.id);

      showMessage({
        type: "success",
        title: "Contact Deleted",
        message: getApiMessage(response, "Branch contact deleted successfully."),
      });

      setConfirmDelete(null);
      setSelectedContact(null);
      setContactForm(emptyContactForm);
      await loadBranches();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Contact Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingContact(false);
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="BRANCH MANAGEMENT"
        subtitle="SERVENEST ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] bg-[#DFE5E5] text-[#003636] overflow-hidden">
          <div className="h-full grid grid-cols-1 xl:grid-cols-[380px_1fr] gap-3">
            {/* LEFT: BRANCH LIST */}
            <aside className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col">
              <div className="h-10 bg-[#004142] border-b-2 border-[#00BCBC] px-3 flex items-center justify-between shrink-0">
                <div className="text-[12px] font-black uppercase text-white">
                  Branches
                </div>

                <button
                  type="button"
                  onClick={loadBranches}
                  disabled={loading}
                  className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-50"
                >
                  {loading ? "Loading" : "Refresh"}
                </button>
              </div>

              <div className="p-2 border-b border-[#D7DFDE] shrink-0">
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search branch..."
                  className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                />

                <button
                  type="button"
                  onClick={() => { resetBranchForm(); setBranchFormOpen(true); }}
                  className="mt-2 w-full h-9 bg-[#004142] text-white text-[11px] font-black uppercase"
                >
                  New Branch
                </button>
              </div>

              <div className="flex-1 min-h-0 overflow-y-auto">
                {loading ? (
                  <div className="h-full flex items-center justify-center text-[12px] text-[#637877]">
                    Loading branches...
                  </div>
                ) : filteredBranches.length === 0 ? (
                  <div className="h-full flex items-center justify-center text-[12px] text-[#637877]">
                    No branches found.
                  </div>
                ) : (
                  filteredBranches.map((branch) => {
                    const selected = selectedBranch?.id === branch.id;
                    const contactsCount = branch.contacts?.length || 0;

                    return (
                      <button
                        key={branch.id}
                        type="button"
                        onClick={() => selectBranch(branch)}
                        className={`w-full text-left border-b border-[#E2E9E9] px-3 py-2 ${
                          selected ? "bg-[#E8F7F5]" : "bg-white hover:bg-[#F8FAFC]"
                        }`}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0">
                            <div className="text-[12px] font-black truncate">
                              {branch.branch_name}
                            </div>

                            <div className="text-[10px] text-[#637877] mt-[2px]">
                              {branch.branch_code || "No Code"} /{" "}
                              {branch.branch_city || "No City"}
                            </div>

                            <div className="text-[9px] text-[#637877] mt-[2px]">
                              {contactsCount} contact{contactsCount === 1 ? "" : "s"}
                            </div>
                          </div>

                          <span
                            className={`px-2 py-1 text-[9px] font-black uppercase border shrink-0 ${getStatusClass(
                              branch.status
                            )}`}
                          >
                            {branch.status || "active"}
                          </span>
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            </aside>

            {/* RIGHT WORKSPACE */}
            <section className="min-h-0 grid grid-rows-[42px_1fr] gap-3">
              {/* TOP TABS */}
              <div className="bg-white border border-[#ACB8B8] p-1 grid grid-cols-2 gap-1">
                <button
                  type="button"
                  onClick={() => setActivePanel("branch")}
                  className={`text-[11px] font-black uppercase border border-[#ACB8B8] ${
                    activePanel === "branch"
                      ? "bg-[#004142] text-white"
                      : "bg-[#E6ECEC] text-[#003636]"
                  }`}
                >
                  Branch Details
                </button>

                <button
                  type="button"
                  onClick={() => setActivePanel("contact")}
                  className={`text-[11px] font-black uppercase border border-[#ACB8B8] ${
                    activePanel === "contact"
                      ? "bg-[#004142] text-white"
                      : "bg-[#E6ECEC] text-[#003636]"
                  }`}
                >
                  Branch Contacts
                </button>
              </div>

              {/* CONTENT */}
              <div className="bg-white border border-[#ACB8B8] min-h-0 overflow-hidden">
                {activePanel === "branch" && (
                  <div className="h-full">
                    {/* BRANCH FORM */}
                    <EnterpriseFormModal open={branchFormOpen} title={selectedBranch ? "Edit Branch" : "Add Branch"} onClose={() => !savingBranch && setBranchFormOpen(false)} busy={savingBranch} width="720px">
                    <form
                      onSubmit={handleSaveBranch}
                      className="border-r border-[#D7DFDE] p-3 overflow-y-auto"
                    >
                      <div className="h-9 bg-[#004142] border-b-2 border-[#00BCBC] px-3 flex items-center justify-between mb-3">
                        <h2 className="text-[11px] font-black uppercase text-white">
                          {selectedBranch ? "Edit Branch" : "Create Branch"}
                        </h2>

                        {selectedBranch && (
                          <span className="text-[10px] font-bold text-[#00BCBC]">
                            Selected
                          </span>
                        )}
                      </div>

                      <div className="space-y-3">
                        <div>
                          <label className="block text-[10px] font-black uppercase mb-1">
                            Branch Name *
                          </label>
                          <input
                            type="text"
                            name="branch_name"
                            value={branchForm.branch_name}
                            onChange={handleBranchChange}
                            className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                          />
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                          <div>
                            <label className="block text-[10px] font-black uppercase mb-1">
                              Branch Code
                            </label>
                            <input
                              type="text"
                              name="branch_code"
                              value={branchForm.branch_code}
                              onChange={handleBranchChange}
                              className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                            />
                          </div>

                          <div>
                            <label className="block text-[10px] font-black uppercase mb-1">
                              Level
                            </label>
                            <input
                              type="text"
                              name="level"
                              value={branchForm.level}
                              onChange={handleBranchChange}
                              placeholder="HQ, Region, Store..."
                              className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                            />
                          </div>
                        </div>

                        <div>
                          <label className="block text-[10px] font-black uppercase mb-1">
                            Address
                          </label>
                          <input
                            type="text"
                            name="branch_address"
                            value={branchForm.branch_address}
                            onChange={handleBranchChange}
                            className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                          />
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                          <div>
                            <label className="block text-[10px] font-black uppercase mb-1">
                              City
                            </label>
                            <input
                              type="text"
                              name="branch_city"
                              value={branchForm.branch_city}
                              onChange={handleBranchChange}
                              className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                            />
                          </div>

                          <div>
                            <label className="block text-[10px] font-black uppercase mb-1">
                              Country
                            </label>
                            <input
                              type="text"
                              name="branch_country"
                              value={branchForm.branch_country}
                              onChange={handleBranchChange}
                              className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                            />
                          </div>
                        </div>

                        {selectedBranch && (
                          <div>
                            <label className="block text-[10px] font-black uppercase mb-1">
                              Status
                            </label>
                            <select
                              name="status"
                              value={branchForm.status}
                              onChange={handleBranchChange}
                              className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                            >
                              <option value="active">Active</option>
                              <option value="inactive">Inactive</option>
                              <option value="suspended">Suspended</option>
                            </select>
                          </div>
                        )}

                        <label className="h-9 flex items-center gap-2 border border-[#ACB8B8] px-2 bg-[#F8FAFC]">
                          <input
                            type="checkbox"
                            name="is_main_branch"
                            checked={branchForm.is_main_branch}
                            onChange={handleBranchChange}
                          />
                          <span className="text-[11px] font-black uppercase">
                            Main Branch
                          </span>
                        </label>

                        <button
                          type="submit"
                          disabled={savingBranch}
                          className="w-full h-10 bg-[#069495] text-white text-[11px] font-black uppercase disabled:opacity-50"
                        >
                          {savingBranch
                            ? "Saving..."
                            : selectedBranch
                            ? "Update Branch"
                            : "Create Branch"}
                        </button>

                        {selectedBranch && (
                          <button
                            type="button"
                            disabled={deletingBranch}
                            onClick={() =>
                              setConfirmDelete({
                                type: "branch",
                                branch: selectedBranch,
                              })
                            }
                            className="w-full h-9 bg-[#E30613] text-white text-[11px] font-black uppercase disabled:opacity-50"
                          >
                            Delete Branch
                          </button>
                        )}

                        <button
                          type="button"
                          onClick={() => { resetBranchForm(); setBranchFormOpen(false); }}
                          className="w-full h-9 bg-[#E6ECEC] border border-[#ACB8B8] text-[11px] font-black uppercase"
                        >
                          Cancel
                        </button>
                      </div>
                    </form>
                    </EnterpriseFormModal>

                    {/* BRANCH DETAILS */}
                    <div className="p-3 min-h-0 overflow-y-auto">
                      <div className="h-9 bg-[#F5F7FA] border border-[#D7DFDE] px-3 flex items-center justify-between mb-3">
                        <h2 className="text-[11px] font-black uppercase">
                          Branch Preview
                        </h2>

                        {selectedBranch && (
                          <div className="flex items-center gap-2"><span className={`px-2 py-1 text-[9px] font-black uppercase border ${getStatusClass(selectedBranch.status)}`}>{selectedBranch.status || "active"}</span><button type="button" onClick={() => setBranchFormOpen(true)} className="h-7 bg-[#069494] px-3 text-[10px] font-bold text-white">Edit Branch</button></div>
                        )}
                      </div>

                      {selectedBranch ? (
                        <div className="space-y-3">
                          <div className="border border-[#ACB8B8] bg-[#F8FAFC] p-3">
                            <div className="text-[10px] font-black uppercase text-[#637877]">
                              Branch Name
                            </div>
                            <div className="text-[18px] font-black mt-1">
                              {selectedBranch.branch_name}
                            </div>
                          </div>

                          <div className="grid grid-cols-1 md:grid-cols-3 gap-2">
                            <div className="border border-[#CAD5D5] p-2">
                              <div className="text-[9px] font-black uppercase text-[#637877]">
                                Code
                              </div>
                              <div className="text-[12px] font-bold">
                                {selectedBranch.branch_code || "-"}
                              </div>
                            </div>

                            <div className="border border-[#CAD5D5] p-2">
                              <div className="text-[9px] font-black uppercase text-[#637877]">
                                Level
                              </div>
                              <div className="text-[12px] font-bold">
                                {selectedBranch.level || "-"}
                              </div>
                            </div>

                            <div className="border border-[#CAD5D5] p-2">
                              <div className="text-[9px] font-black uppercase text-[#637877]">
                                Main
                              </div>
                              <div className="text-[12px] font-bold">
                                {selectedBranch.is_main_branch ? "Yes" : "No"}
                              </div>
                            </div>
                          </div>

                          <div className="border border-[#CAD5D5] p-2">
                            <div className="text-[9px] font-black uppercase text-[#637877]">
                              Address
                            </div>
                            <div className="text-[12px] font-bold">
                              {selectedBranch.branch_address || "-"}
                            </div>
                          </div>

                          <div className="grid grid-cols-2 gap-2">
                            <div className="border border-[#CAD5D5] p-2">
                              <div className="text-[9px] font-black uppercase text-[#637877]">
                                City
                              </div>
                              <div className="text-[12px] font-bold">
                                {selectedBranch.branch_city || "-"}
                              </div>
                            </div>

                            <div className="border border-[#CAD5D5] p-2">
                              <div className="text-[9px] font-black uppercase text-[#637877]">
                                Country
                              </div>
                              <div className="text-[12px] font-bold">
                                {selectedBranch.branch_country || "-"}
                              </div>
                            </div>
                          </div>

                          <button
                            type="button"
                            onClick={() => setActivePanel("contact")}
                            className="w-full h-10 bg-[#004142] text-white text-[11px] font-black uppercase"
                          >
                            Manage Contacts
                          </button>
                        </div>
                      ) : (
                        <div className="h-full flex items-center justify-center text-[12px] text-[#637877]">
                          Select a branch or create a new one.
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {activePanel === "contact" && (
                  <div className="h-full">
                    {/* CONTACT FORM */}
                    <EnterpriseFormModal open={contactFormOpen} title={selectedContact ? "Edit Branch Contact" : "Add Branch Contact"} subtitle={selectedBranch?.branch_name || "Select a branch first"} onClose={() => { if (!savingContact) { setContactFormOpen(false); setSelectedContact(null); setContactForm(emptyContactForm); } }} busy={savingContact} width="620px">
                    <form
                      onSubmit={handleSaveContact}
                      className="border-r border-[#D7DFDE] p-3 overflow-y-auto"
                    >
                      <div className="h-9 bg-[#004142] border-b-2 border-[#00BCBC] px-3 flex items-center justify-between mb-3">
                        <h2 className="text-[11px] font-black uppercase text-white">
                          {selectedContact ? "Edit Contact" : "Add Contact"}
                        </h2>

                        {selectedBranch && (
                          <span className="text-[10px] font-bold text-[#00BCBC] truncate">
                            {selectedBranch.branch_name}
                          </span>
                        )}
                      </div>

                      {!selectedBranch && (
                        <div className="border border-[#FCD34D] bg-[#FEF3C7] text-[#92400E] p-3 text-[11px] font-bold mb-3">
                          Select a branch first before adding contacts.
                        </div>
                      )}

                      <div className="space-y-3">
                        <div>
                          <label className="block text-[10px] font-black uppercase mb-1">
                            Contact Name
                          </label>
                          <input
                            type="text"
                            name="contact_name"
                            value={contactForm.contact_name}
                            onChange={handleContactChange}
                            disabled={!selectedBranch}
                            className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC] disabled:bg-[#F1F5F5]"
                          />
                        </div>

                        <div>
                          <label className="block text-[10px] font-black uppercase mb-1">
                            Email
                          </label>
                          <input
                            type="email"
                            name="email"
                            value={contactForm.email}
                            onChange={handleContactChange}
                            disabled={!selectedBranch}
                            className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC] disabled:bg-[#F1F5F5]"
                          />
                        </div>

                        <div>
                          <label className="block text-[10px] font-black uppercase mb-1">
                            Phone
                          </label>
                          <input
                            type="text"
                            name="phone"
                            value={contactForm.phone}
                            onChange={handleContactChange}
                            disabled={!selectedBranch}
                            className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC] disabled:bg-[#F1F5F5]"
                          />
                        </div>

                        <div>
                          <label className="block text-[10px] font-black uppercase mb-1">
                            Position
                          </label>
                          <input
                            type="text"
                            name="position"
                            value={contactForm.position}
                            onChange={handleContactChange}
                            disabled={!selectedBranch}
                            className="w-full h-9 border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC] disabled:bg-[#F1F5F5]"
                          />
                        </div>

                        <button
                          type="submit"
                          disabled={!selectedBranch || savingContact}
                          className="w-full h-10 bg-[#069495] text-white text-[11px] font-black uppercase disabled:opacity-50"
                        >
                          {savingContact
                            ? "Saving..."
                            : selectedContact
                            ? "Update Contact"
                            : "Add Contact"}
                        </button>

                        {selectedContact && (
                          <button
                            type="button"
                            disabled={deletingContact}
                            onClick={() =>
                              setConfirmDelete({
                                type: "contact",
                                contact: selectedContact,
                              })
                            }
                            className="w-full h-9 bg-[#E30613] text-white text-[11px] font-black uppercase disabled:opacity-50"
                          >
                            Delete Contact
                          </button>
                        )}

                        <button
                          type="button"
                          onClick={() => {
                            setSelectedContact(null);
                            setContactForm(emptyContactForm);
                            setContactFormOpen(false);
                          }}
                          className="w-full h-9 bg-[#E6ECEC] border border-[#ACB8B8] text-[11px] font-black uppercase"
                        >
                          Cancel
                        </button>
                      </div>
                    </form>
                    </EnterpriseFormModal>

                    {/* CONTACT LIST */}
                    <div className="p-3 min-h-0 flex flex-col">
                      <div className="h-9 bg-[#F5F7FA] border border-[#D7DFDE] px-3 flex items-center justify-between mb-3 shrink-0">
                        <h2 className="text-[11px] font-black uppercase">
                          Branch Contacts
                        </h2>

                        <span className="text-[10px] font-bold text-[#069495]">
                          {selectedBranch?.contacts?.length || 0} Records
                        </span>
                        <button type="button" disabled={!selectedBranch} onClick={() => { setSelectedContact(null); setContactForm(emptyContactForm); setContactFormOpen(true); }} className="h-7 bg-[#069494] px-3 text-[10px] font-bold text-white disabled:opacity-50">Add Contact</button>
                      </div>

                      <div className="flex-1 min-h-0 overflow-auto">
                        {!selectedBranch ? (
                          <div className="h-full flex items-center justify-center text-[12px] text-[#637877]">
                            Select a branch to view contacts.
                          </div>
                        ) : selectedBranch.contacts?.length === 0 ? (
                          <div className="h-full flex items-center justify-center text-[12px] text-[#637877]">
                            No contacts for this branch.
                          </div>
                        ) : (
                          <table className="w-full border-collapse">
                            <thead className="sticky top-0 z-10">
                              <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                                <th className="text-left px-2 py-2 text-[10px] font-black uppercase">
                                  Name
                                </th>
                                <th className="text-left px-2 py-2 text-[10px] font-black uppercase">
                                  Email
                                </th>
                                <th className="text-left px-2 py-2 text-[10px] font-black uppercase w-[120px]">
                                  Phone
                                </th>
                                <th className="text-left px-2 py-2 text-[10px] font-black uppercase w-[120px]">
                                  Position
                                </th>
                              </tr>
                            </thead>

                            <tbody>
                              {selectedBranch.contacts.map((contact) => (
                                <tr
                                  key={contact.id}
                                  onClick={() => selectContact(contact)}
                                  className={`border-b border-[#EFF2F2] cursor-pointer hover:bg-[#E8F7F5] ${
                                    selectedContact?.id === contact.id
                                      ? "bg-[#E8F7F5]"
                                      : ""
                                  }`}
                                >
                                  <td className="px-2 py-2 text-[11px] font-black">
                                    {contact.contact_name || "-"}
                                  </td>

                                  <td className="px-2 py-2 text-[10px]">
                                    {contact.email || "-"}
                                  </td>

                                  <td className="px-2 py-2 text-[10px]">
                                    {contact.phone || "-"}
                                  </td>

                                  <td className="px-2 py-2 text-[10px]">
                                    {contact.position || "-"}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        )}
                      </div>
                    </div>
                  </div>
                )}
              </div>
            </section>
          </div>
        </div>
      </FullScreenModal>

      <EnterpriseConfirmModal
        open={Boolean(confirmDelete)}
        title="Confirm Delete"
        message={confirmDelete?.type === "branch"
          ? `Delete branch "${confirmDelete?.branch?.branch_name}"?`
          : `Delete contact "${confirmDelete?.contact?.contact_name || "Unnamed Contact"}"?`}
        confirmLabel="Delete"
        busy={deletingBranch || deletingContact}
        onClose={() => setConfirmDelete(null)}
        onConfirm={confirmDelete?.type === "branch" ? handleDeleteBranch : handleDeleteContact}
      />

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={closeMessage}
      />
    </>
  );
};

export default BranchManagementModal;

// src/backOffice/pages/product/RolePriceLevelAssignModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import { getRoles } from "../../../services/AuthenticationService";

import {
  getPriceLevels,
  getRolePriceLevels,
  syncRolePriceLevels,
} from "../../../services/ProductService";

const RolePriceLevelAssignModal = ({ open, onClose }) => {
  const [roles, setRoles] = useState([]);
  const [priceLevels, setPriceLevels] = useState([]);

  const [selectedRole, setSelectedRole] = useState(null);
  const [assignedIds, setAssignedIds] = useState([]);

  const [roleSearch, setRoleSearch] = useState("");
  const [priceSearch, setPriceSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [loadingRolePrices, setLoadingRolePrices] = useState(false);
  const [saving, setSaving] = useState(false);

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
    if (Array.isArray(response?.roles)) return response.roles;
    if (Array.isArray(response?.data?.roles)) return response.data.roles;

    return [];
  };

  const loadBaseData = async () => {
    try {
      setLoading(true);

      const [rolesResponse, priceLevelsResponse] = await Promise.all([
        getRoles(),
        getPriceLevels(),
      ]);

      setRoles(normalizeList(rolesResponse));
      setPriceLevels(normalizeList(priceLevelsResponse));
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
      setSelectedRole(null);
      setAssignedIds([]);
      setRoleSearch("");
      setPriceSearch("");
      loadBaseData();
    }
  }, [open]);

  const filteredRoles = useMemo(() => {
    const q = roleSearch.toLowerCase();

    return roles.filter((role) => {
      return (
        role?.name?.toLowerCase().includes(q) ||
        role?.role_name?.toLowerCase().includes(q) ||
        role?.description?.toLowerCase().includes(q)
      );
    });
  }, [roles, roleSearch]);

  const filteredPriceLevels = useMemo(() => {
    const q = priceSearch.toLowerCase();

    return priceLevels.filter((level) => {
      return (
        level?.name?.toLowerCase().includes(q) ||
        level?.code?.toLowerCase().includes(q) ||
        level?.description?.toLowerCase().includes(q)
      );
    });
  }, [priceLevels, priceSearch]);

  const getRoleName = (role) => {
    return role?.name || role?.role_name || "Unnamed Role";
  };

  const handleSelectRole = async (role) => {
    try {
      setSelectedRole(role);
      setAssignedIds([]);
      setLoadingRolePrices(true);

      const response = await getRolePriceLevels(role.id);

      const ids =
        response?.data?.assigned_price_level_ids ||
        response?.assigned_price_level_ids ||
        [];

      setAssignedIds(ids);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Role Price Levels Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingRolePrices(false);
    }
  };

  const isChecked = (priceLevelId) => {
    return assignedIds.includes(priceLevelId);
  };

  const handleTogglePriceLevel = (priceLevelId) => {
    setAssignedIds((prev) => {
      if (prev.includes(priceLevelId)) {
        return prev.filter((id) => id !== priceLevelId);
      }

      return [...prev, priceLevelId];
    });
  };

  const handleSelectAll = () => {
    setAssignedIds(priceLevels.map((level) => level.id));
  };

  const handleClearAll = () => {
    setAssignedIds([]);
  };

  const handleSave = async () => {
    if (!selectedRole?.id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Select a role first.",
      });

      return;
    }

    try {
      setSaving(true);

      const response = await syncRolePriceLevels(selectedRole.id, assignedIds);

      showMessage({
        type: "success",
        title: "Price Levels Assigned",
        message: getApiMessage(
          response,
          "Role price levels updated successfully."
        ),
      });

      await handleSelectRole(selectedRole);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Save Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="ROLE PRICE LEVEL ASSIGNMENT"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#DFE5E5] overflow-hidden">
          <div className="h-full grid grid-cols-1 xl:grid-cols-[390px_1fr] gap-4">
            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col">
              <div className="h-10 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between shrink-0">
                <h2 className="text-[12px] font-black uppercase tracking-wide text-white">
                  Roles
                </h2>

                <span className="text-[10px] font-black text-[#00BCBC]">
                  {filteredRoles.length}
                </span>
              </div>

              <div className="p-2 border-b border-[#D7DFDE] shrink-0">
                <input
                  type="text"
                  value={roleSearch}
                  onChange={(e) => setRoleSearch(e.target.value)}
                  placeholder="Search roles..."
                  className="input-small"
                />
              </div>

              <div className="flex-1 min-h-0 overflow-auto">
                {loading ? (
                  <div className="p-6 text-center text-[12px] text-[#637877]">
                    Loading roles...
                  </div>
                ) : filteredRoles.length === 0 ? (
                  <div className="p-6 text-center text-[12px] text-[#637877]">
                    No roles found.
                  </div>
                ) : (
                  filteredRoles.map((role) => {
                    const selected = selectedRole?.id === role.id;

                    return (
                      <button
                        key={role.id}
                        type="button"
                        onClick={() => handleSelectRole(role)}
                        className={`w-full text-left border-b border-[#EFF2F2] px-3 py-2 ${
                          selected ? "bg-[#E8F7F5]" : "bg-white hover:bg-[#F8FAFC]"
                        }`}
                      >
                        <div className="text-[12px] font-black text-[#003636]">
                          {getRoleName(role)}
                        </div>

                        <div className="text-[10px] text-[#637877] mt-1">
                          {role.description || "No description"}
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            </section>

            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col">
              <div className="h-10 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between shrink-0">
                <div>
                  <h2 className="text-[12px] font-black uppercase tracking-wide text-white">
                    Price Levels
                  </h2>
                  <div className="text-[9px] font-bold text-[#00BCBC] uppercase">
                    {selectedRole
                      ? `Role: ${getRoleName(selectedRole)}`
                      : "Select a role"}
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    disabled={!selectedRole || loadingRolePrices}
                    onClick={handleSelectAll}
                    className="h-7 px-3 bg-white/10 text-white text-[10px] font-black uppercase disabled:opacity-50"
                  >
                    Select All
                  </button>

                  <button
                    type="button"
                    disabled={!selectedRole || loadingRolePrices}
                    onClick={handleClearAll}
                    className="h-7 px-3 bg-white/10 text-white text-[10px] font-black uppercase disabled:opacity-50"
                  >
                    Clear
                  </button>

                  <button
                    type="button"
                    disabled={!selectedRole || saving || loadingRolePrices}
                    onClick={handleSave}
                    className="h-7 px-3 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-50"
                  >
                    {saving ? "Saving" : "Sync"}
                  </button>
                </div>
              </div>

              <div className="h-10 border-b border-[#D7DFDE] bg-[#F8FAFC] px-2 grid grid-cols-1 md:grid-cols-[1fr_180px] gap-2 items-center shrink-0">
                <input
                  type="text"
                  value={priceSearch}
                  onChange={(e) => setPriceSearch(e.target.value)}
                  placeholder="Search price levels..."
                  className="input-small"
                />

                <div className="text-[10px] font-black uppercase text-[#324444]">
                  Assigned: {assignedIds.length} / {priceLevels.length}
                </div>
              </div>

              <div className="flex-1 min-h-0 overflow-auto">
                <table className="w-full border-collapse min-w-[900px]">
                  <thead className="sticky top-0 z-10">
                    <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                      <th className="text-left px-3 py-2 text-[11px] font-black uppercase w-[70px]">
                        Allow
                      </th>
                      <th className="text-left px-3 py-2 text-[11px] font-black uppercase">
                        Price Level
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
                    </tr>
                  </thead>

                  <tbody>
                    {!selectedRole ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-3 py-8 text-center text-[12px] text-[#637877]"
                        >
                          Select a role to assign price levels.
                        </td>
                      </tr>
                    ) : loadingRolePrices ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-3 py-8 text-center text-[12px] text-[#637877]"
                        >
                          Loading assigned price levels...
                        </td>
                      </tr>
                    ) : filteredPriceLevels.length === 0 ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-3 py-8 text-center text-[12px] text-[#637877]"
                        >
                          No price levels found.
                        </td>
                      </tr>
                    ) : (
                      filteredPriceLevels.map((level) => (
                        <tr
                          key={level.id}
                          className="border-b border-[#EFF2F2] hover:bg-[#F8FAFC]"
                        >
                          <td className="px-3 py-2">
                            <input
                              type="checkbox"
                              checked={isChecked(level.id)}
                              onChange={() => handleTogglePriceLevel(level.id)}
                              className="w-4 h-4"
                            />
                          </td>

                          <td className="px-3 py-2 text-[11px] font-bold">
                            {level.name}
                          </td>

                          <td className="px-3 py-2 text-[11px]">
                            {level.code || "-"}
                          </td>

                          <td className="px-3 py-2 text-[11px] max-w-[380px] truncate">
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

      .input-small:focus {
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

export default RolePriceLevelAssignModal;

// src/backOffice/pages/admin/UserManagment.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFilterBar from "../../../components/EnterpriseFilterBar";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseRowActionMenu from "../../../components/EnterpriseRowActionMenu";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { useAdminAuth } from "../../context/AdminAuthContext";
import { isSuperAdministrator } from "../../../utils/workspaceAccess";

import {
  getAuthorisedCompany,
  getRoles,
  getPosUsers,
  createPosUser,
  updatePosUser,
  deletePosUser,
} from "../../../services/AuthenticationService";
import { getShiftConfigurations } from "../../../services/GeneralSettingsService";

const emptyFormData = {
  id: null,
  role_id: "",
  name: "",
  username: "",
  phone: "",
  password: "",
  password_confirmation: "",
  login_code: "",
  login_code_confirmation: "",
  clear_login_code: false,
  employee_code: "",
  user_type: "cashier",
  can_login_pos: true,
  can_access_front_office: false,
  can_access_back_office: false,
  is_active: true,
  branch_ids: [],
  default_branch_id: "",
  shift_assignments: [],
};

const USER_TYPES = [
  { value: "super_admin", label: "Super Admin" },
  { value: "admin", label: "Admin" },
  { value: "manager", label: "Manager" },
  { value: "cashier", label: "Cashier" },
  { value: "supervisor", label: "Supervisor" },
];

const isSuperAdminName = (value) => ["super_admin", "super_administrator"].includes(
  String(value || "").trim().toLowerCase().replace(/[\s-]+/g, "_")
);

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

const normalizeUsersResponse = (response) => {
  const payload = response?.data;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;

  return [];
};

const normalizeRolesResponse = (response) => {
  if (Array.isArray(response?.data)) return response.data;
  if (Array.isArray(response?.data?.data)) return response.data.data;

  return [];
};

const normalizeShiftConfigurationsResponse = (response) => {
  if (Array.isArray(response?.data)) return response.data;
  if (Array.isArray(response?.data?.data)) return response.data.data;
  return [];
};

const getShiftOptions = (configurations) => configurations.flatMap((config) => {
  if (!config?.enable_shifts || config?.status === "inactive") return [];

  const names = [];
  if (config.shift_mode === "manual") {
    names.push("manual");
  } else {
    ["morning", "afternoon", "night"].forEach((name) => {
      if (config[`${name}_shift_start`] && config[`${name}_shift_end`]) names.push(name);
    });
  }
  if (config.allow_unscheduled_shifts) names.push("unscheduled");

  const branchName = config?.branch?.branch_name || config?.branch?.name || "All branches";
  return Array.from(new Set(names)).map((shiftName) => ({
    key: `${config.id}:${shiftName}`,
    shift_configuration_id: config.id,
    branch_id: config.branch_id || null,
    shift_name: shiftName,
    branch_name: branchName,
    start: config[`${shiftName}_shift_start`]?.slice?.(0, 5) || null,
    end: config[`${shiftName}_shift_end`]?.slice?.(0, 5) || null,
  }));
});

const UserManagementFullModal = ({ open, onClose }) => {
  const { user: currentAdmin } = useAdminAuth();
  const [users, setUsers] = useState([]);
  const [roles, setRoles] = useState([]);
  const [branches, setBranches] = useState([]);
  const [shiftConfigurations, setShiftConfigurations] = useState([]);

  const [loading, setLoading] = useState(false);
  const [savingUser, setSavingUser] = useState(false);
  const [deletingUserId, setDeletingUserId] = useState(null);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [branchSearch, setBranchSearch] = useState("");

  const [formOpen, setFormOpen] = useState(false);
  const [formData, setFormData] = useState(emptyFormData);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;
  const currentAdminIsSuper = isSuperAdministrator(currentAdmin);
  const availableRoles = useMemo(
    () => currentAdminIsSuper ? roles : roles.filter((role) => !isSuperAdminName(role?.name)),
    [roles, currentAdminIsSuper]
  );
  const availableUserTypes = useMemo(
    () => currentAdminIsSuper ? USER_TYPES : USER_TYPES.filter((type) => !isSuperAdminName(type.value)),
    [currentAdminIsSuper]
  );

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const getBranchName = (branch) =>
    branch?.branch_name || branch?.name || "Unnamed Branch";

  const getBranchCode = (branch) => branch?.branch_code || branch?.code || "";

  const selectedBranches = useMemo(() => {
    return branches.filter((branch) => formData.branch_ids.includes(branch.id));
  }, [branches, formData.branch_ids]);

  const shiftOptions = useMemo(
    () => getShiftOptions(shiftConfigurations),
    [shiftConfigurations]
  );

  const filteredBranches = useMemo(() => {
    const q = branchSearch.trim().toLowerCase();

    if (!q) return branches;

    return branches.filter((branch) => {
      return (
        getBranchName(branch).toLowerCase().includes(q) ||
        getBranchCode(branch).toLowerCase().includes(q)
      );
    });
  }, [branches, branchSearch]);

  const filteredUsers = useMemo(() => {
    const q = search.trim().toLowerCase();

    return users.filter((user) => {
      const roleName = user?.role?.name || "";
      const branchName =
        user?.branch?.branch_name ||
        user?.branch?.name ||
        user?.branches?.map((branch) => getBranchName(branch)).join(", ") ||
        "";

      const matchesSearch =
        !q ||
        user?.name?.toLowerCase().includes(q) ||
        user?.username?.toLowerCase().includes(q) ||
        user?.phone?.toLowerCase().includes(q) ||
        user?.employee_code?.toLowerCase().includes(q) ||
        roleName.toLowerCase().includes(q) ||
        branchName.toLowerCase().includes(q);

      const matchesStatus =
        statusFilter === "" ||
        String(Boolean(user?.is_active)) === String(statusFilter);

      const matchesRole =
        !roleFilter ||
        String(user?.role_id || user?.role?.id || "") === String(roleFilter);

      return matchesSearch && matchesStatus && matchesRole;
    });
  }, [users, search, statusFilter, roleFilter]);

  const activeCount = users.filter((user) => Boolean(user?.is_active)).length;
  const inactiveCount = users.length - activeCount;

  const loadBranches = async () => {
    const response = await getAuthorisedCompany();

    const company = response?.company || response?.data?.company || null;

    const companyBranches =
      response?.company?.branches ||
      response?.data?.branches ||
      response?.data?.company?.branches ||
      company?.branches ||
      [];

    setBranches(companyBranches);
  };

  const loadRoles = async () => {
    const response = await getRoles({ per_page: 100 });
    setRoles(normalizeRolesResponse(response));
  };

  const loadUsers = async () => {
    const response = await getPosUsers({ per_page: 200 });
    setUsers(normalizeUsersResponse(response));
  };

  const loadShiftConfigurations = async () => {
    const response = await getShiftConfigurations({ per_page: 200 });
    setShiftConfigurations(normalizeShiftConfigurationsResponse(response));
  };

  const loadData = async () => {
    try {
      setLoading(true);
      await Promise.all([loadBranches(), loadRoles(), loadUsers(), loadShiftConfigurations()]);
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
    if (open) loadData();
  }, [open]);

  const closeForm = () => {
    setFormOpen(false);
    setFormData(emptyFormData);
    setBranchSearch("");
  };

  const openCreateForm = () => {
    setFormData(emptyFormData);
    setBranchSearch("");
    setFormOpen(true);
  };

  const handleChange = (event) => {
    const { name, value, checked, type } = event.target;

    setFormData((prev) => {
      const next = {
        ...prev,
        [name]: type === "checkbox" ? checked : value,
      };

      const selectedRole = name === "role_id"
        ? roles.find((role) => String(role.id) === String(value))
        : roles.find((role) => String(role.id) === String(next.role_id));
      if (isSuperAdminName(next.user_type) || isSuperAdminName(selectedRole?.name)) {
        next.can_access_front_office = true;
        next.can_access_back_office = true;
      }

      return next;
    });
  };

  const handleBranchToggle = (branchId) => {
    setFormData((prev) => {
      const alreadySelected = prev.branch_ids.includes(branchId);

      const branchIds = alreadySelected
        ? prev.branch_ids.filter((id) => id !== branchId)
        : [...prev.branch_ids, branchId];

      return {
        ...prev,
        branch_ids: branchIds,
        default_branch_id: branchIds.includes(prev.default_branch_id)
          ? prev.default_branch_id
          : "",
        shift_assignments: prev.shift_assignments.filter((assignment) => {
          const option = shiftOptions.find(
            (item) => item.shift_configuration_id === assignment.shift_configuration_id
              && item.shift_name === assignment.shift_name
          );
          return !option?.branch_id || branchIds.includes(option.branch_id);
        }),
      };
    });
  };

  const handleShiftToggle = (option) => {
    setFormData((prev) => {
      const exists = prev.shift_assignments.some(
        (assignment) => assignment.shift_configuration_id === option.shift_configuration_id
          && assignment.shift_name === option.shift_name
      );

      return {
        ...prev,
        shift_assignments: exists
          ? prev.shift_assignments.filter(
              (assignment) => assignment.shift_configuration_id !== option.shift_configuration_id
                || assignment.shift_name !== option.shift_name
            )
          : [
              ...prev.shift_assignments,
              {
                shift_configuration_id: option.shift_configuration_id,
                shift_name: option.shift_name,
              },
            ],
      };
    });
  };

  const buildPayload = () => {
    // The user's home shop travels as default_branch_id, never branch_id: the
    // API reads a top-level branch_id as the active workspace branch and
    // rejects it when it differs from the shop this session is signed into.
    const defaultBranchId =
      formData.default_branch_id || formData.branch_ids?.[0] || null;

    const payload = {
      role_id: formData.role_id || null,
      name: formData.name.trim(),
      username: formData.username.trim(),
      phone: formData.phone || null,
      employee_code: formData.employee_code || null,
      user_type: formData.user_type,
      can_login_pos: !!formData.can_login_pos,
      can_access_front_office: !!formData.can_access_front_office,
      can_access_back_office: !!formData.can_access_back_office,
      is_active: !!formData.is_active,
      branch_ids: formData.branch_ids,
      default_branch_id: defaultBranchId,
      shift_assignments: formData.shift_assignments,
    };

    const hasPassword = Boolean(String(formData.password || "").trim());
    const hasLoginCode = Boolean(String(formData.login_code || "").trim());

    if (hasPassword) {
      payload.password = formData.password;
      payload.password_confirmation = formData.password_confirmation;
    }

    if (hasLoginCode) {
      payload.login_code = formData.login_code;
      payload.login_code_confirmation = formData.login_code_confirmation;
    }

    if (isEditing && formData.clear_login_code) {
      payload.clear_login_code = true;
    }

    return payload;
  };

  const validateForm = () => {
    if (!formData.name.trim()) {
      showMessage({ type: "error", title: "Validation", message: "Full name is required." });
      return false;
    }

    if (!formData.username.trim()) {
      showMessage({ type: "error", title: "Validation", message: "Username is required." });
      return false;
    }

    if (!formData.role_id) {
      showMessage({ type: "error", title: "Validation", message: "Select a role for this user." });
      return false;
    }

    const hasPassword = Boolean(String(formData.password || "").trim());
    const hasLoginCode = Boolean(String(formData.login_code || "").trim());

    if (!isEditing && !hasPassword && !hasLoginCode) {
      showMessage({ type: "error", title: "Validation", message: "Enter a password or a login code." });
      return false;
    }

    if (hasPassword && formData.password.length < 6) {
      showMessage({ type: "error", title: "Validation", message: "Password must be at least 6 characters." });
      return false;
    }

    if (hasPassword && formData.password !== formData.password_confirmation) {
      showMessage({ type: "error", title: "Validation", message: "Password confirmation does not match." });
      return false;
    }

    if (hasLoginCode && (formData.login_code.length < 4 || formData.login_code.length > 12)) {
      showMessage({ type: "error", title: "Validation", message: "Login code must be 4 to 12 characters. The server will apply the effective policy for the assigned scope." });
      return false;
    }

    if (hasLoginCode && formData.login_code !== formData.login_code_confirmation) {
      showMessage({ type: "error", title: "Validation", message: "Login code confirmation does not match." });
      return false;
    }

    if (formData.branch_ids.length > 0 && !formData.default_branch_id) {
      showMessage({ type: "error", title: "Validation", message: "Select a default branch." });
      return false;
    }

    const hasConfiguredShifts = shiftOptions.some(
      (option) => !option.branch_id || formData.branch_ids.includes(option.branch_id)
    );
    const selectedRole = roles.find((role) => String(role.id) === String(formData.role_id));
    const isSuperAdminSelection = isSuperAdminName(formData.user_type) || isSuperAdminName(selectedRole?.name);
    if (
      formData.can_access_front_office
      && !isSuperAdminSelection
      && hasConfiguredShifts
      && formData.shift_assignments.length === 0
    ) {
      showMessage({ type: "error", title: "Validation", message: "Assign at least one shift before granting Front Office access." });
      return false;
    }

    return true;
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (!validateForm()) return;

    try {
      setSavingUser(true);
      const payload = buildPayload();

      const response = isEditing
        ? await updatePosUser(formData.id, payload)
        : await createPosUser(payload);

      showMessage({
        type: "success",
        title: isEditing ? "Updated" : "Created",
        message: getApiMessage(response, isEditing ? "User updated." : "User created."),
      });

      closeForm();
      await loadUsers();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingUser(false);
    }
  };

  const handleEdit = (user) => {
    const userBranches = Array.isArray(user?.branches) ? user.branches : [];

    const branchIds =
      userBranches.length > 0
        ? userBranches.map((branch) => branch.id)
        : user?.branch_id
          ? [user.branch_id]
          : [];

    const defaultBranch =
      userBranches.find((branch) => branch?.pivot?.is_default)?.id ||
      user?.default_branch_id ||
      user?.branch_id ||
      branchIds[0] ||
      "";

    setFormData({
      id: user.id,
      role_id: user.role_id || user.role?.id || "",
      name: user.name || "",
      username: user.username || "",
      phone: user.phone || "",
      password: "",
      password_confirmation: "",
      login_code: "",
      login_code_confirmation: "",
      clear_login_code: false,
      employee_code: user.employee_code || "",
      user_type: user.user_type || "cashier",
      can_login_pos: user.can_login_pos ?? true,
      can_access_front_office: user.can_access_front_office ?? false,
      can_access_back_office: user.can_access_back_office ?? false,
      is_active: user.is_active ?? true,
      branch_ids: branchIds,
      default_branch_id: defaultBranch,
      shift_assignments: (user?.shift_assignments || [])
        .filter((assignment) => shiftOptions.some(
          (option) => String(option.shift_configuration_id) === String(assignment.shift_configuration_id)
            && option.shift_name === assignment.shift_name
            && (!option.branch_id || branchIds.includes(option.branch_id))
        ))
        .map((assignment) => ({
          shift_configuration_id: assignment.shift_configuration_id,
          shift_name: assignment.shift_name,
        })),
    });

    setBranchSearch("");
    setFormOpen(true);
  };

  const handleDelete = async (userId) => {
    try {
      setDeletingUserId(userId);
      const response = await deletePosUser(userId);

      showMessage({
        type: "success",
        title: "Deleted",
        message: getApiMessage(response, "User deleted."),
      });

      await loadUsers();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingUserId(null);
      setDeleteTarget(null);
    }
  };

  const getUserBranchDisplay = (user) => {
    if (Array.isArray(user?.branches) && user.branches.length > 0) {
      return user.branches.map((branch) => getBranchName(branch)).join(", ");
    }

    if (user?.branch) return getBranchName(user.branch);

    return "-";
  };

  const getUserRoleDisplay = (user) => user?.role?.name || "-";

  const getDefaultBranchDisplay = (user) => {
    const branch =
      user?.branches?.find((item) => item?.pivot?.is_default) ||
      user?.branch ||
      null;

    return branch ? getBranchName(branch) : "-";
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="USER MANAGEMENT"
        subtitle="Users and access"
      >
        <div className="flex h-full min-h-0 flex-col border border-[#CAD5D5] bg-white">
          <EnterpriseFilterBar
            leading={(
              <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={openCreateForm}
                className="h-8 border border-[#008A8A] bg-[#00BCBC] px-3 text-[11px] font-semibold uppercase tracking-wide text-[#004141]"
              >
                New User
              </button>

              <button
                type="button"
                onClick={loadData}
                disabled={loading}
                className="h-8 border border-[#ACB8B8] bg-white px-3 text-[11px] font-semibold uppercase tracking-wide text-[#004141] disabled:opacity-60"
              >
                {loading ? "Loading" : "Refresh"}
              </button>
              </div>
            )}
            searchValue={search}
            onSearchChange={setSearch}
            searchLabel="Search users"
            searchPlaceholder="Search users"
            actions={<div className="border border-[#D8E1E0] bg-white px-3 py-2 text-[11px] text-[#324444]">{users.length} users · {activeCount} active · {inactiveCount} inactive</div>}
          >
              <label>
                <span className="mb-1 block text-[10px] font-semibold text-[#556767]">Role</span>
              <select
                value={roleFilter}
                onChange={(event) => setRoleFilter(event.target.value)}
                className="h-8 border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
              >
                <option value="">All Roles</option>
                {roles.map((role) => (
                  <option key={role.id} value={role.id}>
                    {role.name}
                  </option>
                ))}
              </select>
              </label>
              <label>
                <span className="mb-1 block text-[10px] font-semibold text-[#556767]">Status</span>
              <select
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value)}
                className="h-8 border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
              >
                <option value="">All Status</option>
                <option value="true">Active</option>
                <option value="false">Inactive</option>
              </select>
              </label>
          </EnterpriseFilterBar>

          <div className="min-h-0 flex-1 overflow-auto">
            <table className="min-w-[1360px] w-full border-collapse">
              <thead className="sticky top-0 z-10">
                <tr className="border-b border-[#D8E1E0] bg-[#EFF3F3] text-[#324444]">
                  <TableHead>Name</TableHead>
                  <TableHead>Username</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Default Branch</TableHead>
                  <TableHead>Branches</TableHead>
                  <TableHead>Shifts</TableHead>
                  <TableHead>Workspaces</TableHead>
                  <TableHead>POS Login</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead align="right">More</TableHead>
                </tr>
              </thead>

              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan={11} className="px-3 py-10 text-center text-[12px] text-[#637877]">
                      Loading users...
                    </td>
                  </tr>
                ) : filteredUsers.length === 0 ? (
                  <tr>
                    <td colSpan={11} className="px-3 py-10 text-center text-[12px] text-[#637877]">
                      No users found.
                    </td>
                  </tr>
                ) : (
                  filteredUsers.map((user) => (
                    <tr
                      key={user.id}
                      className="border-b border-[#EFF2F2] text-[12px] text-[#1E2B2B] hover:bg-[#F8FAFC]"
                    >
                      <TableCell>
                        <div className="font-medium text-[#091B1B]">{user.name || "-"}</div>
                        <div className="mt-0.5 text-[10px] text-[#637877]">
                          {user.employee_code || "No employee code"}
                        </div>
                      </TableCell>

                      <TableCell>{user.username || "-"}</TableCell>
                      <TableCell>{getUserRoleDisplay(user)}</TableCell>
                      <TableCell>{user.user_type || "-"}</TableCell>
                      <TableCell>{getDefaultBranchDisplay(user)}</TableCell>

                      <TableCell>
                        <div className="max-w-[320px] truncate" title={getUserBranchDisplay(user)}>
                          {getUserBranchDisplay(user)}
                        </div>
                      </TableCell>

                      <TableCell>
                        <div className="max-w-[220px] truncate" title={(user.shift_assignments || []).map((item) => item.shift_name).join(", ")}>
                          {user.shift_assignments?.length
                            ? `${user.shift_assignments.length} — ${user.shift_assignments.map((item) => item.shift_name).join(", ")}`
                            : "None"}
                        </div>
                      </TableCell>

                      <TableCell>
                        <div className="flex flex-wrap gap-1">
                          <WorkspacePill active={user.can_access_front_office} label="Front" />
                          <WorkspacePill active={user.can_access_back_office} label="Back" />
                        </div>
                      </TableCell>

                      <TableCell>
                        <StatusPill active={user.can_login_pos} yes="Allowed" no="Blocked" />
                      </TableCell>

                      <TableCell>
                        <StatusPill active={user.is_active} yes="Active" no="Inactive" />
                      </TableCell>

                      <TableCell align="right">
                        <EnterpriseRowActionMenu
                          label={`More actions for ${user.name || user.username}`}
                          actions={[
                            { label: "Edit user", onClick: () => handleEdit(user) },
                            { label: deletingUserId === user.id ? "Deleting user" : "Delete user", danger: true, disabled: deletingUserId === user.id, onClick: () => setDeleteTarget(user) },
                          ]}
                        />
                      </TableCell>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </FullScreenModal>

      {formOpen && (
        <UserFormDialog
          isEditing={isEditing}
          formData={formData}
          setFormData={setFormData}
          roles={availableRoles}
          userTypes={availableUserTypes}
          branches={branches}
          selectedBranches={selectedBranches}
          filteredBranches={filteredBranches}
          branchSearch={branchSearch}
          setBranchSearch={setBranchSearch}
          handleChange={handleChange}
          handleBranchToggle={handleBranchToggle}
          handleSubmit={handleSubmit}
          savingUser={savingUser}
          onClose={closeForm}
          getBranchName={getBranchName}
          getBranchCode={getBranchCode}
          shiftOptions={shiftOptions}
          handleShiftToggle={handleShiftToggle}
        />
      )}

      <EnterpriseConfirmModal
        open={Boolean(deleteTarget)}
        title="Delete User"
        message={deleteTarget ? `Delete ${deleteTarget.name || deleteTarget.username}?` : ""}
        confirmLabel="Delete user"
        busy={Boolean(deletingUserId)}
        onClose={() => !deletingUserId && setDeleteTarget(null)}
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

const TableHead = ({ children, align = "left" }) => (
  <th
    className={`border-r border-[#D8E1E0] px-3 py-2 text-[11px] font-medium uppercase tracking-wide last:border-r-0 ${
      align === "right" ? "text-right" : "text-left"
    }`}
  >
    {children}
  </th>
);

const TableCell = ({ children, align = "left" }) => (
  <td
    className={`border-r border-[#EFF2F2] px-3 py-2 align-middle last:border-r-0 ${
      align === "right" ? "text-right" : "text-left"
    }`}
  >
    {children}
  </td>
);

const StatusPill = ({ active, yes = "Yes", no = "No" }) => (
  <span
    className={`inline-flex min-w-[70px] justify-center border px-2 py-1 text-[10px] font-medium ${
      active
        ? "border-[#86EFAC] bg-[#F0FDF4] text-[#166534]"
        : "border-[#FCA5A5] bg-[#FEF2F2] text-[#B42318]"
    }`}
  >
    {active ? yes : no}
  </span>
);

const UserFormDialog = ({
  isEditing,
  formData,
  setFormData,
  roles,
  userTypes,
  branches,
  selectedBranches,
  filteredBranches,
  branchSearch,
  setBranchSearch,
  handleChange,
  handleBranchToggle,
  handleSubmit,
  savingUser,
  onClose,
  getBranchName,
  getBranchCode,
  shiftOptions,
  handleShiftToggle,
}) => {
  const selectedRole = roles.find((role) => String(role.id) === String(formData.role_id));
  const isSuperAdminSelection = isSuperAdminName(formData.user_type) || isSuperAdminName(selectedRole?.name);
  const eligibleShiftOptions = shiftOptions.filter(
    (option) => !option.branch_id || formData.branch_ids.includes(option.branch_id)
  );

  return (
    <EnterpriseFormModal
      open
      title={isEditing ? "Edit User" : "New User"}
      onClose={onClose}
      busy={savingUser}
    >
      <form
        onSubmit={handleSubmit}
        className="flex min-h-0 flex-col"
      >
        <div className="min-h-0 flex-1">
          <div className="grid grid-cols-12 gap-3">
            <Field label="Full Name" span="4">
              <input name="name" value={formData.name} onChange={handleChange} className="user-input" />
            </Field>

            <Field label="Username" span="4">
              <input name="username" value={formData.username} onChange={handleChange} className="user-input" />
            </Field>

            <Field label="Phone" span="4">
              <input name="phone" value={formData.phone} onChange={handleChange} className="user-input" />
            </Field>

            <Field label="Employee Code" span="3">
              <input name="employee_code" value={formData.employee_code} onChange={handleChange} className="user-input" />
            </Field>

            <Field label="Role" span="3">
              <select name="role_id" value={formData.role_id} onChange={handleChange} className="user-input bg-white">
                <option value="">Select Role</option>
                {roles.map((role) => (
                  <option key={role.id} value={role.id}>
                    {role.name}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="User Type" span="3">
              <select name="user_type" value={formData.user_type} onChange={handleChange} className="user-input bg-white">
                {userTypes.map((item) => (
                  <option key={item.value} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </select>
            </Field>

            <div className="col-span-3 grid grid-cols-2 gap-2 pt-5">
              <CheckBox name="can_login_pos" checked={formData.can_login_pos} onChange={handleChange} label="POS Login" />
              <CheckBox name="is_active" checked={formData.is_active} onChange={handleChange} label="Active" />
            </div>

            <div className="col-span-12 border border-[#BCC7C7] bg-[#F8FAFC]">
              <div className="border-b border-[#D8E1E0] px-3 py-2">
                <div className="text-[11px] font-semibold uppercase tracking-wide text-[#2B3C3C]">
                  Workspace Access
                </div>
                <div className="mt-0.5 text-[11px] text-[#637877]">
                  Select exactly where this user is allowed to sign in. A user with neither option selected cannot enter either workspace.
                </div>
              </div>

              <div className="grid grid-cols-1 gap-2 p-3 md:grid-cols-2">
                <CheckBox
                  name="can_access_front_office"
                  checked={formData.can_access_front_office}
                  onChange={handleChange}
                  disabled={isSuperAdminSelection}
                  label="Front Office — sales, till and cashier work"
                />
                <CheckBox
                  name="can_access_back_office"
                  checked={formData.can_access_back_office}
                  onChange={handleChange}
                  disabled={isSuperAdminSelection}
                  label="Back Office — management and reporting"
                />
              </div>

              {isSuperAdminSelection && (
                <div className="border-t border-[#D8E1E0] bg-[#E8F7F5] px-3 py-2 text-[11px] text-[#006C6C]">
                  Super administrators always have access to both workspaces.
                </div>
              )}
            </div>

            <div className="col-span-12 border border-[#D8E1E0]">
              <div className="border-b border-[#D8E1E0] bg-[#F8FAFC] px-3 py-2 text-[11px] font-medium uppercase tracking-wide text-[#324444]">
                Branch Access
              </div>

              <div className="grid grid-cols-12 gap-3 p-3">
                <div className="col-span-12 md:col-span-5">
                  <input
                    value={branchSearch}
                    onChange={(event) => setBranchSearch(event.target.value)}
                    placeholder="Search branches"
                    className="mb-2 h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#00BCBC]"
                  />

                  <div className="h-[190px] overflow-auto border border-[#D8E1E0]">
                    {filteredBranches.length === 0 ? (
                      <div className="px-3 py-6 text-[12px] text-[#637877]">No branches found.</div>
                    ) : (
                      filteredBranches.map((branch) => {
                        const checked = formData.branch_ids.includes(branch.id);

                        return (
                          <label
                            key={branch.id}
                            className="flex cursor-pointer items-center gap-2 border-b border-[#EFF2F2] px-3 py-2 text-[12px] hover:bg-[#F8FAFC]"
                          >
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => handleBranchToggle(branch.id)}
                              className="h-3.5 w-3.5"
                            />
                            <span className="min-w-0 flex-1 truncate">{getBranchName(branch)}</span>
                            {getBranchCode(branch) && (
                              <span className="text-[10px] text-[#637877]">{getBranchCode(branch)}</span>
                            )}
                          </label>
                        );
                      })
                    )}
                  </div>
                </div>

                <div className="col-span-12 md:col-span-4">
                  <div className="mb-2 text-[11px] font-medium uppercase tracking-wide text-[#324444]">
                    Selected Branches
                  </div>

                  <div className="h-[190px] overflow-auto border border-[#D8E1E0] bg-white">
                    {selectedBranches.length === 0 ? (
                      <div className="px-3 py-6 text-[12px] text-[#637877]">No branch selected.</div>
                    ) : (
                      selectedBranches.map((branch) => (
                        <div
                          key={branch.id}
                          className="flex items-center justify-between gap-2 border-b border-[#EFF2F2] px-3 py-2 text-[12px]"
                        >
                          <span className="min-w-0 truncate">{getBranchName(branch)}</span>
                          <button
                            type="button"
                            onClick={() => handleBranchToggle(branch.id)}
                            className="text-[13px] text-[#B42318]"
                          >
                            x
                          </button>
                        </div>
                      ))
                    )}
                  </div>
                </div>

                <Field label="Default Branch" span="3">
                  <select
                    name="default_branch_id"
                    value={formData.default_branch_id}
                    onChange={handleChange}
                    disabled={formData.branch_ids.length === 0}
                    className="user-input bg-white disabled:bg-[#F1F5F5] disabled:text-[#93A6A6]"
                  >
                    <option value="">Select Default</option>
                    {formData.branch_ids.map((branchId) => {
                      const branch = branches.find((item) => item.id === branchId);

                      return (
                        <option key={branchId} value={branchId}>
                          {getBranchName(branch)}
                        </option>
                      );
                    })}
                  </select>
                </Field>
              </div>
            </div>

            <div className="col-span-12 border border-[#D8E1E0] bg-white">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#D8E1E0] bg-[#F8FAFC] px-3 py-2">
                <div>
                  <div className="text-[11px] font-medium uppercase tracking-wide text-[#324444]">
                    Shift Assignments
                  </div>
                  <div className="mt-0.5 text-[10px] text-[#637877]">
                    A Front Office user may work only during the shifts selected here. Multiple shifts are allowed.
                  </div>
                </div>
                <span className="border border-[#BCC7C7] bg-white px-2 py-1 text-[10px] font-semibold text-[#324444]">
                  {formData.shift_assignments.length} selected
                </span>
              </div>

              {eligibleShiftOptions.length === 0 ? (
                <div className="px-3 py-5 text-[11px] text-[#637877]">
                  No active shifts are configured for the selected branches. Configure shifts first, or leave Front Office access disabled.
                </div>
              ) : (
                <div className="grid max-h-[240px] grid-cols-1 gap-2 overflow-auto p-3 md:grid-cols-2 xl:grid-cols-3">
                  {eligibleShiftOptions.map((option) => {
                    const checked = formData.shift_assignments.some(
                      (assignment) => assignment.shift_configuration_id === option.shift_configuration_id
                        && assignment.shift_name === option.shift_name
                    );
                    const shiftLabel = option.shift_name.charAt(0).toUpperCase() + option.shift_name.slice(1);

                    return (
                      <label
                        key={option.key}
                        className={`flex cursor-pointer items-start gap-2 border px-3 py-2.5 ${
                          checked
                            ? "border-[#63B7B7] bg-[#E8F7F5]"
                            : "border-[#D8E1E0] bg-white hover:bg-[#F8FAFC]"
                        }`}
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => handleShiftToggle(option)}
                          className="mt-0.5 h-3.5 w-3.5"
                        />
                        <span className="min-w-0">
                          <span className="block text-[11px] font-semibold text-[#2B3C3C]">{shiftLabel}</span>
                          <span className="mt-0.5 block truncate text-[10px] text-[#637877]">{option.branch_name}</span>
                          {option.start && option.end && (
                            <span className="mt-0.5 block font-mono text-[9px] text-[#7F908F]">{option.start}–{option.end}</span>
                          )}
                        </span>
                      </label>
                    );
                  })}
                </div>
              )}

              {formData.can_access_front_office
                && !isSuperAdminSelection
                && eligibleShiftOptions.length > 0
                && formData.shift_assignments.length === 0 && (
                  <div className="border-t border-[#F3C07A] bg-[#FFF5E8] px-3 py-2 text-[11px] text-[#8A5200]">
                    Select at least one shift. This user will be blocked from Front Office until a shift is assigned.
                  </div>
                )}
            </div>

            <Field label="Password" span="3">
              <input
                type="password"
                name="password"
                value={formData.password}
                onChange={handleChange}
                placeholder={isEditing ? "Leave blank" : ""}
                className="user-input"
              />
            </Field>

            <Field label="Confirm Password" span="3">
              <input
                type="password"
                name="password_confirmation"
                value={formData.password_confirmation}
                onChange={handleChange}
                className="user-input"
              />
            </Field>

            <Field label="Login Code (Policy Controlled)" span="3">
              <input
                type="password"
                maxLength={12}
                name="login_code"
                value={formData.login_code}
                onChange={(event) => {
                  const next = event.target.value.slice(0, 12);
                  setFormData((prev) => ({
                    ...prev,
                    login_code: next,
                    clear_login_code: next ? false : prev.clear_login_code,
                  }));
                }}
                className="user-input text-center tracking-[0.35em]"
              />
            </Field>

            <Field label="Confirm Code" span="3">
              <input
                type="password"
                maxLength={12}
                name="login_code_confirmation"
                value={formData.login_code_confirmation}
                onChange={(event) => {
                  const next = event.target.value.slice(0, 12);
                  setFormData((prev) => ({
                    ...prev,
                    login_code_confirmation: next,
                  }));
                }}
                className="user-input text-center tracking-[0.35em]"
              />
            </Field>

            {isEditing && (
              <div className="col-span-12">
                <CheckBox
                  name="clear_login_code"
                  checked={formData.clear_login_code}
                  onChange={handleChange}
                  label="Clear current login code"
                />
              </div>
            )}
          </div>
        </div>

        <div className="flex h-12 shrink-0 items-center justify-end gap-2 border-t border-[#D8E1E0] bg-[#F8FAFC] px-4">
          <button
            type="button"
            onClick={onClose}
            className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-medium uppercase tracking-wide text-[#004141]"
          >
            Cancel
          </button>

          <button
            type="submit"
            disabled={savingUser}
            className="h-8 border border-[#008A8A] bg-[#00BCBC] px-4 text-[11px] font-semibold uppercase tracking-wide text-[#004141] disabled:opacity-60"
          >
            {savingUser ? "Saving" : isEditing ? "Update" : "Save"}
          </button>
        </div>

        <style>{`
          .user-input {
            height: 36px;
            width: 100%;
            border: 1px solid #ACB8B8;
            background: #ffffff;
            padding: 0 8px;
            font-size: 12px;
            outline: none;
          }

          .user-input:focus {
            border-color: #00BCBC;
          }
        `}</style>
      </form>
    </EnterpriseFormModal>
  );
};

const Field = ({ label, span = "3", children }) => {
  const spanClass = {
    3: "col-span-12 md:col-span-3",
    4: "col-span-12 md:col-span-4",
    6: "col-span-12 md:col-span-6",
    12: "col-span-12",
  }[span] || "col-span-12 md:col-span-3";

  return (
    <label className={spanClass}>
      <div className="mb-1 text-[11px] font-medium uppercase tracking-wide text-[#324444]">
        {label}
      </div>
      {children}
    </label>
  );
};

const WorkspacePill = ({ active, label }) => (
  <span
    className={`border px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide ${
      active
        ? "border-[#8AD2D1] bg-[#E8F7F5] text-[#006C6C]"
        : "border-[#D8E1E0] bg-[#F8FAFC] text-[#93A6A6]"
    }`}
  >
    {label}
  </span>
);

const CheckBox = ({ name, checked, onChange, label, disabled = false }) => (
  <label className={`flex min-h-9 items-center gap-2 border border-[#D8E1E0] bg-white px-2 py-2 text-[12px] text-[#324444] ${disabled ? "opacity-70" : "cursor-pointer"}`}>
    <input
      type="checkbox"
      name={name}
      checked={checked}
      onChange={onChange}
      disabled={disabled}
      className="h-3.5 w-3.5"
    />
    {label}
  </label>
);

export default UserManagementFullModal;

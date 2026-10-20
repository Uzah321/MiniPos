import React, { useEffect, useMemo, useState } from "react";
import {
  Check,
  ChevronDown,
  ChevronRight,
  Layers3,
  LockKeyhole,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  Search,
  ShieldCheck,
  Trash2,
  Undo2,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import {
  createRole,
  deleteRole,
  getPermissions,
  getRoles,
  syncRolePermissions,
  updateRole,
} from "../../../services/AuthenticationService";

const emptyRoleForm = { id: null, name: "", group: "", description: "" };
const normalizeName = (value) => String(value || "").trim().toLowerCase().replace(/[\s-]+/g, "_");
const isSystemRole = (role) => ["super_admin", "super_administrator"].includes(normalizeName(role?.name));
const rolePermissionIds = (role) => (Array.isArray(role?.permissions) ? role.permissions : []).map((item) => String(item.id));
const sameIds = (left, right) => left.length === right.length && left.every((id) => new Set(right).has(id));
const titleCase = (value) => String(value || "General").replace(/[_-]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());

const getApiMessage = (source, fallback = "Request failed.") => {
  const data = source?.response?.data;
  if (data?.errors && typeof data.errors === "object") return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || source?.data?.message || source?.data?.error || source?.message || fallback;
};

const Roles = ({ open, onClose }) => {
  const [roles, setRoles] = useState([]);
  const [permissions, setPermissions] = useState([]);
  const [selectedRoleId, setSelectedRoleId] = useState(null);
  const [draftIds, setDraftIds] = useState([]);
  const [savedIds, setSavedIds] = useState([]);
  const [roleSearch, setRoleSearch] = useState("");
  const [permissionSearch, setPermissionSearch] = useState("");
  const [openGroups, setOpenGroups] = useState({});
  const [loading, setLoading] = useState(false);
  const [savingPermissions, setSavingPermissions] = useState(false);
  const [savingRole, setSavingRole] = useState(false);
  const [deletingRoleId, setDeletingRoleId] = useState(null);
  const [formOpen, setFormOpen] = useState(false);
  const [roleForm, setRoleForm] = useState(emptyRoleForm);
  const [messageModal, setMessageModal] = useState({ open: false, type: "success", title: "", message: "" });

  const selectedRole = useMemo(
    () => roles.find((role) => String(role.id) === String(selectedRoleId)) || null,
    [roles, selectedRoleId]
  );
  const protectedRole = isSystemRole(selectedRole);
  const isDirty = !protectedRole && !sameIds(draftIds, savedIds);
  const showMessage = (message) => setMessageModal({ open: true, type: "success", ...message });

  const filteredRoles = useMemo(() => {
    const query = roleSearch.trim().toLowerCase();
    if (!query) return roles;
    return roles.filter((role) => [role.name, role.group, role.description].filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(query)));
  }, [roles, roleSearch]);

  const permissionGroups = useMemo(() => {
    const query = permissionSearch.trim().toLowerCase();
    const groups = {};
    permissions.forEach((permission) => {
      const group = permission.group || "general";
      const matches = !query || group.toLowerCase().includes(query) || [permission.name, permission.code, permission.description]
        .filter(Boolean).some((value) => String(value).toLowerCase().includes(query));
      if (!matches) return;
      if (!groups[group]) groups[group] = [];
      groups[group].push(permission);
    });
    return Object.entries(groups).sort(([left], [right]) => left.localeCompare(right));
  }, [permissions, permissionSearch]);

  const visibleIds = useMemo(
    () => permissionGroups.flatMap(([, items]) => items.map((item) => String(item.id))),
    [permissionGroups]
  );
  const moduleCount = useMemo(() => new Set(permissions.map((item) => item.group || "general")).size, [permissions]);
  const visibleAllSelected = visibleIds.length > 0 && visibleIds.every((id) => draftIds.includes(id));

  const loadData = async (preferredRoleId = null) => {
    try {
      setLoading(true);
      const [rolesResponse, permissionsResponse] = await Promise.all([
        getRoles({ per_page: 200 }),
        getPermissions(),
      ]);
      const nextRoles = Array.isArray(rolesResponse?.data) ? rolesResponse.data : rolesResponse?.data?.data || [];
      const nextPermissions = Array.isArray(permissionsResponse?.data)
        ? permissionsResponse.data
        : permissionsResponse?.data?.data || [];
      setRoles(nextRoles);
      setPermissions(nextPermissions);
      const wantedId = preferredRoleId || selectedRoleId;
      const nextSelected = nextRoles.find((role) => String(role.id) === String(wantedId)) || nextRoles[0];
      setSelectedRoleId(nextSelected?.id || null);
    } catch (error) {
      showMessage({ type: "error", title: "Unable to Load Access Control", message: getApiMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!selectedRole) {
      setDraftIds([]);
      setSavedIds([]);
      return;
    }
    const ids = isSystemRole(selectedRole) ? permissions.map((item) => String(item.id)) : rolePermissionIds(selectedRole);
    setDraftIds(ids);
    setSavedIds(ids);
  }, [selectedRole, permissions]);

  const selectRole = async (roleId) => {
    if (String(roleId) === String(selectedRoleId)) return;
    if (isDirty && !await requestEnterpriseConfirmation({
      title: "Discard Changes",
      message: "Discard the unsaved permission changes?",
      confirmLabel: "Discard changes",
    })) return;
    setSelectedRoleId(roleId);
    setPermissionSearch("");
  };

  const openEditRole = (role) => {
    if (isSystemRole(role)) return;
    setRoleForm({ id: role.id, name: role.name || "", group: role.group || "", description: role.description || "" });
    setFormOpen(true);
  };

  const closeRoleForm = () => {
    if (savingRole) return;
    setRoleForm(emptyRoleForm);
    setFormOpen(false);
  };

  const saveRole = async (event) => {
    event.preventDefault();
    try {
      setSavingRole(true);
      const editingId = roleForm.id;
      const payload = { name: roleForm.name.trim(), group: roleForm.group.trim(), description: roleForm.description.trim() };
      const response = editingId ? await updateRole(editingId, payload) : await createRole(payload);
      const nextId = editingId || response?.data?.id;
      setRoleForm(emptyRoleForm);
      setFormOpen(false);
      await loadData(nextId);
      showMessage({ title: editingId ? "Role Updated" : "Role Created", message: getApiMessage(response, "Role saved successfully.") });
    } catch (error) {
      showMessage({ type: "error", title: "Role Save Failed", message: getApiMessage(error) });
    } finally {
      setSavingRole(false);
    }
  };

  const removeRole = async (role) => {
    if (isSystemRole(role) || !await requestEnterpriseConfirmation({
      title: "Delete Role",
      message: `Delete the “${role.name}” role? This cannot be undone.`,
      confirmLabel: "Delete role",
    })) return;
    try {
      setDeletingRoleId(role.id);
      const response = await deleteRole(role.id);
      if (String(selectedRoleId) === String(role.id)) setSelectedRoleId(null);
      await loadData();
      showMessage({ title: "Role Deleted", message: getApiMessage(response, "Role deleted successfully.") });
    } catch (error) {
      showMessage({ type: "error", title: "Delete Failed", message: getApiMessage(error) });
    } finally {
      setDeletingRoleId(null);
    }
  };

  const togglePermission = (permissionId) => {
    if (protectedRole || savingPermissions) return;
    const id = String(permissionId);
    setDraftIds((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id]);
  };

  const setPermissionSelection = (permissionIds, select) => {
    if (protectedRole || savingPermissions) return;
    const ids = permissionIds.map(String);
    setDraftIds((current) => select
      ? Array.from(new Set([...current, ...ids]))
      : current.filter((id) => !ids.includes(id)));
  };

  const savePermissions = async () => {
    if (!selectedRole || protectedRole || !isDirty) return;
    try {
      setSavingPermissions(true);
      const response = await syncRolePermissions(selectedRole.id, draftIds);
      const selected = new Set(draftIds);
      setRoles((current) => current.map((role) => String(role.id) === String(selectedRole.id)
        ? { ...role, permissions: permissions.filter((item) => selected.has(String(item.id))) }
        : role));
      setSavedIds(draftIds);
      showMessage({ title: "Permissions Saved", message: getApiMessage(response, "Role permissions updated successfully.") });
    } catch (error) {
      showMessage({ type: "error", title: "Permission Save Failed", message: getApiMessage(error) });
    } finally {
      setSavingPermissions(false);
    }
  };

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} confirmOnClose={isDirty} title="Roles & Permissions" subtitle="Access control workspace">
        <div className="flex h-full min-h-0 flex-col bg-[#EFF3F3]">
          <header className="shrink-0 border-b border-[#CAD5D5] bg-white px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h1 className="text-[17px] font-semibold text-[#172B2B]">Access control</h1>
                <p className="mt-0.5 text-[11px] text-[#637877]">Create roles and assign permissions by business area.</p>
              </div>
              <div className="flex items-center gap-2">
                <ActionButton onClick={() => loadData(selectedRoleId)} disabled={loading || savingPermissions} icon={<RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />} label="Refresh" />
                <ActionButton primary onClick={() => { setRoleForm(emptyRoleForm); setFormOpen(true); }} icon={<Plus className="h-4 w-4" />} label="New role" />
              </div>
            </div>
            <div className="mt-3 grid grid-cols-3 gap-2 md:w-[520px]">
              <Summary label="Roles" value={roles.length} />
              <Summary label="Permissions" value={permissions.length} />
              <Summary label="Modules" value={moduleCount} />
            </div>
          </header>

          <div className="grid min-h-0 flex-1 grid-cols-1 gap-3 p-3 lg:grid-cols-[330px_minmax(0,1fr)]">
            <aside className="flex min-h-[260px] flex-col overflow-hidden border border-[#CAD5D5] bg-white">
              <div className="border-b border-[#D8E1E0] p-3">
                <div className="mb-2 flex items-center justify-between text-[10px] text-[#637877]">
                  <span className="font-semibold uppercase tracking-[0.12em] text-[#324444]">Roles</span>
                  <span>{filteredRoles.length} shown</span>
                </div>
                <SearchInput value={roleSearch} onChange={setRoleSearch} placeholder="Search roles" />
              </div>
              <div className="min-h-0 flex-1 overflow-y-auto p-2">
                {filteredRoles.length === 0 ? <EmptyState message={loading ? "Loading roles..." : "No roles match your search."} /> : (
                  <div className="space-y-1.5">
                    {filteredRoles.map((role) => {
                      const active = String(role.id) === String(selectedRoleId);
                      const system = isSystemRole(role);
                      return (
                        <article key={role.id} className={`border ${active ? "border-[#057B7B] bg-[#ECF8F6]" : "border-[#E2E9E9] bg-white hover:border-[#BCC7C7]"}`}>
                          <button type="button" onClick={() => selectRole(role.id)} className="w-full px-3 py-2.5 text-left">
                            <div className="flex items-start justify-between gap-2">
                              <div className="min-w-0">
                                <div className="flex items-center gap-1.5">
                                  {system ? <LockKeyhole className="h-3.5 w-3.5 shrink-0 text-[#B7791F]" /> : <ShieldCheck className="h-3.5 w-3.5 shrink-0 text-[#057B7B]" />}
                                  <span className="truncate text-[12px] font-semibold text-[#172B2B]">{role.name}</span>
                                </div>
                                <div className="mt-1 truncate text-[9px] uppercase tracking-wide text-[#637877]">{role.group || "Uncategorised"}</div>
                              </div>
                              <span className="border border-[#D8E1E0] bg-white px-1.5 py-0.5 text-[9px] font-semibold text-[#465858]">{system ? "ALL" : role.permissions?.length || 0}</span>
                            </div>
                            <p className="mt-1.5 line-clamp-2 text-[10px] leading-4 text-[#637877]">{role.description || "No role description."}</p>
                          </button>
                          {active && !system && (
                            <div className="flex gap-1.5 border-t border-[#C9E5E1] px-3 py-2">
                              <SmallButton onClick={() => openEditRole(role)} icon={<Pencil className="h-3 w-3" />} label="Edit" />
                              <SmallButton danger disabled={deletingRoleId === role.id} onClick={() => removeRole(role)} icon={<Trash2 className="h-3 w-3" />} label={deletingRoleId === role.id ? "Deleting" : "Delete"} />
                            </div>
                          )}
                        </article>
                      );
                    })}
                  </div>
                )}
              </div>
            </aside>

            <main className="flex min-h-[420px] min-w-0 flex-col overflow-hidden border border-[#CAD5D5] bg-white">
              {!selectedRole ? <EmptyRole /> : (
                <>
                  <div className="shrink-0 border-b border-[#D8E1E0] p-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <h2 className="text-[15px] font-semibold text-[#172B2B]">{selectedRole.name}</h2>
                          <Badge>{selectedRole.group || "Uncategorised"}</Badge>
                          {protectedRole && <Badge gold><LockKeyhole className="h-3 w-3" /> System role</Badge>}
                          {isDirty && <Badge orange>Unsaved changes</Badge>}
                        </div>
                        <p className="mt-1 max-w-3xl text-[11px] text-[#637877]">{protectedRole ? "Super administrators automatically receive every permission and cannot be restricted." : selectedRole.description || "Choose the operations this role may perform."}</p>
                      </div>
                      <div className="text-right">
                        <div className="text-[18px] font-semibold text-[#057B7B]">{protectedRole ? permissions.length : draftIds.length}<span className="text-[11px] font-normal text-[#637877]"> / {permissions.length}</span></div>
                        <div className="text-[9px] uppercase tracking-wide text-[#637877]">selected permissions</div>
                      </div>
                    </div>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                      <div className="min-w-[240px] flex-1"><SearchInput value={permissionSearch} onChange={setPermissionSearch} placeholder="Search permission, code or module" /></div>
                      {!protectedRole && (
                        <>
                          <SmallButton disabled={!visibleIds.length || savingPermissions} onClick={() => setPermissionSelection(visibleIds, !visibleAllSelected)} label={visibleAllSelected ? "Clear visible" : "Select visible"} />
                          <SmallButton disabled={!isDirty || savingPermissions} onClick={() => setDraftIds(savedIds)} icon={<Undo2 className="h-3.5 w-3.5" />} label="Discard" />
                          <button type="button" onClick={savePermissions} disabled={!isDirty || savingPermissions} className="flex h-9 min-w-[130px] items-center justify-center gap-1.5 bg-[#057B7B] px-4 text-[10px] font-semibold text-white disabled:bg-[#93A6A6]">
                            <Save className="h-3.5 w-3.5" />{savingPermissions ? "Saving..." : "Save changes"}
                          </button>
                        </>
                      )}
                    </div>
                  </div>

                  <div className="min-h-0 flex-1 overflow-y-auto bg-[#F8FAFC] p-3">
                    {permissionGroups.length === 0 ? <EmptyState message="No permissions match your search." /> : (
                      <div className="grid grid-cols-1 gap-3 2xl:grid-cols-2">
                        {permissionGroups.map(([group, items]) => {
                          const isOpen = openGroups[group] !== false;
                          const groupIds = items.map((item) => String(item.id));
                          const selectedInGroup = groupIds.filter((id) => draftIds.includes(id)).length;
                          const allSelected = selectedInGroup === groupIds.length;
                          return (
                            <section key={group} className="self-start border border-[#CAD5D5] bg-white">
                              <div className="flex min-h-12 items-center gap-2 border-b border-[#D8E1E0] bg-[#F1F5F5] px-3 py-2">
                                <button type="button" onClick={() => setOpenGroups((current) => ({ ...current, [group]: !isOpen }))} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                                  {isOpen ? <ChevronDown className="h-4 w-4 shrink-0 text-[#465858]" /> : <ChevronRight className="h-4 w-4 shrink-0 text-[#465858]" />}
                                  <Layers3 className="h-4 w-4 shrink-0 text-[#057B7B]" />
                                  <span className="min-w-0"><span className="block truncate text-[11px] font-semibold text-[#2B3C3C]">{titleCase(group)}</span><span className="block text-[9px] text-[#637877]">{selectedInGroup} of {items.length} selected</span></span>
                                </button>
                                {!protectedRole && <SmallButton disabled={savingPermissions} onClick={() => setPermissionSelection(groupIds, !allSelected)} label={allSelected ? "Clear group" : "Select group"} />}
                              </div>
                              {isOpen && <div className="divide-y divide-[#EFF2F2]">{items.map((permission) => <PermissionRow key={permission.id} permission={permission} checked={draftIds.includes(String(permission.id))} disabled={protectedRole || savingPermissions} onToggle={togglePermission} />)}</div>}
                            </section>
                          );
                        })}
                      </div>
                    )}
                  </div>
                </>
              )}
            </main>
          </div>
        </div>
      </FullScreenModal>

      <EnterpriseFormModal open={formOpen} title={roleForm.id ? "Edit role" : "Create role"} onClose={closeRoleForm} busy={savingRole} width="620px">
        <form onSubmit={saveRole} className="space-y-4 p-4">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <FormField label="Role name"><input value={roleForm.name} onChange={(event) => setRoleForm((current) => ({ ...current, name: event.target.value }))} required autoFocus className="role-input" placeholder="e.g. Branch Manager" /></FormField>
            <FormField label="Role group"><input value={roleForm.group} onChange={(event) => setRoleForm((current) => ({ ...current, group: event.target.value }))} required className="role-input" placeholder="e.g. Operations" /></FormField>
          </div>
          <FormField label="Description"><textarea value={roleForm.description} onChange={(event) => setRoleForm((current) => ({ ...current, description: event.target.value }))} rows={4} className="role-input min-h-[96px] resize-none py-2" placeholder="Explain who should receive this role." /></FormField>
          <div className="flex justify-end gap-2 border-t border-[#D8E1E0] pt-3">
            <button type="button" onClick={closeRoleForm} disabled={savingRole} className="h-9 border border-[#ACB8B8] bg-white px-4 text-[11px] font-semibold text-[#324444] disabled:opacity-50">Cancel</button>
            <button type="submit" disabled={savingRole} className="h-9 min-w-[120px] bg-[#057B7B] px-4 text-[11px] font-semibold text-white disabled:bg-[#93A6A6]">{savingRole ? "Saving..." : roleForm.id ? "Update role" : "Create role"}</button>
          </div>
          <style>{`.role-input{width:100%;min-height:36px;border:1px solid #acb8b8;background:#fff;padding-left:10px;padding-right:10px;font-size:12px;color:#2b3c3c;outline:none}.role-input:focus{border-color:#057b7b}`}</style>
        </form>
      </EnterpriseFormModal>

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={() => setMessageModal((current) => ({ ...current, open: false }))} />
    </>
  );
};

const SearchInput = ({ value, onChange, placeholder }) => <label className="relative block"><Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#7F908F]" /><input value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="h-9 w-full border border-[#ACB8B8] bg-white pl-8 pr-3 text-[11px] outline-none focus:border-[#057B7B]" /></label>;
const Summary = ({ label, value }) => <div className="border border-[#D8E1E0] bg-[#F8FAFC] px-3 py-2"><div className="text-[9px] font-semibold uppercase tracking-wide text-[#637877]">{label}</div><div className="mt-0.5 text-[15px] font-semibold text-[#172B2B]">{value}</div></div>;
const EmptyState = ({ message }) => <div className="flex min-h-[150px] items-center justify-center px-4 text-center text-[11px] text-[#637877]">{message}</div>;
const EmptyRole = () => <div className="flex h-full items-center justify-center p-8 text-center"><div><ShieldCheck className="mx-auto h-9 w-9 text-[#93A6A6]" /><div className="mt-3 text-[13px] font-semibold text-[#324444]">Select a role</div><div className="mt-1 text-[11px] text-[#637877]">Choose a role on the left to review its permissions.</div></div></div>;
const FormField = ({ label, children }) => <label className="block"><span className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-[#465858]">{label}</span>{children}</label>;
const Badge = ({ children, gold = false, orange = false }) => <span className={`flex items-center gap-1 border px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wide ${gold ? "border-[#E7C873] bg-[#FFF7D6] text-[#7A5600]" : orange ? "border-[#F0B25D] bg-[#FFF5E8] text-[#9A5B00]" : "border-[#BCC7C7] bg-[#F8FAFC] text-[#465858]"}`}>{children}</span>;
const ActionButton = ({ onClick, disabled, icon, label, primary = false }) => <button type="button" onClick={onClick} disabled={disabled} className={`flex h-9 items-center gap-2 px-3 text-[11px] font-semibold disabled:opacity-50 ${primary ? "bg-[#057B7B] text-white hover:bg-[#046161]" : "border border-[#ACB8B8] bg-white text-[#324444] hover:bg-[#F8FAFC]"}`}>{icon}{label}</button>;
const SmallButton = ({ onClick, disabled, icon, label, danger = false }) => <button type="button" onClick={onClick} disabled={disabled} className={`flex h-7 items-center gap-1 border bg-white px-2 text-[9px] font-semibold disabled:opacity-50 ${danger ? "border-[#F4B4AE] text-[#B42318]" : "border-[#ACB8B8] text-[#324444]"}`}>{icon}{label}</button>;
const PermissionRow = ({ permission, checked, disabled, onToggle }) => <label className={`flex min-h-[52px] items-start gap-2.5 px-3 py-2.5 ${disabled ? "cursor-default bg-[#FAFCFD]" : "cursor-pointer hover:bg-[#F7FBFA]"}`}><span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center border ${checked ? "border-[#057B7B] bg-[#057B7B] text-white" : "border-[#ACB8B8] bg-white"}`}>{checked && <Check className="h-3 w-3" />}</span><input type="checkbox" className="sr-only" checked={checked} disabled={disabled} onChange={() => onToggle(permission.id)} /><span className="min-w-0 flex-1"><span className="block text-[11px] font-medium leading-4 text-[#2B3C3C]">{permission.description || titleCase(permission.name)}</span><span className="mt-0.5 block break-all font-mono text-[9px] leading-3 text-[#7F908F]">{permission.code || permission.name}</span></span></label>;

export default Roles;

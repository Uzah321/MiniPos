import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  Clock3,
  Code2,
  KeyRound,
  RefreshCw,
  Save,
  ShieldCheck,
  UserRoundCheck,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseRowActionMenu from "../../../components/EnterpriseRowActionMenu";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import {
  disableAuthenticationPolicy,
  getEffectiveAuthenticationPolicy,
  grantAccessAssignment,
  listAccessAssignments,
  listAuthenticationPolicies,
  publishAuthenticationPolicy,
  revokeAccessAssignment,
} from "../../../services/AuthenticationAdminService";
import { getTenantEnterpriseHierarchy } from "../../../services/EnterpriseHierarchyService";

const inputClass = "h-9 w-full border border-[#ACB8B8] bg-white px-2 text-[11px] text-[#2B3C3C] outline-none focus:border-[#069494] disabled:bg-[#EFF2F2] disabled:text-[#637877]";
const textAreaClass = "w-full border border-[#ACB8B8] bg-white px-2 py-2 text-[11px] text-[#2B3C3C] outline-none focus:border-[#069494] disabled:bg-[#EFF2F2]";
const primaryButton = "inline-flex h-9 items-center justify-center gap-1.5 bg-[#006B6B] px-4 text-[10px] font-black uppercase tracking-wide text-white hover:bg-[#005656] disabled:cursor-not-allowed disabled:opacity-45";
const secondaryButton = "inline-flex h-9 items-center justify-center gap-1.5 border border-[#ACB8B8] bg-white px-3 text-[10px] font-black uppercase tracking-wide text-[#324444] hover:border-[#069494] hover:text-[#006B6B] disabled:cursor-not-allowed disabled:opacity-45";
const dangerButton = "inline-flex h-8 items-center justify-center gap-1 border border-[#E6A6A1] bg-white px-2 text-[9px] font-black uppercase text-[#B42318] hover:bg-[#FFF1F0] disabled:cursor-not-allowed disabled:opacity-40";

const POLICY_KEYS = [
  { value: "authentication", label: "Authentication methods" },
  { value: "credential", label: "Credential strength" },
  { value: "session", label: "Session limits" },
];

const SCOPE_TYPES = ["global", "country", "company", "brand", "complex", "shop", "till"];

export const POLICY_FIELDS = {
  authentication: [
    {
      path: "pos.login_mode",
      label: "POS login mode",
      type: "select",
      options: [
        { value: "login_code", label: "Login code only" },
        { value: "username_password", label: "Username and password only" },
        { value: "both", label: "Login code or username and password" },
      ],
      defaultValue: "username_password",
      help: "Controls login only on assigned POS terminals.",
    },
    { path: "lockout.maximum_attempts", label: "Maximum failed attempts", type: "number", min: 1, max: 100, defaultValue: 5 },
    { path: "lockout.decay_seconds", label: "Lockout duration (seconds)", type: "number", min: 30, max: 86400, defaultValue: 900 },
  ],
  credential: [
    { path: "password.minimum_length", label: "Password minimum length", type: "number", min: 6, max: 1024, defaultValue: 6 },
    { path: "password.maximum_length", label: "Password maximum length", type: "number", min: 6, max: 1024, defaultValue: 128 },
    { path: "password.require_uppercase", label: "Require uppercase letter", type: "boolean", defaultValue: true },
    { path: "password.require_lowercase", label: "Require lowercase letter", type: "boolean", defaultValue: true },
    { path: "password.require_number", label: "Require number", type: "boolean", defaultValue: true },
    { path: "password.require_symbol", label: "Require symbol", type: "boolean", defaultValue: true },
    { path: "password.history_count", label: "Previous passwords blocked", type: "number", min: 0, max: 24, defaultValue: 5 },
    { path: "login_code.minimum_length", label: "Login code minimum length", type: "number", min: 4, max: 12, defaultValue: 4 },
    { path: "login_code.maximum_length", label: "Login code maximum length", type: "number", min: 4, max: 12, defaultValue: 8 },
    { path: "login_code.numeric_only", label: "Digits only", type: "boolean", defaultValue: true },
    { path: "login_code.reject_repeated_digits", label: "Block repeated-digit codes", type: "boolean", defaultValue: true },
    { path: "login_code.reject_sequences", label: "Block simple sequences", type: "boolean", defaultValue: true },
  ],
  session: [
    { path: "pos.lifetime_minutes", label: "POS maximum session (minutes)", type: "number", min: 1, max: 43200, defaultValue: 720 },
    { path: "pos.idle_timeout_minutes", label: "POS idle timeout (minutes)", type: "number", min: 1, max: 10080, defaultValue: 30 },
    { path: "back_office.lifetime_minutes", label: "Back Office maximum session (minutes)", type: "number", min: 1, max: 43200, defaultValue: 480 },
    { path: "back_office.idle_timeout_minutes", label: "Back Office idle timeout (minutes)", type: "number", min: 1, max: 10080, defaultValue: 30 },
  ],
};

const isPlainObject = (value) => Boolean(value) && typeof value === "object" && !Array.isArray(value);

export const getPath = (source, path, fallback = undefined) => {
  const value = String(path).split(".").reduce(
    (current, segment) => (isPlainObject(current) ? current[segment] : undefined),
    source,
  );
  return value === undefined ? fallback : value;
};

const setPath = (source, path, value) => {
  const copy = JSON.parse(JSON.stringify(source || {}));
  const segments = String(path).split(".");
  let cursor = copy;
  segments.forEach((segment, index) => {
    if (index === segments.length - 1) cursor[segment] = value;
    else {
      cursor[segment] = isPlainObject(cursor[segment]) ? cursor[segment] : {};
      cursor = cursor[segment];
    }
  });
  return copy;
};

const deletePath = (source, path) => {
  const copy = JSON.parse(JSON.stringify(source || {}));
  const segments = String(path).split(".");
  const stack = [];
  let cursor = copy;
  for (const segment of segments.slice(0, -1)) {
    if (!isPlainObject(cursor?.[segment])) return copy;
    stack.push([cursor, segment]);
    cursor = cursor[segment];
  }
  delete cursor[segments[segments.length - 1]];
  stack.reverse().forEach(([parent, segment]) => {
    if (Object.keys(parent[segment] || {}).length === 0) delete parent[segment];
  });
  return copy;
};

const defaultPolicySettings = (policyKey) => (POLICY_FIELDS[policyKey] || []).reduce(
  (current, definition) => setPath(current, definition.path, definition.defaultValue),
  policyKey === "authentication" ? { back_office: { login_mode: "username_password" } } : {},
);

const mergeSettings = (base, override) => {
  const output = JSON.parse(JSON.stringify(base || {}));
  Object.entries(override || {}).forEach(([key, value]) => {
    output[key] = isPlainObject(value) && isPlainObject(output[key])
      ? mergeSettings(output[key], value)
      : value;
  });
  return output;
};

const leafEntries = (source, prefix = "") => Object.entries(source || {}).flatMap(([key, value]) => {
  const path = prefix ? `${prefix}.${key}` : key;
  return isPlainObject(value) ? leafEntries(value, path) : [[path, value]];
});

export const validatePolicySettings = (policyKey, settings, { baseline = {}, allowEmpty = true } = {}) => {
  if (!isPlainObject(settings)) throw new Error("Policy settings must be a JSON object.");
  const entries = leafEntries(settings);
  if (!allowEmpty && entries.length === 0) throw new Error("Select at least one setting to override.");

  const definitions = POLICY_FIELDS[policyKey] || [];
  const definitionsByPath = new Map(definitions.map((field) => [field.path, field]));
  const fixedBackOfficePath = policyKey === "authentication" ? "back_office.login_mode" : null;

  entries.forEach(([path, value]) => {
    if (path === fixedBackOfficePath) {
      throw new Error("Back Office login mode is fixed to username_password and cannot be overridden.");
    }

    const definition = definitionsByPath.get(path);
    if (!definition) throw new Error(`Unsupported ${policyKey} policy setting: ${path}.`);
    if (definition.type === "boolean" && typeof value !== "boolean") {
      throw new Error(`${definition.label} must be true or false.`);
    }
    if (definition.type === "number") {
      if (!Number.isInteger(value)) throw new Error(`${definition.label} must be a whole number.`);
      if (value < definition.min || value > definition.max) {
        throw new Error(`${definition.label} must be between ${definition.min} and ${definition.max}.`);
      }
    }
    if (definition.type === "select" && !definition.options.some((option) => option.value === value)) {
      throw new Error(`${definition.label} contains an unsupported value.`);
    }
  });

  const resolved = mergeSettings(baseline, settings);
  const passwordMinimum = getPath(resolved, "password.minimum_length");
  const passwordMaximum = getPath(resolved, "password.maximum_length");
  if (policyKey === "credential" && Number.isFinite(passwordMinimum) && Number.isFinite(passwordMaximum) && passwordMaximum < passwordMinimum) {
    throw new Error("Password maximum length cannot be less than its minimum length.");
  }
  const codeMinimum = getPath(resolved, "login_code.minimum_length");
  const codeMaximum = getPath(resolved, "login_code.maximum_length");
  if (policyKey === "credential" && Number.isFinite(codeMinimum) && Number.isFinite(codeMaximum) && codeMaximum < codeMinimum) {
    throw new Error("Login code maximum length cannot be less than its minimum length.");
  }
  [["pos", "POS"], ["back_office", "Back Office"]].forEach(([channel, label]) => {
    const lifetime = getPath(resolved, `${channel}.lifetime_minutes`);
    const idle = getPath(resolved, `${channel}.idle_timeout_minutes`);
    if (policyKey === "session" && Number.isFinite(lifetime) && Number.isFinite(idle) && idle > lifetime) {
      throw new Error(`${label} idle timeout cannot exceed the maximum session lifetime.`);
    }
  });

  return settings;
};

export const parsePolicyJson = (policyKey, raw, options = {}) => {
  let settings;
  try {
    settings = JSON.parse(raw);
  } catch {
    throw new Error("Policy JSON is not valid JSON.");
  }
  return validatePolicySettings(policyKey, settings, options);
};

const entityLabel = (entity, fallback) => {
  const name = entity?.name || entity?.company_name || entity?.till_name || fallback;
  const code = entity?.code || entity?.company_code || entity?.till_code || "";
  return code ? `${name} (${code})` : name;
};

const addTarget = (bucket, type, entity, fallback) => {
  const id = String(entity?.id || "").trim();
  if (!id || bucket[type].some((item) => item.id === id)) return;
  bucket[type].push({ id, label: entityLabel(entity, fallback), entity });
};

export const buildEnterpriseScopeTargets = (hierarchyPages = []) => {
  const targets = Object.fromEntries(SCOPE_TYPES.map((type) => [type, []]));
  targets.global = [{ id: "", label: "Global platform", entity: null }];

  hierarchyPages.forEach((page) => {
    const payload = page?.data?.company ? page.data : page?.company ? page : page?.data?.data || page?.data || page || {};
    const country = payload?.country;
    const company = payload?.company;
    addTarget(targets, "country", country, "Country");
    addTarget(targets, "company", company, "Company");
    (company?.brands || []).forEach((brand) => {
      addTarget(targets, "brand", brand, "Brand");
      (brand?.complexes || []).forEach((complex) => {
        addTarget(targets, "complex", complex, "Complex");
        (complex?.shops || []).forEach((shop) => {
          addTarget(targets, "shop", shop, "Shop");
          (shop?.tills || []).forEach((till) => addTarget(targets, "till", till, "Till"));
        });
      });
    });
  });
  return targets;
};

export const extractAdminRows = (response) => {
  const candidates = [
    response,
    response?.data,
    response?.data?.data,
    response?.data?.data?.data,
  ];
  return candidates.find(Array.isArray) || [];
};

export const extractAssignmentOptions = (response) => {
  const meta = response?.meta || response?.data?.meta || response?.data?.data?.meta || {};
  return {
    users: Array.isArray(meta.users) ? meta.users : [],
    roles: Array.isArray(meta.roles) ? meta.roles : [],
  };
};

export const extractAdminPagination = (response) => {
  const candidates = [response?.data, response?.data?.data, response];
  const paginator = candidates.find((candidate) => (
    isPlainObject(candidate)
    && (candidate.current_page !== undefined || candidate.last_page !== undefined)
  ));
  return {
    page: Number(paginator?.current_page || 1),
    lastPage: Number(paginator?.last_page || 1),
    total: Number(paginator?.total ?? extractAdminRows(response).length),
  };
};

const extractHierarchyMeta = (response) => response?.meta || response?.data?.meta || {};
const apiMessage = (error, fallback) => (
  error?.response?.data?.errors
    ? Object.values(error.response.data.errors).flat().join("\n")
    : error?.response?.data?.message || error?.displayMessage || error?.message || fallback
);
const toApiDate = (value) => value ? new Date(value).toISOString() : undefined;
const formatDate = (value) => {
  if (!value) return "Open ended";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString("en-ZW");
};
const scopeTargetId = (row) => row?.scope_type === "global" ? "" : String(row?.[`${row?.scope_type}_id`] || "");

const Field = ({ label, required = false, children, help = "" }) => (
  <label className="block">
    <span className="mb-1 block text-[9px] font-black uppercase tracking-wide text-[#556767]">
      {label}{required ? " *" : ""}
    </span>
    {children}
    {help && <span className="mt-1 block text-[9px] leading-relaxed text-[#758483]">{help}</span>}
  </label>
);

const Notice = ({ type, children }) => {
  if (!children) return null;
  const error = type === "error";
  return (
    <div className={`flex items-start gap-2 border px-3 py-2 text-[11px] ${error ? "border-[#E6A6A1] bg-[#FFF1F0] text-[#9B1C1C]" : "border-[#90D3D2] bg-[#ECFDF8] text-[#005D5D]"}`}>
      {error ? <AlertTriangle size={15} className="mt-0.5 shrink-0" /> : <CheckCircle2 size={15} className="mt-0.5 shrink-0" />}
      <span className="whitespace-pre-line">{children}</span>
    </div>
  );
};

function ScopeEditor({ value, onChange, targets, isPlatformAdmin, disabled = false }) {
  const visibleScopes = isPlatformAdmin ? SCOPE_TYPES : SCOPE_TYPES.filter((type) => !["global", "country"].includes(type));
  const selectedTargets = targets[value.scope_type] || [];
  const setScopeType = (scopeType) => {
    const first = (targets[scopeType] || [])[0];
    onChange({ ...value, scope_type: scopeType, target_id: scopeType === "global" ? "" : first?.id || "" });
  };

  return (
    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
      <Field label="Enterprise scope" required>
        <select className={inputClass} value={value.scope_type} disabled={disabled} onChange={(event) => setScopeType(event.target.value)}>
          {visibleScopes.map((type) => <option key={type} value={type}>{type.replace("_", " ")}</option>)}
        </select>
      </Field>
      <Field label="Scope target" required={value.scope_type !== "global"} help={value.scope_type !== "global" && !selectedTargets.length ? "No accessible target is loaded for this level." : ""}>
        <select
          className={inputClass}
          value={value.scope_type === "global" ? "" : value.target_id}
          disabled={disabled || value.scope_type === "global" || !selectedTargets.length}
          onChange={(event) => onChange({ ...value, target_id: event.target.value })}
        >
          {value.scope_type === "global" ? <option value="">Global platform</option> : <option value="">Select target</option>}
          {value.scope_type !== "global" && selectedTargets.map((target) => (
            <option key={target.id} value={target.id}>{target.label}</option>
          ))}
        </select>
      </Field>
    </div>
  );
}

function AssignmentWorkspace({ isPlatformAdmin, targets, hierarchyMeta, onLoadMoreHierarchy }) {
  const [users, setUsers] = useState([]);
  const [baseUsers, setBaseUsers] = useState([]);
  const [roles, setRoles] = useState([]);
  const [assignments, setAssignments] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, lastPage: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [statusFilter, setStatusFilter] = useState("active");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [revokeTarget, setRevokeTarget] = useState(null);
  const [revokeReason, setRevokeReason] = useState("");
  const [revoking, setRevoking] = useState(false);
  const [userSearch, setUserSearch] = useState("");
  const [searchingUsers, setSearchingUsers] = useState(false);
  const [userSearchError, setUserSearchError] = useState("");
  const [selectedUserRecord, setSelectedUserRecord] = useState(null);
  const [editorOpen, setEditorOpen] = useState(false);
  const [form, setForm] = useState({
    user_id: "",
    role_id: "",
    scope_type: "company",
    target_id: "",
    valid_from: "",
    valid_until: "",
    reason: "",
  });

  const load = useCallback(async (requestedPage = 1) => {
    setLoading(true);
    setError("");
    try {
      const assignmentsResponse = await listAccessAssignments({
        per_page: 100,
        page: requestedPage,
        ...(statusFilter ? { status: statusFilter } : {}),
      });
      const options = extractAssignmentOptions(assignmentsResponse);
      setUsers(options.users);
      setBaseUsers(options.users);
      setRoles(options.roles);
      setAssignments(extractAdminRows(assignmentsResponse));
      setPagination(extractAdminPagination(assignmentsResponse));
    } catch (loadError) {
      setError(apiMessage(loadError, "Access assignments could not be loaded."));
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);

  useEffect(() => { load(page); }, [load, page]);
  useEffect(() => {
    const query = userSearch.trim();
    if (query.length < 2) {
      setUsers(baseUsers);
      setSearchingUsers(false);
      setUserSearchError("");
      return undefined;
    }

    let active = true;
    const timeout = window.setTimeout(async () => {
      setSearchingUsers(true);
      setUserSearchError("");
      try {
        const response = await listAccessAssignments({
          per_page: 1,
          page: 1,
          user_q: query,
          ...(statusFilter ? { status: statusFilter } : {}),
        });
        if (active) setUsers(extractAssignmentOptions(response).users);
      } catch (searchError) {
        if (active) setUserSearchError(apiMessage(searchError, "The authorized user directory could not be searched."));
      } finally {
        if (active) setSearchingUsers(false);
      }
    }, 350);

    return () => {
      active = false;
      window.clearTimeout(timeout);
    };
  }, [baseUsers, statusFilter, userSearch]);
  useEffect(() => {
    if (form.scope_type === "global") return;
    if ((targets[form.scope_type] || []).some((target) => target.id === form.target_id)) return;
    setForm((current) => ({ ...current, target_id: targets[current.scope_type]?.[0]?.id || "" }));
  }, [targets, form.scope_type, form.target_id]);

  const save = async (event) => {
    event.preventDefault();
    setError("");
    setNotice("");
    if (!form.user_id || !form.role_id) {
      setError("Select both a user and a role.");
      return;
    }
    if (form.scope_type !== "global" && !form.target_id) {
      setError("Select an enterprise scope target.");
      return;
    }
    if (form.valid_from && form.valid_until && new Date(form.valid_until) <= new Date(form.valid_from)) {
      setError("Valid until must be later than valid from.");
      return;
    }
    setSaving(true);
    try {
      const response = await grantAccessAssignment({
        user_id: form.user_id,
        role_id: form.role_id,
        scope_type: form.scope_type,
        ...(form.scope_type === "global" ? {} : { target_id: form.target_id }),
        ...(form.valid_from ? { valid_from: toApiDate(form.valid_from) } : {}),
        ...(form.valid_until ? { valid_until: toApiDate(form.valid_until) } : {}),
        ...(form.reason.trim() ? { reason: form.reason.trim() } : {}),
      });
      setNotice(response?.message || "Enterprise access assignment saved.");
      setForm((current) => ({ ...current, reason: "", valid_from: "", valid_until: "" }));
      setEditorOpen(false);
      await load(page);
    } catch (saveError) {
      setError(apiMessage(saveError, "The access assignment could not be saved."));
    } finally {
      setSaving(false);
    }
  };

  const revoke = async () => {
    if (!revokeTarget) return;
    setRevoking(true);
    setError("");
    try {
      const response = await revokeAccessAssignment(revokeTarget.id, {
        ...(revokeReason.trim() ? { reason: revokeReason.trim() } : {}),
      });
      setNotice(response?.message || "Enterprise access assignment revoked.");
      setRevokeTarget(null);
      setRevokeReason("");
      await load(page);
    } catch (revokeError) {
      setError(apiMessage(revokeError, "The access assignment could not be revoked."));
    } finally {
      setRevoking(false);
    }
  };

  const targetName = (assignment) => {
    const type = assignment?.scope_type || "company";
    if (type === "global") return "Global platform";
    const id = scopeTargetId(assignment);
    return targets[type]?.find((target) => target.id === id)?.label || assignment?.scope_key || `${type}: ${id || "Unknown"}`;
  };

  const filteredUsers = useMemo(() => {
    const query = userSearch.trim().toLowerCase();
    const matches = !query ? users : users.filter((user) => [user.name, user.username, user.employee_code]
      .some((value) => String(value || "").toLowerCase().includes(query)));
    if (selectedUserRecord && !matches.some((user) => String(user.id) === String(selectedUserRecord.id))) {
      return [selectedUserRecord, ...matches];
    }
    return matches;
  }, [selectedUserRecord, users, userSearch]);

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden">
      <section className="flex min-h-0 flex-1 flex-col overflow-hidden bg-white">
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-[#DAE0E0] px-4 py-3">
          <div>
            <div className="text-[12px] font-black uppercase text-[#054747]">Access assignments</div>
            <div className="text-[9px] text-[#637877]">{pagination.total} records</div>
          </div>
          <div className="flex items-center gap-2">
            <select className={`${inputClass} w-32`} value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="revoked">Revoked</option>
              <option value="">All statuses</option>
            </select>
            <button type="button" className={secondaryButton} onClick={() => load(page)} disabled={loading}>
              <RefreshCw size={12} className={loading ? "animate-spin" : ""} /> Refresh
            </button>
            <button type="button" className={primaryButton} onClick={() => { setError(""); setEditorOpen(true); }}>
              <UserRoundCheck size={13} /> Add assignment
            </button>
          </div>
        </header>
        {(error || notice) && <div className="shrink-0 space-y-2 px-4 py-2"><Notice type="error">{error}</Notice><Notice type="success">{notice}</Notice></div>}
        <div className="min-h-0 flex-1 overflow-auto">
          <table className="w-full min-w-[820px] border-collapse text-left text-[10px]">
            <thead className="sticky top-0 z-10 bg-[#EFF3F3] text-[9px] font-black uppercase text-[#556767]">
              <tr><th className="border-b px-3 py-2">User</th><th className="border-b px-3 py-2">Role</th><th className="border-b px-3 py-2">Scope</th><th className="border-b px-3 py-2">Validity</th><th className="border-b px-3 py-2">Status</th><th className="border-b px-3 py-2 text-right">More</th></tr>
            </thead>
            <tbody>
              {assignments.map((assignment) => (
                <tr key={assignment.id} className="border-b border-[#E5E9E9] align-top hover:bg-[#F8FAFC]">
                  <td className="px-3 py-2"><div className="font-bold text-[#054747]">{assignment.user?.name || "Unknown user"}</div><div className="text-[#758483]">{assignment.user?.username || assignment.pos_user_id}</div></td>
                  <td className="px-3 py-2 font-semibold">{assignment.role?.name || assignment.role_id}</td>
                  <td className="px-3 py-2"><div className="font-bold uppercase text-[#006B6B]">{assignment.scope_type}</div><div className="max-w-[260px] truncate" title={targetName(assignment)}>{targetName(assignment)}</div></td>
                  <td className="px-3 py-2"><div>{assignment.valid_from ? formatDate(assignment.valid_from) : "Immediate"}</div><div className="text-[#758483]">to {formatDate(assignment.valid_until)}</div></td>
                  <td className="px-3 py-2"><span className={`inline-flex px-2 py-1 text-[8px] font-black uppercase ${assignment.status === "active" ? "bg-[#DDF7EF] text-[#005D5D]" : assignment.status === "revoked" ? "bg-[#FEE4E2] text-[#9B1C1C]" : "bg-[#FFF3CD] text-[#7A5411]"}`}>{assignment.status}</span></td>
                  <td className="px-3 py-2 text-right">{assignment.status === "active" && <EnterpriseRowActionMenu label={`More actions for ${assignment.user?.name || "assignment"}`} actions={[{ label: "Revoke access", danger: true, onClick: () => { setRevokeTarget(assignment); setRevokeReason(""); } }]} />}</td>
                </tr>
              ))}
              {!loading && !assignments.length && <tr><td colSpan={6} className="px-4 py-12 text-center text-[#758483]">No assignments match this status filter.</td></tr>}
              {loading && <tr><td colSpan={6} className="px-4 py-12 text-center text-[#758483]">Loading access assignments…</td></tr>}
            </tbody>
          </table>
        </div>
        {pagination.lastPage > 1 && <footer className="flex shrink-0 items-center justify-between border-t border-[#DAE0E0] bg-[#F8FAFC] px-4 py-2 text-[9px] font-bold uppercase text-[#637877]"><button type="button" className={secondaryButton} disabled={loading || page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>Previous</button><span>Page {pagination.page} of {pagination.lastPage}</span><button type="button" className={secondaryButton} disabled={loading || page >= pagination.lastPage} onClick={() => setPage((current) => current + 1)}>Next</button></footer>}
      </section>

      <EnterpriseFormModal open={editorOpen} title="Grant Access" onClose={() => !saving && setEditorOpen(false)} busy={saving}>
        <form className="space-y-4" onSubmit={save}>
          <Notice type="error">{error}</Notice>
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <Field label="Find user" help={userSearchError || (searchingUsers ? "Searching…" : "Type at least two characters.")}>
              <input className={inputClass} type="search" maxLength={120} value={userSearch} disabled={saving} placeholder="Search users" onChange={(event) => setUserSearch(event.target.value)} />
            </Field>
            <Field label="User" required>
              <select className={inputClass} value={form.user_id} disabled={saving} onChange={(event) => {
                const userId = event.target.value;
                setForm({ ...form, user_id: userId });
                setSelectedUserRecord(filteredUsers.find((user) => String(user.id) === String(userId)) || null);
              }}>
                <option value="">Select user</option>
                {filteredUsers.map((user) => <option key={user.id} value={user.id}>{user.name || user.username} ({user.username})</option>)}
              </select>
            </Field>
            <Field label="Role" required>
              <select className={inputClass} value={form.role_id} disabled={saving} onChange={(event) => setForm({ ...form, role_id: event.target.value })}>
                <option value="">Select role</option>
                {roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
              </select>
            </Field>
            <div className="lg:col-span-2"><ScopeEditor value={form} onChange={setForm} targets={targets} isPlatformAdmin={isPlatformAdmin} disabled={saving} /></div>
            {Number(hierarchyMeta?.current_page || 1) < Number(hierarchyMeta?.last_page || 1) && (
              <button type="button" className={secondaryButton} onClick={onLoadMoreHierarchy} disabled={saving}>
                Load more shops and tills ({hierarchyMeta.current_page}/{hierarchyMeta.last_page})
              </button>
            )}
            <Field label="Valid from"><input className={inputClass} type="datetime-local" value={form.valid_from} disabled={saving} onChange={(event) => setForm({ ...form, valid_from: event.target.value })} /></Field>
            <Field label="Valid until"><input className={inputClass} type="datetime-local" value={form.valid_until} disabled={saving} onChange={(event) => setForm({ ...form, valid_until: event.target.value })} /></Field>
            <div className="lg:col-span-2"><Field label="Reason"><textarea className={textAreaClass} rows={3} maxLength={1000} value={form.reason} disabled={saving} onChange={(event) => setForm({ ...form, reason: event.target.value })} /></Field></div>
          </div>
          <div className="flex justify-end gap-2 border-t border-[#DAE0E0] pt-3">
            <button type="button" className={secondaryButton} disabled={saving} onClick={() => setEditorOpen(false)}>Cancel</button>
            <button type="submit" className={primaryButton} disabled={saving || loading}>{saving ? <RefreshCw size={13} className="animate-spin" /> : <ShieldCheck size={13} />} Save assignment</button>
          </div>
        </form>
      </EnterpriseFormModal>

      {revokeTarget && (
        <div className="fixed inset-0 z-[100010] flex items-center justify-center bg-black/55 px-4">
          <div className="sx-large-modal-window border border-[#BCC7C7] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Revoke access assignment">
            <div className="flex items-center gap-2 border-b border-[#DAE0E0] px-4 py-3 text-[#9B1C1C]"><AlertTriangle size={18} /><div className="text-[12px] font-black uppercase">Revoke scoped access</div></div>
            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4 text-[11px]">
              <p>Revoke <strong>{revokeTarget.role?.name}</strong> from <strong>{revokeTarget.user?.name}</strong> at <strong>{targetName(revokeTarget)}</strong>?</p>
              <Field label="Revocation reason" help="Recommended for a complete audit trail."><textarea className={textAreaClass} rows={3} maxLength={1000} value={revokeReason} onChange={(event) => setRevokeReason(event.target.value)} /></Field>
            </div>
            <div className="flex justify-end gap-2 border-t border-[#DAE0E0] bg-[#F8FAFC] p-3"><button type="button" className={secondaryButton} disabled={revoking} onClick={() => setRevokeTarget(null)}>Cancel</button><button type="button" className={`${dangerButton} h-9 px-4`} disabled={revoking} onClick={revoke}>{revoking ? "Revoking…" : "Revoke access"}</button></div>
          </div>
        </div>
      )}
    </div>
  );
}

function PolicyFieldEditor({ definition, settings, effectiveSettings, onChange, disabled }) {
  const included = getPath(settings, definition.path) !== undefined;
  const inherited = getPath(effectiveSettings, definition.path, definition.defaultValue);
  const value = included ? getPath(settings, definition.path) : inherited;
  const toggle = (checked) => onChange(checked
    ? setPath(settings, definition.path, inherited)
    : deletePath(settings, definition.path));
  const update = (raw) => {
    const normalized = definition.type === "number" ? Number(raw) : definition.type === "boolean" ? raw === "true" : raw;
    onChange(setPath(settings, definition.path, normalized));
  };

  return (
    <div className={`border p-2.5 ${included ? "border-[#53BCBC] bg-[#F2FCF9]" : "border-[#DAE0E0] bg-white"}`}>
      <div className="mb-2 flex items-start justify-between gap-2">
        <div><div className="text-[10px] font-bold text-[#2B3C3C]">{definition.label}</div>{definition.help && <div className="mt-0.5 text-[8px] text-[#758483]">{definition.help}</div>}</div>
        <label className="flex shrink-0 items-center gap-1 text-[8px] font-black uppercase text-[#556767]"><input type="checkbox" checked={included} disabled={disabled} onChange={(event) => toggle(event.target.checked)} /> Override</label>
      </div>
      {definition.type === "select" ? (
        <select className={inputClass} value={value} disabled={!included || disabled} onChange={(event) => update(event.target.value)}>{definition.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
      ) : definition.type === "boolean" ? (
        <select className={inputClass} value={String(Boolean(value))} disabled={!included || disabled} onChange={(event) => update(event.target.value)}><option value="true">Enabled</option><option value="false">Disabled</option></select>
      ) : (
        <input className={inputClass} type="number" min={definition.min} max={definition.max} step="1" value={value ?? ""} disabled={!included || disabled} onChange={(event) => update(event.target.value)} />
      )}
      {!included && <div className="mt-1 text-[8px] text-[#758483]">Inherited effective value: {typeof value === "boolean" ? (value ? "Enabled" : "Disabled") : String(value ?? "Not set")}</div>}
    </div>
  );
}

function PolicyWorkspace({ isPlatformAdmin, targets, hierarchyMeta, onLoadMoreHierarchy }) {
  const [policies, setPolicies] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, lastPage: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [disablingId, setDisablingId] = useState("");
  const [resolving, setResolving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [effective, setEffective] = useState(null);
  const [editorMode, setEditorMode] = useState("structured");
  const [jsonText, setJsonText] = useState("{}");
  const [settings, setSettings] = useState({});
  const [editorOpen, setEditorOpen] = useState(false);
  const [disableTarget, setDisableTarget] = useState(null);
  const [form, setForm] = useState({
    policy_key: "authentication",
    scope_type: "company",
    target_id: "",
    priority: "0",
    effective_from: "",
    effective_until: "",
  });

  const loadPolicies = useCallback(async (requestedPage = 1) => {
    setLoading(true);
    setError("");
    try {
      const response = await listAuthenticationPolicies({ per_page: 100, page: requestedPage });
      setPolicies(extractAdminRows(response));
      setPagination(extractAdminPagination(response));
    } catch (loadError) {
      setError(apiMessage(loadError, "Authentication policies could not be loaded."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadPolicies(page); }, [loadPolicies, page]);
  useEffect(() => {
    if (form.scope_type === "global") return;
    if ((targets[form.scope_type] || []).some((target) => target.id === form.target_id)) return;
    setForm((current) => ({ ...current, target_id: targets[current.scope_type]?.[0]?.id || "" }));
  }, [targets, form.scope_type, form.target_id]);

  const effectiveSettings = mergeSettings(defaultPolicySettings(form.policy_key), effective?.settings || {});
  const canResolveScope = form.scope_type === "global" || Boolean(form.target_id);

  const resolveEffective = async () => {
    if (!canResolveScope) {
      setError("Select an enterprise scope target before resolving policy.");
      return;
    }
    setResolving(true);
    setError("");
    try {
      const response = await getEffectiveAuthenticationPolicy({
        policy_key: form.policy_key,
        scope_type: form.scope_type,
        ...(form.scope_type === "global" ? {} : { target_id: form.target_id }),
      });
      setEffective(response?.data || response);
    } catch (resolveError) {
      setEffective(null);
      setError(apiMessage(resolveError, "The effective policy could not be resolved."));
    } finally {
      setResolving(false);
    }
  };

  useEffect(() => {
    setEffective(null);
    setSettings({});
    setJsonText("{}");
    setError("");
    setNotice("");
  }, [form.policy_key, form.scope_type, form.target_id]);

  const changeEditorMode = (mode) => {
    setError("");
    if (mode === editorMode) return;
    if (mode === "json") {
      setJsonText(JSON.stringify(settings, null, 2));
      setEditorMode(mode);
      return;
    }
    try {
      const parsed = parsePolicyJson(form.policy_key, jsonText, { baseline: effectiveSettings });
      setSettings(parsed);
      setEditorMode(mode);
    } catch (parseError) {
      setError(parseError.message);
    }
  };

  const publish = async (event) => {
    event.preventDefault();
    setError("");
    setNotice("");
    if (!canResolveScope) {
      setError("Select an enterprise scope target.");
      return;
    }
    if (!effective) {
      setError("Resolve the effective policy for this scope before publishing an override.");
      return;
    }
    if (form.effective_from && form.effective_until && new Date(form.effective_until) <= new Date(form.effective_from)) {
      setError("Effective until must be later than effective from.");
      return;
    }
    let draft;
    try {
      const priority = Number(form.priority || 0);
      if (!Number.isInteger(priority) || priority < 0 || priority > 100000) {
        throw new Error("Priority must be a whole number between 0 and 100000.");
      }
      draft = editorMode === "json"
        ? parsePolicyJson(form.policy_key, jsonText, { baseline: effectiveSettings, allowEmpty: false })
        : validatePolicySettings(form.policy_key, settings, { baseline: effectiveSettings, allowEmpty: false });
    } catch (validationError) {
      setError(validationError.message);
      return;
    }

    setSaving(true);
    try {
      const response = await publishAuthenticationPolicy({
        policy_key: form.policy_key,
        scope_type: form.scope_type,
        ...(form.scope_type === "global" ? {} : { target_id: form.target_id }),
        settings: draft,
        priority: Number(form.priority || 0),
        ...(form.effective_from ? { effective_from: toApiDate(form.effective_from) } : {}),
        ...(form.effective_until ? { effective_until: toApiDate(form.effective_until) } : {}),
      });
      setNotice(response?.message || "Authentication policy version published.");
      setSettings({});
      setJsonText("{}");
      setEditorOpen(false);
      await Promise.all([loadPolicies(page), resolveEffective()]);
    } catch (saveError) {
      setError(apiMessage(saveError, "The policy version could not be published."));
    } finally {
      setSaving(false);
    }
  };

  const disable = async (policy) => {
    setDisablingId(policy.id);
    setError("");
    try {
      const response = await disableAuthenticationPolicy(policy.id);
      setNotice(response?.message || "Authentication policy version disabled.");
      await loadPolicies(page);
      setEffective(null);
    } catch (disableError) {
      setError(apiMessage(disableError, "The policy version could not be disabled."));
    } finally {
      setDisablingId("");
      setDisableTarget(null);
    }
  };

  const policyTargetName = (policy) => {
    if (policy.scope_type === "global") return "Global platform";
    const id = scopeTargetId(policy);
    return targets[policy.scope_type]?.find((target) => target.id === id)?.label || policy.scope_key || `${policy.scope_type}: ${id}`;
  };

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden">
      <EnterpriseFormModal open={editorOpen} title="Publish Policy" onClose={() => !saving && setEditorOpen(false)} busy={saving}>
      <form className="space-y-3" onSubmit={publish}>
        <div className="space-y-3">
          <Notice type="error">{error}</Notice>
          <Field label="Policy area" required>
            <select className={inputClass} value={form.policy_key} disabled={saving} onChange={(event) => setForm({ ...form, policy_key: event.target.value })}>{POLICY_KEYS.map((key) => <option key={key.value} value={key.value}>{key.label}</option>)}</select>
          </Field>
          <ScopeEditor value={form} onChange={setForm} targets={targets} isPlatformAdmin={isPlatformAdmin} disabled={saving} />
          {Number(hierarchyMeta?.current_page || 1) < Number(hierarchyMeta?.last_page || 1) && <button type="button" className={secondaryButton} onClick={onLoadMoreHierarchy}>Load more shops and tills ({hierarchyMeta.current_page}/{hierarchyMeta.last_page})</button>}
          <button type="button" className={`${secondaryButton} w-full`} disabled={resolving || !canResolveScope} onClick={resolveEffective}><RefreshCw size={12} className={resolving ? "animate-spin" : ""} /> Resolve effective policy</button>

          {form.policy_key === "authentication" && (
            <div className="border border-[#95CECD] bg-[#F1F8F8] p-3">
              <div className="flex items-center gap-2 text-[10px] font-black uppercase text-[#054747]"><ShieldCheck size={14} /> Back Office login</div>
              <div className="mt-1 text-[11px] font-bold text-[#006868]">username_password</div>
              <div className="mt-1 text-[9px] leading-relaxed text-[#556767]">Fixed product security boundary. Back Office login codes are never permitted and this value cannot be edited.</div>
            </div>
          )}

          <div className="flex border border-[#ACB8B8] bg-white p-0.5">
            <button type="button" className={`flex h-8 flex-1 items-center justify-center gap-1 text-[9px] font-black uppercase ${editorMode === "structured" ? "bg-[#006B6B] text-white" : "text-[#556767]"}`} onClick={() => changeEditorMode("structured")}><ShieldCheck size={11} /> Guided fields</button>
            <button type="button" className={`flex h-8 flex-1 items-center justify-center gap-1 text-[9px] font-black uppercase ${editorMode === "json" ? "bg-[#006B6B] text-white" : "text-[#556767]"}`} onClick={() => changeEditorMode("json")}><Code2 size={11} /> Validated JSON</button>
          </div>

          {editorMode === "structured" ? (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {(POLICY_FIELDS[form.policy_key] || []).map((definition) => <PolicyFieldEditor key={definition.path} definition={definition} settings={settings} effectiveSettings={effectiveSettings} onChange={setSettings} disabled={saving} />)}
            </div>
          ) : (
            <Field label="Partial policy JSON" required help="Only supported fields and safe value types are accepted. This publishes a new immutable version; it does not edit an existing row.">
              <textarea className={`${textAreaClass} font-mono`} rows={16} spellCheck={false} value={jsonText} disabled={saving} onChange={(event) => setJsonText(event.target.value)} />
            </Field>
          )}

          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <Field label="Priority"><input className={inputClass} type="number" min="0" max="100000" step="1" value={form.priority} disabled={saving} onChange={(event) => setForm({ ...form, priority: event.target.value })} /></Field>
            <Field label="Effective from"><input className={inputClass} type="datetime-local" value={form.effective_from} disabled={saving} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} /></Field>
            <Field label="Effective until"><input className={inputClass} type="datetime-local" value={form.effective_until} disabled={saving} onChange={(event) => setForm({ ...form, effective_until: event.target.value })} /></Field>
          </div>
          <button type="submit" className={`${primaryButton} w-full`} disabled={saving}>{saving ? <RefreshCw size={13} className="animate-spin" /> : <Save size={13} />} Publish new version</button>
        </div>
      </form>
      </EnterpriseFormModal>

      <section className="flex min-h-0 flex-1 flex-col overflow-hidden bg-white">
        <div className="grid shrink-0 grid-cols-1 border-b border-[#DAE0E0] lg:grid-cols-2">
          <div className="border-b border-[#DAE0E0] p-3 lg:border-b-0 lg:border-r">
            <div className="flex items-center justify-between"><div className="text-[10px] font-black uppercase text-[#054747]">Effective result</div>{effective?.fingerprint && <span className="max-w-[170px] truncate font-mono text-[8px] text-[#637877]" title={effective.fingerprint}>{effective.fingerprint}</span>}</div>
            {effective ? <pre className="mt-2 max-h-40 overflow-auto bg-[#F5F7F9] p-2 text-[9px] leading-relaxed text-[#324444]">{JSON.stringify(effective.settings, null, 2)}</pre> : <div className="mt-2 border border-dashed border-[#CBD4D4] p-4 text-center text-[9px] text-[#758483]">Resolve the scope to inspect inherited and active settings before publishing.</div>}
          </div>
          <div className="p-3">
            <div className="text-[10px] font-black uppercase text-[#054747]">Resolution evidence</div>
            <div className="mt-2 grid grid-cols-2 gap-2 text-[9px]"><div className="bg-[#F5F7F9] p-2"><div className="font-black uppercase text-[#758483]">Applied versions</div><div className="mt-1 font-semibold">{effective?.applied_policy_ids?.length ?? "—"}</div></div><div className="bg-[#F5F7F9] p-2"><div className="font-black uppercase text-[#758483]">Resolved at</div><div className="mt-1 font-semibold">{effective?.resolved_at ? formatDate(effective.resolved_at) : "—"}</div></div></div>
            <div className="mt-2 text-[8px] leading-relaxed text-[#758483]">The effective result merges active versions from global through the selected enterprise scope. Disabling a version recalculates this chain.</div>
          </div>
        </div>
        <header className="flex items-center justify-between border-b border-[#DAE0E0] px-4 py-3"><div><div className="text-[12px] font-black uppercase text-[#054747]">Published policy versions</div><div className="text-[9px] text-[#637877]">{pagination.total} versions</div></div><div className="flex items-center gap-2"><button type="button" className={secondaryButton} onClick={() => loadPolicies(page)} disabled={loading}><RefreshCw size={12} className={loading ? "animate-spin" : ""} /> Refresh</button><button type="button" className={primaryButton} onClick={() => { setError(""); setEditorOpen(true); }}><KeyRound size={13} /> Publish policy</button></div></header>
        {(error || notice) && <div className="shrink-0 space-y-2 px-4 py-2"><Notice type="error">{error}</Notice><Notice type="success">{notice}</Notice></div>}
        <div className="min-h-0 flex-1 overflow-auto">
          <table className="w-full min-w-[900px] border-collapse text-left text-[10px]">
            <thead className="sticky top-0 z-10 bg-[#EFF3F3] text-[9px] font-black uppercase text-[#556767]"><tr><th className="border-b px-3 py-2">Policy</th><th className="border-b px-3 py-2">Scope</th><th className="border-b px-3 py-2">Version</th><th className="border-b px-3 py-2">Effective window</th><th className="border-b px-3 py-2">Status</th><th className="border-b px-3 py-2 text-right">More</th></tr></thead>
            <tbody>
              {policies.map((policy) => <tr key={policy.id} className="border-b border-[#E5E9E9] align-top hover:bg-[#F8FAFC]"><td className="px-3 py-2"><div className="font-bold text-[#054747]">{policy.policy_key}</div><div className="text-[8px] text-[#758483]">priority {policy.priority ?? 0}</div></td><td className="px-3 py-2"><div className="font-bold uppercase text-[#006B6B]">{policy.scope_type}</div><div className="max-w-[250px] truncate" title={policyTargetName(policy)}>{policyTargetName(policy)}</div></td><td className="px-3 py-2 font-black">v{policy.version}</td><td className="px-3 py-2"><div>{policy.effective_from ? formatDate(policy.effective_from) : "Immediate"}</div><div className="text-[#758483]">to {formatDate(policy.effective_until)}</div></td><td className="px-3 py-2"><span className={`inline-flex px-2 py-1 text-[8px] font-black uppercase ${policy.is_active ? "bg-[#DDF7EF] text-[#005D5D]" : "bg-[#E9EDED] text-[#556767]"}`}>{policy.is_active ? "Active" : "Disabled"}</span></td><td className="px-3 py-2 text-right">{policy.is_active && <EnterpriseRowActionMenu label={`More actions for ${policy.policy_key} policy version ${policy.version}`} actions={[{ label: disablingId === policy.id ? "Disabling policy" : "Disable policy", danger: true, disabled: disablingId === policy.id, onClick: () => setDisableTarget(policy) }]} />}</td></tr>)}
              {!loading && !policies.length && <tr><td colSpan={6} className="px-4 py-12 text-center text-[#758483]">No policy versions have been published for this company.</td></tr>}
              {loading && <tr><td colSpan={6} className="px-4 py-12 text-center text-[#758483]">Loading authentication policies…</td></tr>}
            </tbody>
          </table>
        </div>
        {pagination.lastPage > 1 && <footer className="flex shrink-0 items-center justify-between border-t border-[#DAE0E0] bg-[#F8FAFC] px-4 py-2 text-[9px] font-bold uppercase text-[#637877]"><button type="button" className={secondaryButton} disabled={loading || page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>Previous</button><span>Page {pagination.page} of {pagination.lastPage}</span><button type="button" className={secondaryButton} disabled={loading || page >= pagination.lastPage} onClick={() => setPage((current) => current + 1)}>Next</button></footer>}
      </section>
      <EnterpriseConfirmModal
        open={Boolean(disableTarget)}
        title="Disable Policy"
        message={disableTarget ? `Disable ${disableTarget.policy_key} policy version ${disableTarget.version}?` : ""}
        confirmLabel="Disable policy"
        busy={Boolean(disablingId)}
        onClose={() => !disablingId && setDisableTarget(null)}
        onConfirm={() => disableTarget && disable(disableTarget)}
      />
    </div>
  );
}

export default function AuthenticationAdministrationModal({
  open,
  onClose,
  isPlatformAdmin = false,
  canManageAssignments = false,
  canManagePolicies = false,
}) {
  const assignmentAccess = isPlatformAdmin || canManageAssignments;
  const policyAccess = isPlatformAdmin || canManagePolicies;
  const firstTab = assignmentAccess ? "assignments" : "policies";
  const [activeTab, setActiveTab] = useState(firstTab);
  const [hierarchyPages, setHierarchyPages] = useState([]);
  const [hierarchyMeta, setHierarchyMeta] = useState({ current_page: 1, last_page: 1 });
  const [hierarchyLoading, setHierarchyLoading] = useState(false);
  const [hierarchyError, setHierarchyError] = useState("");

  const loadHierarchy = useCallback(async (page = 1, append = false) => {
    setHierarchyLoading(true);
    setHierarchyError("");
    try {
      const response = await getTenantEnterpriseHierarchy({ page, per_page: 100 });
      setHierarchyPages((current) => append ? [...current, response] : [response]);
      setHierarchyMeta(extractHierarchyMeta(response));
    } catch (loadError) {
      setHierarchyError(apiMessage(loadError, "Accessible enterprise scope targets could not be loaded."));
    } finally {
      setHierarchyLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    setActiveTab(firstTab);
    loadHierarchy(1, false);
  }, [open, firstTab, loadHierarchy]);

  const targets = useMemo(() => buildEnterpriseScopeTargets(hierarchyPages), [hierarchyPages]);
  const loadMoreHierarchy = () => {
    const next = Number(hierarchyMeta?.current_page || 1) + 1;
    if (hierarchyLoading || next > Number(hierarchyMeta?.last_page || 1)) return;
    loadHierarchy(next, true);
  };

  return (
    <FullScreenModal open={open} onClose={onClose} title="Authentication & Enterprise Access" subtitle="Dynamic scoped security administration">
      <div className="flex h-full min-h-0 flex-col">
        <header className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-[#DAE0E0] bg-white px-4 py-2">
          <div className="flex items-center gap-1">
            {assignmentAccess && <button type="button" className={`flex h-9 items-center gap-2 px-4 text-[10px] font-black uppercase ${activeTab === "assignments" ? "bg-[#044747] text-white" : "bg-[#EFF3F3] text-[#556767]"}`} onClick={() => setActiveTab("assignments")}><UserRoundCheck size={14} /> Access assignments</button>}
            {policyAccess && <button type="button" className={`flex h-9 items-center gap-2 px-4 text-[10px] font-black uppercase ${activeTab === "policies" ? "bg-[#044747] text-white" : "bg-[#EFF3F3] text-[#556767]"}`} onClick={() => setActiveTab("policies")}><KeyRound size={14} /> Authentication policies</button>}
          </div>
          <div className="flex items-center gap-2 text-[9px] text-[#637877]">{hierarchyLoading ? <RefreshCw size={12} className="animate-spin" /> : <Clock3 size={12} />} Scope targets are limited to the selected company and your active grants</div>
        </header>
        {hierarchyError && <div className="shrink-0 px-4 pt-2"><Notice type="error">{hierarchyError}</Notice></div>}
        <main className="min-h-0 flex-1 overflow-hidden">
          {!assignmentAccess && !policyAccess ? (
            <div className="flex h-full items-center justify-center p-6"><div className="max-w-lg border border-[#E6A6A1] bg-[#FFF1F0] p-5 text-center"><AlertTriangle className="mx-auto text-[#B42318]" /><div className="mt-2 text-[12px] font-black uppercase text-[#9B1C1C]">Permission required</div><p className="mt-2 text-[10px] leading-relaxed text-[#7A2E2A]">This workspace requires manage_access_assignments or manage_authentication_policies.</p></div></div>
          ) : activeTab === "assignments" && assignmentAccess ? (
            <AssignmentWorkspace isPlatformAdmin={isPlatformAdmin} targets={targets} hierarchyMeta={hierarchyMeta} onLoadMoreHierarchy={loadMoreHierarchy} />
          ) : (
            <PolicyWorkspace isPlatformAdmin={isPlatformAdmin} targets={targets} hierarchyMeta={hierarchyMeta} onLoadMoreHierarchy={loadMoreHierarchy} />
          )}
        </main>
      </div>
    </FullScreenModal>
  );
}

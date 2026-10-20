import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  Alert,
  AppBar,
  Box,
  Button,
  Chip,
  Dialog,
  DialogContent,
  FormControl,
  FormControlLabel,
  InputLabel,
  MenuItem,
  Paper,
  Radio,
  RadioGroup,
  Select,
  Stack,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Toolbar,
  Typography,
} from "@mui/material";
import { Plus, RefreshCw, Utensils, X } from "lucide-react";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseRowActionMenu from "../../../components/EnterpriseRowActionMenu";
import { useOptionalWorkspace } from "../../../context/WorkspaceContext";
import {
  createHospitalityTable,
  getHospitalityTables,
  getHospitalityTableServiceSettings,
  updateHospitalityTable,
  updateHospitalityTableServiceSettings,
} from "../../../services/HospitalityTableManagementService";

const EMPTY_SETTINGS = {
  assignment_mode: "manual",
  require_table_for_dine_in: true,
  takeaway_uses_tables: false,
  allow_cross_till_recall: false,
  is_active: true,
};

const EMPTY_TABLE = {
  id: "",
  code: "",
  name: "",
  section: "",
  capacity: 2,
  sort_order: 0,
  is_active: true,
};

const truthy = (value) => value === true || value === 1 || value === "1" || String(value).toLowerCase() === "true";
const enabled = (value, fallback = true) => value == null ? fallback : truthy(value);
const entityId = (value) => value?.id || value?.shop_id || value?.branch_id || "";
const entityName = (value) => value?.shop_name || value?.branch_name || value?.name || value?.code || "Shop";
const errorMessage = (error, fallback) => {
  const payload = error?.response?.data || {};
  if (payload?.errors && typeof payload.errors === "object") {
    return Object.values(payload.errors).flat().join("\n");
  }
  return payload?.message || payload?.error || error?.message || fallback;
};

const normalizePermission = (value) => String(value || "").trim().toLowerCase().replaceAll("-", "_").replaceAll(" ", "_");
const permissionSet = (user) => {
  const result = new Set();
  [user?.permissions, user?.role?.permissions, user?.permission_names, user?.role_permissions]
    .forEach((bucket) => {
      if (Array.isArray(bucket)) {
        bucket.forEach((entry) => {
          const name = typeof entry === "string" ? entry : entry?.name || entry?.permission_name || entry?.key;
          if (name) result.add(normalizePermission(name));
        });
      } else if (bucket && typeof bucket === "object") {
        Object.entries(bucket).forEach(([name, enabled]) => {
          if (truthy(enabled)) result.add(normalizePermission(name));
        });
      }
    });
  return result;
};

const canManageTables = (user) => {
  const role = normalizePermission(typeof user?.role === "string" ? user.role : user?.role?.name || user?.role_name);
  return truthy(user?.is_super_admin)
    || ["super_admin", "super_administrator"].includes(role)
    || permissionSet(user).has("manage_hospitality_tables");
};

const rowsFrom = (value) => {
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  return [];
};

const normalizeScope = (row, fallback = {}) => {
  const shop = row?.shop || row?.selectedShop || row?.selected_shop || row;
  const shopId = row?.shop_id || entityId(shop) || fallback.shop_id || "";
  const branchId = row?.branch_id
    || row?.parent_branch_id
    || row?.branch?.id
    || shop?.branch_id
    || fallback.branch_id;
  if (!shopId || !branchId) return null;
  return {
    branch_id: String(branchId),
    shop_id: String(shopId),
    label: entityName(shop),
    detail: row?.brand_name || row?.complex_name || row?.branch_name || row?.code || "",
  };
};

const tableIdentity = (table) => table?.id || table?.table_id || "";
const activeOrder = (table) => table?.active_order || table?.open_order || null;
const tableIsActive = (table) => enabled(table?.is_active, true);

const normalizedSettings = (value = {}) => ({
  ...EMPTY_SETTINGS,
  ...value,
  assignment_mode: value?.assignment_mode === "auto" ? "auto" : "manual",
  require_table_for_dine_in: enabled(value?.require_table_for_dine_in, true),
  takeaway_uses_tables: false,
  allow_cross_till_recall: enabled(value?.allow_cross_till_recall, false),
  is_active: enabled(value?.is_active, true),
});

export default function HospitalityTableServiceModal({ open, onClose, currentUser = null }) {
  const workspace = useOptionalWorkspace();
  const [selectedScopeKey, setSelectedScopeKey] = useState("");
  const [settings, setSettings] = useState(EMPTY_SETTINGS);
  const [tables, setTables] = useState([]);
  const [loading, setLoading] = useState(false);
  const [savingSettings, setSavingSettings] = useState(false);
  const [savingTable, setSavingTable] = useState(false);
  const [togglingId, setTogglingId] = useState("");
  const [tableEditorOpen, setTableEditorOpen] = useState(false);
  const [tableForm, setTableForm] = useState(EMPTY_TABLE);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const manager = canManageTables(currentUser);
  const scopes = useMemo(() => {
    const fallback = {
      branch_id: currentUser?.selectedBranchId || currentUser?.selected_branch_id || currentUser?.branch_id || workspace?.data?.branch_id || "",
      shop_id: currentUser?.selectedShopId || currentUser?.selected_shop_id || currentUser?.shop_id || entityId(workspace?.shop) || "",
    };
    const candidates = [
      ...rowsFrom(workspace?.data?.available_shops),
      workspace?.shop,
      currentUser?.selectedShop,
      fallback.shop_id ? { ...fallback, name: currentUser?.shop_name || currentUser?.branch_name || workspace?.shop?.name || "Current shop" } : null,
    ].filter(Boolean);
    const unique = new Map();
    candidates.forEach((candidate) => {
      const normalized = normalizeScope(candidate, fallback);
      if (!normalized) return;
      unique.set(`${normalized.branch_id}:${normalized.shop_id}`, normalized);
    });
    return [...unique.values()];
  }, [currentUser, workspace?.data?.available_shops, workspace?.data?.branch_id, workspace?.shop]);

  const selectedScope = useMemo(
    () => scopes.find((scope) => `${scope.branch_id}:${scope.shop_id}` === selectedScopeKey) || null,
    [scopes, selectedScopeKey],
  );

  const loadScope = useCallback(async (scope) => {
    if (!scope) return;
    setLoading(true);
    setError("");
    try {
      const [loadedSettings, loadedTables] = await Promise.all([
        getHospitalityTableServiceSettings(scope),
        getHospitalityTables(scope),
      ]);
      setSettings(normalizedSettings(loadedSettings));
      setTables(Array.isArray(loadedTables) ? loadedTables : []);
    } catch (requestError) {
      setError(errorMessage(requestError, "Table service configuration could not be loaded."));
      setTables([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    setError("");
    setNotice("");
    setSelectedScopeKey((current) => (
      scopes.some((scope) => `${scope.branch_id}:${scope.shop_id}` === current)
        ? current
        : scopes[0] ? `${scopes[0].branch_id}:${scopes[0].shop_id}` : ""
    ));
  }, [open, scopes]);

  useEffect(() => {
    if (open && selectedScope) loadScope(selectedScope);
  }, [loadScope, open, selectedScope]);

  if (!open) return null;

  const saveSettings = async () => {
    if (!manager || !selectedScope) return;
    setSavingSettings(true);
    setError("");
    setNotice("");
    try {
      const saved = await updateHospitalityTableServiceSettings(selectedScope, {
        assignment_mode: settings.assignment_mode === "auto" ? "auto" : "manual",
        require_table_for_dine_in: Boolean(settings.require_table_for_dine_in),
        allow_cross_till_recall: Boolean(settings.allow_cross_till_recall),
        is_active: Boolean(settings.is_active),
      });
      setSettings(normalizedSettings(saved || settings));
      setNotice("Dining table rules saved for this shop.");
    } catch (requestError) {
      setError(errorMessage(requestError, "Dining table rules could not be saved."));
    } finally {
      setSavingSettings(false);
    }
  };

  const openNewTable = () => {
    if (!manager) return;
    setTableForm(EMPTY_TABLE);
    setTableEditorOpen(true);
  };

  const openEditTable = (table) => {
    if (!manager) return;
    setTableForm({
      ...EMPTY_TABLE,
      ...table,
      id: tableIdentity(table),
      capacity: Number(table?.capacity || 1),
      sort_order: Number(table?.sort_order || 0),
      is_active: tableIsActive(table),
    });
    setTableEditorOpen(true);
  };

  const saveTable = async () => {
    if (!manager || !selectedScope) return;
    if (!String(tableForm.code || "").trim() || !String(tableForm.name || "").trim()) {
      setError("Table code and name are required.");
      return;
    }
    if (Number(tableForm.capacity || 0) < 1) {
      setError("Table capacity must be at least one guest.");
      return;
    }

    setSavingTable(true);
    setError("");
    setNotice("");
    try {
      const payload = {
        code: String(tableForm.code).trim(),
        name: String(tableForm.name).trim(),
        section: String(tableForm.section || "").trim() || null,
        capacity: Number(tableForm.capacity),
        sort_order: Number(tableForm.sort_order || 0),
        is_active: Boolean(tableForm.is_active),
      };
      if (tableForm.id) await updateHospitalityTable(tableForm.id, selectedScope, payload);
      else await createHospitalityTable(selectedScope, payload);
      setTableEditorOpen(false);
      setNotice(tableForm.id ? "Dining table updated." : "Dining table created.");
      await loadScope(selectedScope);
    } catch (requestError) {
      setError(errorMessage(requestError, "The dining table could not be saved."));
    } finally {
      setSavingTable(false);
    }
  };

  const toggleTable = async (table) => {
    if (!manager || !selectedScope) return;
    const occupied = Boolean(activeOrder(table));
    const nextActive = !tableIsActive(table);
    if (occupied && !nextActive) {
      setError("An occupied table cannot be disabled. Complete or clear its open order first.");
      return;
    }
    const id = tableIdentity(table);
    setTogglingId(id);
    setError("");
    try {
      await updateHospitalityTable(id, selectedScope, { is_active: nextActive });
      setNotice(nextActive ? "Dining table enabled." : "Dining table disabled.");
      await loadScope(selectedScope);
    } catch (requestError) {
      setError(errorMessage(requestError, "The dining table status could not be changed."));
    } finally {
      setTogglingId("");
    }
  };

  return (
    <>
    <Dialog open fullScreen aria-labelledby="hospitality-table-service-title">
      <AppBar position="static" elevation={0} sx={{ bgcolor: "#004344", borderBottom: "4px solid #069494" }}>
        <Toolbar sx={{ minHeight: "64px !important", gap: 2 }}>
          <Box sx={{ width: 40, height: 40, borderRadius: 2, bgcolor: "#069494", display: "grid", placeItems: "center" }}>
            <Utensils size={21} />
          </Box>
          <Box sx={{ minWidth: 0, flex: 1 }}>
            <Typography id="hospitality-table-service-title" variant="h6" fontWeight={900}>Dining & table service</Typography>
            <Typography variant="caption" sx={{ color: "rgba(255,255,255,.72)" }}>Tables and seating rules</Typography>
          </Box>
          <Button color="inherit" startIcon={<X size={17} />} onClick={onClose}>Close</Button>
        </Toolbar>
      </AppBar>

      <DialogContent sx={{ bgcolor: "#F3F5F2", p: { xs: 1.5, md: 3 } }}>
        <Stack spacing={2.25} sx={{ maxWidth: 1280, mx: "auto" }}>
          {!manager && (
            <Alert severity="info">This workspace is read-only. Table management permission is required.</Alert>
          )}
          {error && <Alert severity="error" onClose={() => setError("")}>{error}</Alert>}
          {notice && <Alert severity="success" onClose={() => setNotice("")}>{notice}</Alert>}

          <Paper variant="outlined" sx={{ p: 2, borderColor: "#C7D8D2", borderRadius: 2 }}>
            <Stack direction={{ xs: "column", md: "row" }} spacing={2} alignItems={{ md: "center" }}>
              <FormControl size="small" sx={{ minWidth: { md: 360 } }}>
                <InputLabel id="hospitality-shop-scope-label">Shop</InputLabel>
                <Select
                  labelId="hospitality-shop-scope-label"
                  label="Shop"
                  value={selectedScopeKey}
                  onChange={(event) => setSelectedScopeKey(event.target.value)}
                >
                  {scopes.map((scope) => (
                    <MenuItem key={`${scope.branch_id}:${scope.shop_id}`} value={`${scope.branch_id}:${scope.shop_id}`}>
                      {scope.label}{scope.detail && scope.detail !== scope.label ? ` · ${scope.detail}` : ""}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <Box sx={{ flex: 1 }} />
              <Button
                variant="outlined"
                startIcon={<RefreshCw size={16} />}
                disabled={loading || !selectedScope}
                onClick={() => loadScope(selectedScope)}
              >
                Refresh
              </Button>
            </Stack>
            {!scopes.length && <Alert severity="warning" sx={{ mt: 2 }}>No accessible shop scope was returned for this Back Office user.</Alert>}
          </Paper>

          <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 }, borderColor: "#C7D8D2", borderRadius: 2, opacity: loading ? 0.65 : 1 }}>
            <Stack direction={{ xs: "column", lg: "row" }} spacing={3} alignItems={{ lg: "flex-start" }}>
              <Box sx={{ flex: 1 }}>
                <Typography variant="h6" fontWeight={900} color="#20352D">Table assignment</Typography>
                <RadioGroup
                  row
                  aria-label="Table assignment mode"
                  value={settings.assignment_mode}
                  onChange={(event) => setSettings((current) => ({ ...current, assignment_mode: event.target.value }))}
                  sx={{ mt: 1 }}
                >
                  <FormControlLabel disabled={!manager} value="manual" control={<Radio />} label="Cashier selects a table" />
                  <FormControlLabel disabled={!manager} value="auto" control={<Radio />} label="Assign next available table" />
                </RadioGroup>
                <Stack spacing={0.5} sx={{ mt: 1 }}>
                  <FormControlLabel
                    control={<Switch checked={Boolean(settings.require_table_for_dine_in)} disabled={!manager} onChange={(event) => setSettings((current) => ({ ...current, require_table_for_dine_in: event.target.checked }))} />}
                    label="Require a table before a dine-in order can start"
                  />
                  <FormControlLabel
                    control={<Switch checked={Boolean(settings.allow_cross_till_recall)} disabled={!manager} onChange={(event) => setSettings((current) => ({ ...current, allow_cross_till_recall: event.target.checked }))} />}
                    label="Allow tills in this shop to recall each other's open orders"
                  />
                  <FormControlLabel
                    control={<Switch checked={Boolean(settings.is_active)} disabled={!manager} onChange={(event) => setSettings((current) => ({ ...current, is_active: event.target.checked }))} />}
                    label="Table service is active for this shop"
                  />
                </Stack>
              </Box>
              <Box sx={{ width: { lg: 330 }, p: 2, borderRadius: 2, bgcolor: "#E8F7F5", border: "1px solid #B8DCDA" }}>
                <Typography fontWeight={900} color="#067C7C">Takeaway</Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mt: 0.75 }}>Takeaway never reserves a table.</Typography>
              </Box>
            </Stack>
            <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}>
              <Button variant="contained" disabled={!manager || !selectedScope || loading || savingSettings} onClick={saveSettings} sx={{ bgcolor: "#069494" }}>
                {savingSettings ? "Saving..." : "Save table rules"}
              </Button>
            </Stack>
          </Paper>

          <Paper variant="outlined" sx={{ borderColor: "#C7D8D2", borderRadius: 2, overflow: "hidden" }}>
            <Stack direction={{ xs: "column", sm: "row" }} spacing={1.5} alignItems={{ sm: "center" }} sx={{ p: 2, bgcolor: "#004344", color: "white" }}>
              <Box sx={{ flex: 1 }}>
                <Typography fontWeight={900}>Dining tables</Typography>
              </Box>
              <Button variant="contained" startIcon={<Plus size={16} />} disabled={!manager || !selectedScope} onClick={openNewTable} sx={{ bgcolor: "#069494", "&:hover": { bgcolor: "#067C7C" } }}>
                Add table
              </Button>
            </Stack>
            <TableContainer>
              <Table size="small" aria-label="Dining tables">
                <TableHead>
                  <TableRow sx={{ bgcolor: "#EAF3EF" }}>
                    <TableCell>Code</TableCell><TableCell>Name</TableCell><TableCell>Section</TableCell><TableCell align="right">Capacity</TableCell><TableCell>Order</TableCell><TableCell>Status</TableCell><TableCell align="right">More</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {tables.map((table) => {
                    const occupied = activeOrder(table);
                    const active = tableIsActive(table);
                    return (
                      <TableRow key={tableIdentity(table)} hover>
                        <TableCell sx={{ fontWeight: 900 }}>{table.code || "-"}</TableCell>
                        <TableCell>{table.name || "-"}</TableCell>
                        <TableCell>{table.section || "General"}</TableCell>
                        <TableCell align="right">{Number(table.capacity || 0)}</TableCell>
                        <TableCell>{occupied ? <Chip label={occupied.order_number || "Occupied"} size="small" color="warning" /> : <Chip label="Available" size="small" color="success" variant="outlined" />}</TableCell>
                        <TableCell><Chip size="small" label={active ? "Active" : "Inactive"} color={active ? "success" : "default"} /></TableCell>
                        <TableCell align="right">
                          <EnterpriseRowActionMenu
                            label={`More actions for ${table.name || table.code || "table"}`}
                            actions={[
                              { label: "Edit table", disabled: !manager, onClick: () => openEditTable(table) },
                              { label: active ? "Disable table" : "Enable table", danger: active, disabled: !manager || togglingId === tableIdentity(table) || (active && Boolean(occupied)), onClick: () => toggleTable(table) },
                            ]}
                          />
                        </TableCell>
                      </TableRow>
                    );
                  })}
                  {!loading && !tables.length && <TableRow><TableCell colSpan={7} align="center" sx={{ py: 5, color: "text.secondary" }}>No dining tables are configured for this shop.</TableCell></TableRow>}
                  {loading && <TableRow><TableCell colSpan={7} align="center" sx={{ py: 5 }}>Loading table service…</TableCell></TableRow>}
                </TableBody>
              </Table>
            </TableContainer>
          </Paper>
        </Stack>
      </DialogContent>
      <EnterpriseFormModal open={tableEditorOpen} onClose={() => !savingTable && setTableEditorOpen(false)} busy={savingTable} title={tableForm.id ? "Edit Dining Table" : "Add Dining Table"}>
        <form onSubmit={(event) => { event.preventDefault(); saveTable(); }}>
          <Stack spacing={2} sx={{ pt: 0.5 }}>
            <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
              <TextField label="Table code" value={tableForm.code} onChange={(event) => setTableForm((current) => ({ ...current, code: event.target.value }))} required fullWidth size="small" />
              <TextField label="Table name" value={tableForm.name} onChange={(event) => setTableForm((current) => ({ ...current, name: event.target.value }))} required fullWidth size="small" />
            </Stack>
            <TextField label="Section / area" value={tableForm.section || ""} onChange={(event) => setTableForm((current) => ({ ...current, section: event.target.value }))} fullWidth size="small" placeholder="Main floor, patio, upstairs…" />
            <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
              <TextField label="Guest capacity" type="number" inputProps={{ min: 1, max: 999 }} value={tableForm.capacity} onChange={(event) => setTableForm((current) => ({ ...current, capacity: event.target.value }))} fullWidth size="small" />
              <TextField label="Display order" type="number" inputProps={{ min: 0 }} value={tableForm.sort_order} onChange={(event) => setTableForm((current) => ({ ...current, sort_order: event.target.value }))} fullWidth size="small" />
            </Stack>
            <FormControlLabel control={<Switch checked={Boolean(tableForm.is_active)} onChange={(event) => setTableForm((current) => ({ ...current, is_active: event.target.checked }))} />} label="Active and available for new dine-in orders" />
          </Stack>
          <Stack direction="row" spacing={1} justifyContent="flex-end" sx={{ mt: 3, pt: 2, borderTop: "1px solid #D8E1E0" }}>
            <Button disabled={savingTable} onClick={() => setTableEditorOpen(false)}>Cancel</Button>
            <Button type="submit" variant="contained" disabled={savingTable} sx={{ bgcolor: "#069494" }}>{savingTable ? "Saving..." : "Save table"}</Button>
          </Stack>
        </form>
      </EnterpriseFormModal>
    </Dialog>
    </>
  );
}

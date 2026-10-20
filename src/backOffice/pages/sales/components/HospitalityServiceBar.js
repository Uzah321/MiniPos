import React, { useMemo, useState } from "react";
import {
  Alert,
  Badge,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip,
  Typography,
} from "@mui/material";
import ManagerApprovalModal from "../../../../components/ManagerApprovalModal";
import {
  hospitalityOrderId,
  hospitalityOrderRevision,
} from "../../../../services/HospitalityTableOrderService";

const tableId = (table) => table?.id || table?.table_id || "";
const tableName = (table) => table?.name || table?.table_name || table?.code || "Table";
const orderStatus = (order) => String(order?.status || order?.state || "open").toLowerCase();
const orderTable = (order) => order?.table || order?.hospitality_table || null;
const orderLabel = (order) =>
  order?.display_order_number || order?.order_number || order?.display_label || order?.reference || "Open order";
const itemCount = (order) =>
  Number(
    order?.item_count
    ?? order?.items_count
    ?? order?.line_count
    ?? order?.lines?.length
    ?? order?.items?.length
    ?? 0,
  );
const orderTotal = (order) => order?.total_amount ?? order?.total ?? null;

const largeDialogPaperSx = {
  width: "min(1180px, calc(100vw - 28px))",
  height: "min(90vh, 820px)",
  maxWidth: "none",
  maxHeight: "90vh",
  m: "14px",
  borderRadius: 0,
  "@media (max-width: 820px)": {
    width: "calc(100vw - 14px)",
    height: "calc(100vh - 14px)",
    maxHeight: "none",
    m: "7px",
  },
};

const tableUnavailable = (table, currentOrder) => {
  const active = table?.active_order || table?.open_order || null;
  if (!active) return false;
  return String(hospitalityOrderId(active)) !== String(hospitalityOrderId(currentOrder));
};

export default function HospitalityServiceBar({
  enabled = true,
  inline = false,
  serviceType = "takeaway",
  assignmentMode = "manual",
  selectedTableId = "",
  tables = [],
  currentOrder = null,
  openOrders = [],
  cartLength = 0,
  busy = false,
  saveError = "",
  canClear = false,
  onServiceTypeChange,
  onTableSelect,
  onRefresh,
  onRecall,
  onHang,
  onSendKitchen,
  onClear,
  onRetry,
}) {
  const [tableDialogOpen, setTableDialogOpen] = useState(false);
  const [ordersDialogOpen, setOrdersDialogOpen] = useState(false);
  const [pendingClear, setPendingClear] = useState(null);
  const [clearReason, setClearReason] = useState("");
  const [managerApprovalOpen, setManagerApprovalOpen] = useState(false);

  const selectedTable = useMemo(
    () => tables.find((table) => String(tableId(table)) === String(selectedTableId)) || orderTable(currentOrder),
    [tables, selectedTableId, currentOrder],
  );
  const isLocked = cartLength > 0 || Boolean(currentOrder);
  const openCount = openOrders.length;

  if (!enabled) return null;

  const changeServiceType = (_event, nextMode) => {
    if (!nextMode || nextMode === serviceType || isLocked) return;
    onServiceTypeChange?.(nextMode);
  };

  const beginClear = (order) => {
    setPendingClear(order);
    setClearReason("");
  };

  const submitClear = async () => {
    if (!clearReason.trim() || !pendingClear) return;
    if (!canClear) {
      setManagerApprovalOpen(true);
      return;
    }

    await onClear?.(pendingClear, clearReason.trim(), null);
    setPendingClear(null);
    setClearReason("");
  };

  const handleManagerApproved = async (approval) => {
    const approvalId = approval?.id || approval?.approval_id || approval?.data?.id;
    await onClear?.(pendingClear, clearReason.trim(), approvalId || null);
    setManagerApprovalOpen(false);
    setPendingClear(null);
    setClearReason("");
  };

  return (
    <>
      <Box
        data-testid="hospitality-service-bar"
        sx={inline ? {
          display: "flex",
          alignItems: "center",
          flex: 1,
          minWidth: 0,
        } : {
          borderBottom: "1px solid #BAC8C8",
          bgcolor: "#F8FAFC",
          px: 1.5,
          py: 0.75,
        }}
      >
        <Stack direction={inline ? "row" : { xs: "column", sm: "row" }} spacing={1} alignItems={inline ? "center" : { sm: "center" }} sx={inline ? { flex: 1, minWidth: 0 } : undefined}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ minWidth: 0, flexShrink: 0 }}>
            <Typography variant="caption" fontWeight={900} color="#546766" textTransform="uppercase" sx={inline ? { display: { xs: "none", xl: "block" }, fontSize: 9, letterSpacing: "0.12em" } : undefined}>
              Order type
            </Typography>
            <ToggleButtonGroup
              exclusive
              size="small"
              value={serviceType}
              onChange={changeServiceType}
              aria-label="Order service type"
              sx={{
                height: 32,
                "& .MuiToggleButton-root": { px: 1.5, py: 0, fontSize: 11, fontWeight: 800 },
                "& .Mui-selected": { bgcolor: "#069494 !important", color: "white !important" },
              }}
            >
              <ToggleButton value="dine_in" aria-label="Dine in" disabled={isLocked && serviceType !== "dine_in"}>
                Dine in
              </ToggleButton>
              <ToggleButton value="takeaway" aria-label="Takeaway" disabled={isLocked && serviceType !== "takeaway"}>
                Takeaway
              </ToggleButton>
            </ToggleButtonGroup>
          </Stack>

          {serviceType === "dine_in" && (
            <Button
              variant={selectedTable ? "contained" : "outlined"}
              size="small"
              disabled={busy || assignmentMode === "automatic"}
              onClick={() => setTableDialogOpen(true)}
              sx={{
                minHeight: 32,
                flexShrink: 0,
                whiteSpace: "nowrap",
                bgcolor: selectedTable ? "#004344" : undefined,
                fontSize: 11,
                fontWeight: 800,
              }}
            >
              {selectedTable
                ? `${tableName(selectedTable)} · ${orderStatus(currentOrder)}`
                : assignmentMode === "automatic"
                ? "Table assigns with first item"
                : "Assign table before adding items"}
            </Button>
          )}

          <Stack direction="row" spacing={1} alignItems="center" sx={{ ml: inline ? "auto !important" : { sm: "auto !important" }, flexShrink: 0 }}>
            {inline && saveError && (
              <Tooltip title={saveError}>
                <span>
                  <Button
                    size="small"
                    color="error"
                    variant="outlined"
                    disabled={busy}
                    onClick={onRetry}
                    sx={{ minHeight: 32, fontSize: 10, fontWeight: 800, whiteSpace: "nowrap" }}
                  >
                    Save failed · Retry
                  </Button>
                </span>
              </Tooltip>
            )}
            {currentOrder && (
              <>
                <Chip
                  size="small"
                  label={`${orderLabel(currentOrder)} · ${orderStatus(currentOrder)}`}
                  color={orderStatus(currentOrder) === "hanging" ? "warning" : "success"}
                  variant="outlined"
                  sx={{ fontWeight: 800 }}
                />
                <Tooltip title="Keep this unpaid order for later and release the selling screen">
                  <span>
                    <Button
                      size="small"
                      variant="outlined"
                      disabled={busy}
                      onClick={() => onHang?.(currentOrder)}
                      sx={{ minHeight: 32, fontSize: 10, fontWeight: 800 }}
                    >
                      Use later
                    </Button>
                  </span>
                </Tooltip>
                <Tooltip title="Save this order and print its new or changed items to the configured kitchen printer">
                  <span>
                    <Button
                      size="small"
                      variant="contained"
                      disabled={busy || cartLength === 0}
                      onClick={() => onSendKitchen?.(currentOrder)}
                      sx={{ minHeight: 32, bgcolor: "#069494", "&:hover": { bgcolor: "#067C7C" }, fontSize: 10, fontWeight: 800 }}
                    >
                      Send kitchen
                    </Button>
                  </span>
                </Tooltip>
              </>
            )}

            <Badge
              badgeContent={openCount}
              color="warning"
              max={99}
              sx={inline ? { "& .MuiBadge-badge": { top: 6, right: 6 } } : undefined}
            >
              <Button
                size="small"
                variant="contained"
                onClick={() => {
                  onRefresh?.();
                  setOrdersDialogOpen(true);
                }}
                sx={{ minHeight: 32, bgcolor: "#004344", fontSize: 10, fontWeight: 800 }}
              >
                Open tables
              </Button>
            </Badge>
          </Stack>
        </Stack>

        {saveError && !inline && (
          <Alert
            severity="error"
            action={
              <Button color="inherit" size="small" disabled={busy} onClick={onRetry}>
                Retry save
              </Button>
            }
            sx={{ mt: 0.75, py: 0, fontSize: 11 }}
          >
            {saveError}
          </Alert>
        )}
      </Box>

      <Dialog open={tableDialogOpen} onClose={() => setTableDialogOpen(false)} fullWidth maxWidth={false} PaperProps={{ sx: largeDialogPaperSx }}>
        <DialogTitle sx={{ fontWeight: 900 }}>Assign a restaurant table</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            This shop uses manual table assignment. Occupied tables cannot be assigned to a second open order.
          </Typography>
          <FormControl fullWidth size="small">
            <InputLabel id="hospitality-table-label">Table</InputLabel>
            <Select
              labelId="hospitality-table-label"
              label="Table"
              value={selectedTableId || ""}
              onChange={(event) => onTableSelect?.(event.target.value)}
            >
              {tables.map((table) => (
                <MenuItem key={tableId(table)} value={tableId(table)} disabled={tableUnavailable(table, currentOrder)}>
                  {tableName(table)}
                  {table?.capacity ? ` · ${table.capacity} seats` : ""}
                  {tableUnavailable(table, currentOrder) ? " · occupied" : " · available"}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          {!tables.length && <Alert severity="warning" sx={{ mt: 2 }}>No active tables are configured for this shop.</Alert>}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTableDialogOpen(false)}>Cancel</Button>
          <Button
            variant="contained"
            disabled={!selectedTableId}
            onClick={() => setTableDialogOpen(false)}
            sx={{ bgcolor: "#069494" }}
          >
            Use selected table
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={ordersDialogOpen} onClose={() => setOrdersDialogOpen(false)} fullWidth maxWidth={false} PaperProps={{ sx: largeDialogPaperSx }}>
        <DialogTitle sx={{ fontWeight: 900 }}>Open and hanging orders</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={1.25}>
            {busy && !openOrders.length && <CircularProgress size={26} sx={{ alignSelf: "center", my: 4 }} />}
            {!busy && !openOrders.length && (
              <Alert severity="info">There are no open or hanging orders on this till.</Alert>
            )}
            {openOrders.map((order) => {
              const assigned = orderTable(order);
              return (
                <Box key={hospitalityOrderId(order)} sx={{ border: "1px solid #CBD6D6", borderRadius: 1, p: 1.5 }}>
                  <Stack direction={{ xs: "column", sm: "row" }} spacing={1} alignItems={{ sm: "center" }}>
                    <Box sx={{ minWidth: 0, flex: 1 }}>
                      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                        <Typography fontWeight={900}>{orderLabel(order)}</Typography>
                        <Chip size="small" label={orderStatus(order)} color={orderStatus(order) === "hanging" ? "warning" : "success"} />
                        <Chip size="small" variant="outlined" label={assigned ? tableName(assigned) : "Takeaway"} />
                      </Stack>
                      <Typography variant="caption" color="text.secondary">
                        {itemCount(order)} item{itemCount(order) === 1 ? "" : "s"}
                        {orderTotal(order) != null ? ` · ${order.currency_code || ""} ${Number(orderTotal(order)).toFixed(2)}` : ""}
                        {order?.opened_by?.name ? ` · ${order.opened_by.name}` : ""}
                      </Typography>
                    </Box>
                    {/* Recalling another order parks the one on screen first. */}
                    <Stack direction="row" spacing={1}>
                      <Button
                        variant="contained"
                        disabled={busy}
                        onClick={async () => {
                          setOrdersDialogOpen(false);
                          await onRecall?.(order, { pay: true });
                        }}
                        sx={{ bgcolor: "#069494", whiteSpace: "nowrap" }}
                      >
                        Recall &amp; pay
                      </Button>
                      <Button
                        variant="outlined"
                        disabled={busy}
                        onClick={async () => {
                          setOrdersDialogOpen(false);
                          await onRecall?.(order);
                        }}
                        sx={{ whiteSpace: "nowrap" }}
                      >
                        Add items
                      </Button>
                      <Button color="error" variant="outlined" disabled={busy} onClick={() => beginClear(order)}>
                        Clear
                      </Button>
                    </Stack>
                  </Stack>
                </Box>
              );
            })}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button disabled={busy} onClick={onRefresh}>Refresh</Button>
          <Button onClick={() => setOrdersDialogOpen(false)}>Close</Button>
        </DialogActions>
      </Dialog>

      {/* Step aside while the manager approves: the approval box renders inside
          the sale screen, below this dialog's layer. The reason is kept. */}
      <Dialog open={Boolean(pendingClear) && !managerApprovalOpen} onClose={() => setPendingClear(null)} fullWidth maxWidth={false} PaperProps={{ sx: largeDialogPaperSx }}>
        <DialogTitle sx={{ fontWeight: 900 }}>Clear open table</DialogTitle>
        <DialogContent dividers>
          <Alert severity="warning" sx={{ mb: 2 }}>
            This removes the unpaid order and releases its table. The reason and approving user are audited.
          </Alert>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={3}
            label="Required reason"
            value={clearReason}
            onChange={(event) => setClearReason(event.target.value)}
          />
          {!canClear && (
            <Typography variant="caption" color="text.secondary" sx={{ display: "block", mt: 1.5 }}>
              Your role cannot clear open tables directly. A manager with the required permission must approve.
            </Typography>
          )}
        </DialogContent>
        <Divider />
        <DialogActions>
          <Button onClick={() => setPendingClear(null)}>Cancel</Button>
          <Button color="error" variant="contained" disabled={!clearReason.trim() || busy} onClick={submitClear}>
            {canClear ? "Clear table" : "Manager approval"}
          </Button>
        </DialogActions>
      </Dialog>

      <ManagerApprovalModal
        open={managerApprovalOpen}
        onClose={() => setManagerApprovalOpen(false)}
        onApproved={handleManagerApproved}
        action="clear_open_table"
        permission="clear_open_tables"
        title="Manager approval: clear open table"
        description="A manager with permission must approve removal of this unpaid hospitality order."
        reason={clearReason}
        referenceType="hospitality_table_order"
        referenceId={hospitalityOrderId(pendingClear)}
        metadata={{
          expected_revision: hospitalityOrderRevision(pendingClear),
          service_type: pendingClear?.service_type || pendingClear?.service_mode || null,
          table_id: tableId(orderTable(pendingClear)) || null,
        }}
      />
    </>
  );
}


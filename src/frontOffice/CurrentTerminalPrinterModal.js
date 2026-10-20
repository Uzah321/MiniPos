import React, { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  FileKey2,
  KeyRound,
  Loader2,
  Pencil,
  Plus,
  Printer,
  RefreshCw,
  Send,
  ShieldAlert,
  ShieldCheck,
  Trash2,
  X,
} from "lucide-react";
import {
  createCurrentTerminalPrinter,
  deleteCurrentTerminalQzCertificate,
  deleteCurrentTerminalPrinter,
  getCurrentTerminalPrinters,
  getCurrentTerminalKitchenOutput,
  getCurrentTerminalQzCertificate,
  saveCurrentTerminalQzCertificate,
  updateCurrentTerminalPrinter,
  updateCurrentTerminalKitchenOutput,
} from "../services/CurrentTerminalService";
import {
  Alert,
  Button,
  Chip,
  FormControlLabel,
  MenuItem,
  Radio,
  RadioGroup,
  Select,
  Stack,
  Switch,
  Typography,
} from "@mui/material";
import {
  qzConnect,
  qzDisconnect,
  qzGetDefaultPrinter,
  qzGetPrinters,
  qzPrintImageBase64,
} from "../services/QzService";

export const CURRENT_TERMINAL_PRINT_TARGETS = [
  "receipt",
  "kitchen",
  "bar",
  "invoice",
  "report",
  "barcode",
  "label",
  "general",
];

const EMPTY_FORM = {
  id: "",
  printer_name: "",
  target_printing: "receipt",
  printer_type: "qz",
  paper_size: "80mm",
  connection_type: "local",
  ip_address: "",
  port: "",
  is_default: true,
  is_active: true,
  notes: "",
};

const EMPTY_QZ_FORM = {
  name: "",
  certificate: null,
  private_key: null,
  is_active: true,
  notes: "",
};

const EMPTY_KITCHEN_OUTPUT = {
  mode: "kitchen_printer",
  auto_print: true,
  copies: 1,
  capabilities: {
    kitchen_printer: { available: true },
    kds: { available: false, status: "planned" },
  },
  ready: false,
};

const normalizeRows = (payload) => {
  const value = payload?.data ?? payload;
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  return [];
};

const normalizeCertificate = (payload) => {
  if (payload && Object.prototype.hasOwnProperty.call(payload, "data")) {
    return payload.data;
  }

  return payload?.certificate ?? payload ?? null;
};

const requestMessage = (error, fallback) =>
  error?.response?.data?.message || error?.message || fallback;

const targetLabel = (value) => String(value || "general")
  .replaceAll("_", " ")
  .replace(/\b\w/g, (character) => character.toUpperCase());

const toPrinterPayload = (form) => ({
  printer_name: String(form.printer_name || "").trim(),
  target_printing: form.target_printing,
  printer_type: form.printer_type,
  paper_size: String(form.paper_size || "").trim() || null,
  connection_type: form.connection_type,
  ip_address: form.connection_type === "network"
    ? String(form.ip_address || "").trim() || null
    : null,
  port: form.connection_type === "network" && form.port !== ""
    ? Number(form.port)
    : null,
  is_default: Boolean(form.is_default),
  is_active: Boolean(form.is_active),
  notes: String(form.notes || "").trim() || null,
});

const testPageBase64 = (printerName) => {
  const canvas = document.createElement("canvas");
  canvas.width = 576;
  canvas.height = 260;
  const context = canvas.getContext("2d");
  if (!context) throw new Error("The browser could not prepare a printer test page.");
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.fillStyle = "#111111";
  context.font = "bold 30px Arial";
  context.fillText("SERVENEST POS", 30, 55);
  context.font = "22px Arial";
  context.fillText("Current terminal printer test", 30, 100);
  context.font = "18px Arial";
  context.fillText(`Printer: ${printerName}`, 30, 145);
  context.fillText(new Date().toLocaleString(), 30, 185);
  context.fillText("--------------------------------", 30, 225);
  return canvas.toDataURL("image/png").split(",")[1];
};

export default function CurrentTerminalPrinterModal({ open, onClose, canManagePrinters = true }) {
  const [printers, setPrinters] = useState([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [detecting, setDetecting] = useState(false);
  const [testing, setTesting] = useState(false);
  const [qzCertificate, setQzCertificate] = useState(null);
  const [qzCertificateLoading, setQzCertificateLoading] = useState(false);
  const [qzCertificateSaving, setQzCertificateSaving] = useState(false);
  const [qzCertificateDeleting, setQzCertificateDeleting] = useState(false);
  const [kitchenOutput, setKitchenOutput] = useState(EMPTY_KITCHEN_OUTPUT);
  const [kitchenOutputLoading, setKitchenOutputLoading] = useState(false);
  const [kitchenOutputSaving, setKitchenOutputSaving] = useState(false);
  const [qzFormOpen, setQzFormOpen] = useState(false);
  const [qzForm, setQzForm] = useState(EMPTY_QZ_FORM);
  const [confirmCertificateDelete, setConfirmCertificateDelete] = useState(false);
  const [deletingId, setDeletingId] = useState("");
  const [confirmDeleteId, setConfirmDeleteId] = useState("");
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [detectedPrinters, setDetectedPrinters] = useState([]);
  const [defaultPrinter, setDefaultPrinter] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const loadPrinters = async () => {
    setLoading(true);
    setError("");
    try {
      const response = await getCurrentTerminalPrinters();
      setPrinters(normalizeRows(response));
    } catch (requestError) {
      setError(requestMessage(requestError, "Printer setup could not be loaded."));
    } finally {
      setLoading(false);
    }
  };

  const loadQzCertificate = async () => {
    setQzCertificateLoading(true);
    try {
      const response = await getCurrentTerminalQzCertificate();
      setQzCertificate(normalizeCertificate(response));
    } catch (requestError) {
      setError(requestMessage(requestError, "QZ security setup could not be loaded."));
    } finally {
      setQzCertificateLoading(false);
    }
  };

  const loadKitchenOutput = async () => {
    if (typeof getCurrentTerminalKitchenOutput !== "function") return;
    setKitchenOutputLoading(true);
    try {
      const response = await getCurrentTerminalKitchenOutput();
      const output = response?.data?.data || response?.data || response;
      setKitchenOutput({
        ...EMPTY_KITCHEN_OUTPUT,
        ...(output || {}),
        mode: output?.mode || "kitchen_printer",
        auto_print: output?.auto_print !== false,
        copies: Number(output?.copies || 1),
      });
    } catch (requestError) {
      setError(requestMessage(requestError, "Kitchen output setup could not be loaded."));
    } finally {
      setKitchenOutputLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    setFormOpen(false);
    setQzFormOpen(false);
    setConfirmDeleteId("");
    setConfirmCertificateDelete(false);
    setNotice("");
    loadPrinters();
    loadQzCertificate();
    loadKitchenOutput();
  }, [open]);

  const activeCount = useMemo(
    () => printers.filter((printer) => Boolean(printer?.is_active)).length,
    [printers],
  );

  const qzSecurityReady = Boolean(
    qzCertificate?.is_active
    && qzCertificate?.certificate_configured
    && qzCertificate?.private_key_configured,
  );

  const saveKitchenOutput = async () => {
    if (!canManagePrinters) {
      setError("Printer Management permission is required to change kitchen output.");
      return;
    }
    if (typeof updateCurrentTerminalKitchenOutput !== "function") {
      setError("Kitchen output API is not available on this local POS yet.");
      return;
    }
    setKitchenOutputSaving(true);
    setError("");
    setNotice("");
    try {
      const response = await updateCurrentTerminalKitchenOutput(kitchenOutput);
      const output = response?.data?.data || response?.data || response;
      setKitchenOutput((current) => ({ ...current, ...(output || {}) }));
      setNotice(response?.message || "Kitchen printer output saved for this terminal.");
      await loadKitchenOutput();
    } catch (requestError) {
      setError(requestMessage(requestError, "Kitchen output setup could not be saved."));
    } finally {
      setKitchenOutputSaving(false);
    }
  };

  if (!open) return null;

  const updateForm = (field, value) => setForm((current) => ({ ...current, [field]: value }));
  const updateQzForm = (field, value) => setQzForm((current) => ({ ...current, [field]: value }));
  const requirePrinterManagement = () => {
    if (canManagePrinters) return true;
    setNotice("");
    setError("Printer Management permission is required to change this terminal's printer setup.");
    return false;
  };

  const openQzSecurityForm = () => {
    if (!requirePrinterManagement()) return;
    setQzForm({
      ...EMPTY_QZ_FORM,
      name: qzCertificate?.name || "",
      is_active: qzCertificate?.is_active ?? true,
      notes: qzCertificate?.notes || "",
    });
    setError("");
    setNotice("");
    setQzFormOpen(true);
  };

  const openCreate = () => {
    if (!requirePrinterManagement()) return;
    setForm({
      ...EMPTY_FORM,
      printer_name: defaultPrinter || detectedPrinters[0] || "",
    });
    setError("");
    setNotice("");
    setFormOpen(true);
  };

  const openEdit = (printer) => {
    if (!requirePrinterManagement()) return;
    setForm({
      ...EMPTY_FORM,
      ...printer,
      id: printer?.id || "",
      port: printer?.port ?? "",
      is_default: Boolean(printer?.is_default),
      is_active: printer?.is_active !== false && Number(printer?.is_active) !== 0,
    });
    setError("");
    setNotice("");
    setFormOpen(true);
  };

  const detectPrinters = async () => {
    if (!requirePrinterManagement()) return;
    if (!qzSecurityReady) {
      setError("Configure an active QZ certificate and private key for this terminal before detecting printers.");
      setNotice("");
      return;
    }

    setDetecting(true);
    setError("");
    setNotice("");
    try {
      await qzConnect();
      const [available, detectedDefault] = await Promise.all([
        qzGetPrinters(),
        qzGetDefaultPrinter(),
      ]);
      const rows = Array.isArray(available) ? available : [];
      setDetectedPrinters(rows);
      setDefaultPrinter(detectedDefault || "");
      if (!form.printer_name && (detectedDefault || rows[0])) {
        updateForm("printer_name", detectedDefault || rows[0]);
      }
      setNotice(rows.length
        ? `${rows.length} local printer${rows.length === 1 ? "" : "s"} detected.`
        : "QZ Tray connected, but no local printers were returned.");
    } catch (requestError) {
      setError(requestMessage(requestError, "QZ Tray could not detect local printers."));
    } finally {
      setDetecting(false);
    }
  };

  const testPrinter = async () => {
    if (!requirePrinterManagement()) return;
    if (!qzSecurityReady) {
      setError("Configure an active QZ certificate and private key for this terminal before testing a printer.");
      setNotice("");
      return;
    }

    const printerName = String(form.printer_name || "").trim();
    if (!printerName) {
      setError("Select or enter a printer before sending a test.");
      return;
    }

    setTesting(true);
    setError("");
    setNotice("");
    try {
      await qzPrintImageBase64({
        printerName,
        imageBase64: testPageBase64(printerName),
        jobName: "ServeNest current terminal printer test",
      });
      setNotice(`Test page sent to ${printerName}.`);
    } catch (requestError) {
      setError(requestMessage(requestError, "The printer test failed."));
    } finally {
      setTesting(false);
    }
  };

  const savePrinter = async (event) => {
    event.preventDefault();
    if (!requirePrinterManagement()) return;
    if (!String(form.printer_name || "").trim()) {
      setError("Printer name is required.");
      return;
    }

    setSaving(true);
    setError("");
    setNotice("");
    try {
      const payload = toPrinterPayload(form);
      const response = form.id
        ? await updateCurrentTerminalPrinter(form.id, payload)
        : await createCurrentTerminalPrinter(payload);
      setNotice(response?.message || "Printer setup saved.");
      setFormOpen(false);
      await loadPrinters();
    } catch (requestError) {
      setError(requestMessage(requestError, "The printer setup could not be saved."));
    } finally {
      setSaving(false);
    }
  };

  const deletePrinter = async (printerId) => {
    if (!requirePrinterManagement()) return;
    setDeletingId(printerId);
    setError("");
    setNotice("");
    try {
      const response = await deleteCurrentTerminalPrinter(printerId);
      setConfirmDeleteId("");
      setNotice(response?.message || "Printer override removed.");
      await loadPrinters();
    } catch (requestError) {
      setError(requestMessage(requestError, "The printer override could not be removed."));
    } finally {
      setDeletingId("");
    }
  };

  const saveQzCertificate = async (event) => {
    event.preventDefault();
    if (!requirePrinterManagement()) return;
    const hasCertificateFile = Boolean(qzForm.certificate);
    const hasPrivateKeyFile = Boolean(qzForm.private_key);

    if (!hasCertificateFile || !hasPrivateKeyFile) {
      setError("Select both the QZ certificate and its matching private key.");
      return;
    }

    setQzCertificateSaving(true);
    setError("");
    setNotice("");
    try {
      const response = await saveCurrentTerminalQzCertificate(qzForm);
      await qzDisconnect().catch(() => undefined);
      setQzCertificate(normalizeCertificate(response));
      setNotice(response?.message || "QZ security credentials saved for this terminal.");
      setQzFormOpen(false);
      await loadQzCertificate();
    } catch (requestError) {
      setError(requestMessage(requestError, "QZ security credentials could not be saved."));
    } finally {
      setQzCertificateSaving(false);
    }
  };

  const removeQzCertificate = async () => {
    if (!requirePrinterManagement()) return;
    setQzCertificateDeleting(true);
    setError("");
    setNotice("");
    try {
      const response = await deleteCurrentTerminalQzCertificate();
      await qzDisconnect().catch(() => undefined);
      setQzCertificate(null);
      setDetectedPrinters([]);
      setDefaultPrinter("");
      setConfirmCertificateDelete(false);
      setNotice(response?.message || "QZ security credentials removed from this terminal.");
    } catch (requestError) {
      setError(requestMessage(requestError, "QZ security credentials could not be removed."));
    } finally {
      setQzCertificateDeleting(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[100000] flex items-center justify-center bg-[#003637]/65 p-4 font-sans"
      onMouseDown={() => !saving && !qzCertificateSaving && onClose()}
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="current-terminal-printers-title"
        className="flex max-h-[90vh] min-h-[620px] w-full max-w-[1180px] flex-col overflow-hidden rounded-md border border-[#069494] bg-white shadow-2xl"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="flex items-start gap-3 bg-gradient-to-r from-[#067C7C] to-[#006565] px-5 py-4 text-white">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/15"><Printer className="h-6 w-6" /></span>
          <div className="min-w-0 flex-1">
            <h2 id="current-terminal-printers-title" className="text-[17px] font-semibold">Printer Setup</h2>
            <p className="mt-1 text-[11px] text-white/80">This terminal's printers and QZ security</p>
          </div>
          <button type="button" aria-label="Close printer setup" disabled={saving || qzCertificateSaving} onClick={onClose} className="flex h-8 w-8 items-center justify-center rounded border border-white/30 bg-white/10 disabled:opacity-40"><X className="h-4 w-4" /></button>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto p-5">
          {error && <div role="alert" className="mb-3 rounded border border-[#F5C2C0] bg-[#FFF2F1] px-3 py-2 text-[11px] text-[#9F241E]">{error}</div>}
          {notice && <div role="status" className="mb-3 rounded border border-[#A9D8C6] bg-[#EFFBF6] px-3 py-2 text-[11px] text-[#136C4E]">{notice}</div>}
          {!canManagePrinters && (
            <Alert severity="info" role="status" sx={{ mb: 2 }}>
              Printer status is read-only. Printer Management permission is required to change QZ credentials, physical printers or kitchen output.
            </Alert>
          )}

          {!formOpen && !qzFormOpen && (
            <section data-testid="kitchen-output-setup" className="mb-4 rounded border border-[#A6D8D7] bg-[#F2FBF9] p-4">
              <Stack direction={{ xs: "column", md: "row" }} spacing={2} alignItems={{ md: "flex-start" }}>
                <div className="min-w-0 flex-1">
                  <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                    <Typography variant="subtitle2" fontWeight={900} color="#045F5F">Kitchen order output</Typography>
                    <Chip size="small" label="QZ Tray" sx={{ bgcolor: "#069494", color: "white", fontWeight: 800 }} />
                    <Chip size="small" label={kitchenOutput?.ready ? "Ready" : "Setup required"} color={kitchenOutput?.ready ? "success" : "warning"} variant="outlined" />
                  </Stack>
                  <Typography variant="caption" color="text.secondary" sx={{ display: "block", mt: 0.5 }}>
                    Kitchen printer tickets are implemented now. KDS is retained as a planned module and cannot be selected yet.
                  </Typography>

                  <RadioGroup
                    row
                    value={kitchenOutput?.mode || "kitchen_printer"}
                    onChange={(event) => setKitchenOutput((current) => ({ ...current, mode: event.target.value }))}
                    aria-label="Kitchen order output mode"
                    sx={{ mt: 1 }}
                  >
                    <FormControlLabel value="kitchen_printer" disabled={!canManagePrinters} control={<Radio size="small" />} label="Kitchen printer" />
                    <FormControlLabel
                      value="kds"
                      disabled
                      control={<Radio size="small" />}
                      label="KDS (coming later)"
                    />
                  </RadioGroup>

                  <Stack direction={{ xs: "column", sm: "row" }} spacing={2} alignItems={{ sm: "center" }} sx={{ mt: 0.5 }}>
                    <FormControlLabel
                      control={(
                        <Switch
                          checked={Boolean(kitchenOutput?.auto_print)}
                          disabled={!canManagePrinters}
                          onChange={(event) => setKitchenOutput((current) => ({ ...current, auto_print: event.target.checked }))}
                          color="success"
                        />
                      )}
                      label="Print automatically when order is sent"
                    />
                    <Stack direction="row" spacing={1} alignItems="center">
                      <Typography variant="caption" fontWeight={800}>Copies</Typography>
                      <Select
                        size="small"
                        value={Number(kitchenOutput?.copies || 1)}
                        disabled={!canManagePrinters}
                        onChange={(event) => setKitchenOutput((current) => ({ ...current, copies: Number(event.target.value) }))}
                        inputProps={{ "aria-label": "Kitchen ticket copies" }}
                        sx={{ minWidth: 72, bgcolor: "white" }}
                      >
                        {[1, 2, 3, 4, 5].map((copies) => <MenuItem key={copies} value={copies}>{copies}</MenuItem>)}
                      </Select>
                    </Stack>
                  </Stack>

                  {!kitchenOutput?.ready && !kitchenOutputLoading && (
                    <Alert severity="info" role="status" sx={{ mt: 1.25, py: 0 }}>
                      Add an active QZ printer with Print Target set to Kitchen, then configure this terminal&apos;s QZ certificate and signing key.
                    </Alert>
                  )}
                </div>
                <Button
                  variant="contained"
                  disabled={!canManagePrinters || kitchenOutputLoading || kitchenOutputSaving}
                  onClick={saveKitchenOutput}
                  sx={{ bgcolor: "#069494", minWidth: 150, fontWeight: 800 }}
                >
                  {kitchenOutputSaving ? "Saving..." : "Save kitchen output"}
                </Button>
              </Stack>
            </section>
          )}

          {qzFormOpen ? (
            <form onSubmit={saveQzCertificate} className="space-y-4">
              <div className="flex items-center justify-between border-b border-[#D7E0E0] pb-3">
                <div>
                  <h3 className="text-[14px] font-semibold text-[#054747]">{qzCertificate ? "Replace QZ Security" : "Configure QZ Security"}</h3>
                  <p className="mt-1 text-[10px] text-[#6F7E7D]">The certificate and matching signing key are locked to this activated terminal by the local API.</p>
                </div>
                <FileKey2 className="h-6 w-6 text-[#007474]" />
              </div>

              <div className="rounded border border-[#C4D6D5] bg-[#F4F9F9] px-4 py-3 text-[11px] leading-5 text-[#1F6363]">
                The private key uploads directly to the local POS server. It is never displayed, downloaded, or stored in this browser. Select both files together whenever credentials are installed or replaced.
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Certificate Name">
                  <input value={qzForm.name} onChange={(event) => updateQzForm("name", event.target.value)} className="terminal-printer-input" placeholder="e.g. Counter 1 QZ" />
                </Field>
                <div className="hidden sm:block" />
                <Field label={qzCertificate ? "Replacement Certificate" : "Certificate File"}>
                  <input type="file" accept=".txt,.pem,.crt,.cer" onChange={(event) => updateQzForm("certificate", event.target.files?.[0] || null)} className="terminal-printer-file" required />
                </Field>
                <Field label={qzCertificate ? "Replacement Private Key" : "Private Key File"}>
                  <input type="file" accept=".pem,.key,.txt" onChange={(event) => updateQzForm("private_key", event.target.files?.[0] || null)} className="terminal-printer-file" required />
                </Field>
              </div>

              <Field label="Notes"><textarea rows={2} value={qzForm.notes} onChange={(event) => updateQzForm("notes", event.target.value)} className="terminal-printer-input h-auto py-2" placeholder="Optional local terminal notes" /></Field>

              <label className="flex items-center gap-2 rounded border border-[#CCD6D6] bg-[#F8FAFC] px-4 py-3 text-[11px] text-[#255858]"><input type="checkbox" checked={qzForm.is_active} onChange={(event) => updateQzForm("is_active", event.target.checked)} />Active for QZ Tray signing</label>

              <div className="flex justify-end gap-2 border-t border-[#D7E0E0] pt-4">
                <button type="button" onClick={() => setQzFormOpen(false)} disabled={qzCertificateSaving} className="h-9 rounded border border-[#A1B0AF] bg-white px-4 text-[11px] font-semibold text-[#3F4E4D] disabled:opacity-45">Back</button>
                <button type="submit" disabled={!canManagePrinters || qzCertificateSaving} className="flex h-9 items-center gap-2 rounded border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white disabled:opacity-45">{qzCertificateSaving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ShieldCheck className="h-3.5 w-3.5" />}{qzCertificateSaving ? "Saving" : qzCertificate ? "Replace Credentials" : "Save Credentials"}</button>
              </div>
            </form>
          ) : !formOpen ? (
            <>
              <section className={`mb-4 rounded border p-4 ${qzSecurityReady ? "border-[#A9D8C6] bg-[#F2FBF7]" : "border-[#EDC58F] bg-[#FFF9EE]"}`}>
                <div className="flex flex-wrap items-start gap-3">
                  <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded ${qzSecurityReady ? "bg-[#DDF4EA] text-[#167052]" : "bg-[#FFF0D6] text-[#9A5A00]"}`}>
                    {qzSecurityReady ? <ShieldCheck className="h-5 w-5" /> : <ShieldAlert className="h-5 w-5" />}
                  </span>
                  <div className="min-w-[230px] flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="text-[13px] font-semibold text-[#054747]">QZ Tray Security</h3>
                      <span className={`rounded px-2 py-1 text-[9px] font-bold uppercase tracking-wide ${qzSecurityReady ? "bg-[#DDF4EA] text-[#167052]" : "bg-[#FFF0D6] text-[#9A5A00]"}`}>{qzCertificateLoading ? "Checking" : qzSecurityReady ? "Ready" : "Setup required"}</span>
                    </div>
                    <p className="mt-1 text-[10px] leading-5 text-[#566868]">{qzCertificate?.name || "Install this terminal's certificate and private key before detecting or testing local printers."}</p>
                    <div className="mt-2 flex flex-wrap gap-2 text-[9px] font-semibold uppercase tracking-wide">
                      <span className={`rounded px-2 py-1 ${qzCertificate?.certificate_configured ? "bg-white text-[#167052]" : "bg-white text-[#9F241E]"}`}><FileKey2 className="mr-1 inline h-3 w-3" />Certificate {qzCertificate?.certificate_configured ? "ready" : "missing"}</span>
                      <span className={`rounded px-2 py-1 ${qzCertificate?.private_key_configured ? "bg-white text-[#167052]" : "bg-white text-[#9F241E]"}`}><KeyRound className="mr-1 inline h-3 w-3" />Signing key {qzCertificate?.private_key_configured ? "ready" : "missing"}</span>
                    </div>
                  </div>
                  <div className="flex flex-wrap justify-end gap-2">
                    <button type="button" onClick={openQzSecurityForm} disabled={!canManagePrinters || qzCertificateLoading} className="h-8 rounded border border-[#006161] bg-white px-3 text-[10px] font-semibold text-[#006161] disabled:opacity-45">{qzCertificate ? "Replace Certificate & Key" : "Add Certificate & Key"}</button>
                    {qzCertificate && !confirmCertificateDelete && <button type="button" disabled={!canManagePrinters} onClick={() => setConfirmCertificateDelete(true)} className="h-8 rounded border border-[#D5A5A1] bg-white px-3 text-[10px] font-semibold text-[#9F241E] disabled:opacity-45">Remove</button>}
                    {qzCertificate && confirmCertificateDelete && <><button type="button" onClick={() => setConfirmCertificateDelete(false)} disabled={qzCertificateDeleting} className="h-8 rounded border border-[#A1B0AF] bg-white px-3 text-[10px]">Cancel</button><button type="button" onClick={removeQzCertificate} disabled={!canManagePrinters || qzCertificateDeleting} className="h-8 rounded border border-[#B42318] bg-[#C7352A] px-3 text-[10px] font-semibold text-white disabled:opacity-45">{qzCertificateDeleting ? "Removing" : "Confirm Remove"}</button></>}
                  </div>
                </div>
              </section>

              <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="mr-auto text-[11px] text-[#566868]">{printers.length} configured · {activeCount} active</div>
                <button type="button" onClick={() => { loadPrinters(); loadQzCertificate(); }} disabled={loading || qzCertificateLoading} className="flex h-9 items-center gap-2 rounded border border-[#A1B0AF] bg-white px-3 text-[11px] font-semibold text-[#3F4E4D] disabled:opacity-45"><RefreshCw className={`h-3.5 w-3.5 ${loading || qzCertificateLoading ? "animate-spin" : ""}`} />Refresh</button>
                <button type="button" onClick={detectPrinters} disabled={!canManagePrinters || detecting} className="flex h-9 items-center gap-2 rounded border border-[#A1B0AF] bg-white px-3 text-[11px] font-semibold text-[#3F4E4D] disabled:opacity-45"><Printer className="h-3.5 w-3.5" />{detecting ? "Detecting" : "Detect Local"}</button>
                <button type="button" onClick={openCreate} disabled={!canManagePrinters} className="flex h-9 items-center gap-2 rounded border border-[#006161] bg-[#067C7C] px-4 text-[11px] font-semibold text-white disabled:opacity-45"><Plus className="h-3.5 w-3.5" />Add Printer</button>
              </div>

              {loading ? (
                <div className="flex min-h-[240px] items-center justify-center gap-2 text-[12px] text-[#566868]"><Loader2 className="h-4 w-4 animate-spin" />Loading this terminal&apos;s printers…</div>
              ) : printers.length === 0 ? (
                <div className="flex min-h-[240px] flex-col items-center justify-center rounded border border-dashed border-[#BCCAC9] bg-[#F8FAFC] px-5 text-center">
                  <Printer className="mb-3 h-10 w-10 text-[#829594]" />
                  <div className="text-[13px] font-semibold text-[#255858]">No terminal printer overrides</div>
                  <p className="mt-1 max-w-[470px] text-[11px] leading-5 text-[#6F7E7D]">Detect a printer connected to this computer, then save it for receipts, kitchen tickets, bar tickets or another output.</p>
                </div>
              ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                  {printers.map((printer) => (
                    <article key={printer.id} className="rounded border border-[#CCD6D6] bg-[#F8FAFC] p-4">
                      <div className="flex items-start gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-[#E7F1F1] text-[#007474]"><Printer className="h-5 w-5" /></span>
                        <div className="min-w-0 flex-1">
                          <div className="truncate text-[13px] font-semibold text-[#054747]" title={printer.printer_name}>{printer.printer_name}</div>
                          <div className="mt-1 flex flex-wrap gap-1.5 text-[9px] font-bold uppercase tracking-wide">
                            <span className="rounded bg-[#DFEAEA] px-2 py-1 text-[#1F6363]">{targetLabel(printer.target_printing)}</span>
                            {printer.is_default ? <span className="rounded bg-[#DDF4EA] px-2 py-1 text-[#167052]">Default</span> : null}
                            <span className={`rounded px-2 py-1 ${printer.is_active ? "bg-[#DDF4EA] text-[#167052]" : "bg-[#ECEFEF] text-[#6C7B7A]"}`}>{printer.is_active ? "Active" : "Inactive"}</span>
                          </div>
                          <div className="mt-2 text-[10px] text-[#6F7E7D]">{targetLabel(printer.printer_type)} · {printer.paper_size || "Default paper"}</div>
                        </div>
                      </div>
                      <div className="mt-4 flex justify-end gap-2 border-t border-[#D7E0E0] pt-3">
                        {confirmDeleteId === printer.id ? (
                          <>
                            <span className="mr-auto self-center text-[10px] font-semibold text-[#9F241E]">Remove this override?</span>
                            <button type="button" onClick={() => setConfirmDeleteId("")} className="h-7 rounded border border-[#A1B0AF] bg-white px-3 text-[10px]">Cancel</button>
                            <button type="button" onClick={() => deletePrinter(printer.id)} disabled={!canManagePrinters || deletingId === printer.id} className="h-7 rounded border border-[#B42318] bg-[#C7352A] px-3 text-[10px] font-semibold text-white disabled:opacity-45">{deletingId === printer.id ? "Removing" : "Remove"}</button>
                          </>
                        ) : (
                          <>
                            <button type="button" disabled={!canManagePrinters} onClick={() => openEdit(printer)} className="flex h-7 items-center gap-1 rounded border border-[#A1B0AF] bg-white px-3 text-[10px] disabled:opacity-45"><Pencil className="h-3 w-3" />Edit</button>
                            <button type="button" disabled={!canManagePrinters} onClick={() => setConfirmDeleteId(printer.id)} className="flex h-7 items-center gap-1 rounded border border-[#D5A5A1] bg-white px-3 text-[10px] text-[#9F241E] disabled:opacity-45"><Trash2 className="h-3 w-3" />Remove</button>
                          </>
                        )}
                      </div>
                    </article>
                  ))}
                </div>
              )}
            </>
          ) : (
            <form onSubmit={savePrinter} className="space-y-4">
              <div className="flex items-center justify-between border-b border-[#D7E0E0] pb-3">
                <div><h3 className="text-[14px] font-semibold text-[#054747]">{form.id ? "Edit Printer" : "Add Printer"}</h3><p className="mt-1 text-[10px] text-[#6F7E7D]">This configuration is automatically locked to the current terminal.</p></div>
                <button type="button" onClick={detectPrinters} disabled={!canManagePrinters || detecting} className="flex h-8 items-center gap-2 rounded border border-[#A1B0AF] bg-white px-3 text-[10px] font-semibold disabled:opacity-45"><RefreshCw className={`h-3 w-3 ${detecting ? "animate-spin" : ""}`} />{detecting ? "Detecting" : "Detect Printers"}</button>
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Printer Name">
                  <input list="current-terminal-printer-names" value={form.printer_name} onChange={(event) => updateForm("printer_name", event.target.value)} required className="terminal-printer-input" placeholder="Windows / QZ printer name" />
                  <datalist id="current-terminal-printer-names">{detectedPrinters.map((name) => <option key={name} value={name} />)}</datalist>
                  {defaultPrinter && <button type="button" onClick={() => updateForm("printer_name", defaultPrinter)} className="mt-1 text-[10px] font-semibold text-[#007474]">Use local default: {defaultPrinter}</button>}
                </Field>
                <Field label="Print Target">
                  <select value={form.target_printing} onChange={(event) => updateForm("target_printing", event.target.value)} className="terminal-printer-input">{CURRENT_TERMINAL_PRINT_TARGETS.map((target) => <option key={target} value={target}>{targetLabel(target)}</option>)}</select>
                </Field>
                <Field label="Printer Method">
                  <select value={form.printer_type} onChange={(event) => updateForm("printer_type", event.target.value)} className="terminal-printer-input"><option value="qz">QZ Local</option><option value="browser">Browser Print</option><option value="network">Network Printer</option></select>
                </Field>
                <Field label="Paper Size">
                  <input value={form.paper_size} onChange={(event) => updateForm("paper_size", event.target.value)} className="terminal-printer-input" placeholder="80mm" />
                </Field>
                <Field label="Connection">
                  <select value={form.connection_type} onChange={(event) => updateForm("connection_type", event.target.value)} className="terminal-printer-input"><option value="local">Local</option><option value="network">Network</option><option value="usb">USB</option></select>
                </Field>
                {form.connection_type === "network" && (
                  <div className="grid grid-cols-[1fr_110px] gap-2">
                    <Field label="IP Address"><input value={form.ip_address} onChange={(event) => updateForm("ip_address", event.target.value)} className="terminal-printer-input" placeholder="192.168.1.50" /></Field>
                    <Field label="Port"><input type="number" min="1" max="65535" value={form.port} onChange={(event) => updateForm("port", event.target.value)} className="terminal-printer-input" placeholder="9100" /></Field>
                  </div>
                )}
              </div>

              <Field label="Notes"><textarea rows={2} value={form.notes} onChange={(event) => updateForm("notes", event.target.value)} className="terminal-printer-input h-auto py-2" placeholder="Optional notes for this terminal" /></Field>

              <div className="flex flex-wrap gap-5 rounded border border-[#CCD6D6] bg-[#F8FAFC] px-4 py-3 text-[11px] text-[#255858]">
                <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_default} onChange={(event) => updateForm("is_default", event.target.checked)} />Default for this target</label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={(event) => updateForm("is_active", event.target.checked)} />Active</label>
              </div>

              <div className="flex flex-wrap justify-end gap-2 border-t border-[#D7E0E0] pt-4">
                <button type="button" onClick={() => setFormOpen(false)} disabled={saving} className="h-9 rounded border border-[#A1B0AF] bg-white px-4 text-[11px] font-semibold text-[#3F4E4D] disabled:opacity-45">Back</button>
                <button type="button" onClick={testPrinter} disabled={!canManagePrinters || testing || !form.printer_name} className="flex h-9 items-center gap-2 rounded border border-[#168A62] bg-white px-4 text-[11px] font-semibold text-[#136C4E] disabled:opacity-45">{testing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}{testing ? "Sending" : "Test Print"}</button>
                <button type="submit" disabled={!canManagePrinters || saving || !form.printer_name} className="flex h-9 items-center gap-2 rounded border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white disabled:opacity-45">{saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5" />}{saving ? "Saving" : "Save Printer"}</button>
              </div>
            </form>
          )}
        </div>

        {!formOpen && !qzFormOpen && <footer className="flex justify-end border-t border-[#D7E0E0] bg-[#F8FAFC] px-5 py-4"><button type="button" onClick={onClose} className="h-9 rounded border border-[#A1B0AF] bg-white px-4 text-[11px] font-semibold text-[#3F4E4D]">Close</button></footer>}
        <style>{`.terminal-printer-input{height:2.5rem;width:100%;border-radius:.25rem;border:1px solid #b2c1c0;background:#fff;padding:0 .75rem;font-size:12px;color:#054747;outline:none}.terminal-printer-file{min-height:2.5rem;width:100%;border-radius:.25rem;border:1px solid #b2c1c0;background:#fff;padding:.5rem .75rem;font-size:11px;color:#054747;outline:none}.terminal-printer-input:focus,.terminal-printer-file:focus{border-color:#067c7c;box-shadow:0 0 0 1px #067c7c}`}</style>
      </section>
    </div>
  );
}

const Field = ({ label, children }) => (
  <label className="block">
    <span className="mb-1 block text-[10px] font-bold uppercase tracking-wide text-[#566868]">{label}</span>
    {children}
  </label>
);

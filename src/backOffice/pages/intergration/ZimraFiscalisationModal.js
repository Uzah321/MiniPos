import React, { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  Clock3,
  Database,
  FileKey2,
  KeyRound,
  Loader2,
  MonitorCheck,
  PlayCircle,
  Printer,
  RefreshCw,
  RotateCcw,
  Search,
  ServerCog,
  ShieldCheck,
  Upload,
  XCircle,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import {
  getZimraSettings,
  updateZimraSettings,
  getFiscalDevices,
  saveFiscalDevice,
  syncFiscalDeviceConfig,
  getFiscalDeviceStatus,
  getFiscalDays,
  openFiscalDay,
  closeFiscalDay,
  pollFiscalDay,
  getZimraReceipts,
  retryZimraSale,
} from "../../../services/ZimraService";

const emptySettings = {
  enabled: false,
  environment: "testing",
  base_url: "",
  auto_open_day: false,
  block_sales_without_open_day: true,
  block_sale_if_fiscalisation_fails: false,
};

const emptyDeviceForm = {
  branch_id: "",
  device_id: "",
  device_serial_no: "",
  model_name: "SERVENEST-POS",
  model_version: "1.0.0",
  environment: "testing",
  base_url: "",
  certificate_file: null,
  private_key_file: null,
  certificate_password: "",
};

const boolValue = (v) => v === true || v === 1 || v === "1" || v === "true";
const rows = (res) => {
  const d = res?.data || res;
  if (Array.isArray(d?.data)) return d.data;
  if (Array.isArray(d)) return d;
  return [];
};
const obj = (res) => res?.data || res || null;
const errMsg = (err) => {
  const d = err?.response?.data;
  if (d?.errors) return Object.values(d.errors).flat().join("\n");
  return d?.message || d?.error || err?.message || "Request failed.";
};
const fmt = (v) => (v ? new Date(v).toLocaleString() : "-");
const money = (v, c = "USD") => `${c || "USD"} ${Number(v || 0).toFixed(2)}`;

export default function ZimraFiscalisationModal({ open, onClose }) {
  const [activeTab, setActiveTab] = useState("overview");
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);
  const [settings, setSettings] = useState(emptySettings);
  const [devices, setDevices] = useState([]);
  const [days, setDays] = useState([]);
  const [receipts, setReceipts] = useState([]);
  const [deviceForm, setDeviceForm] = useState(emptyDeviceForm);
  const [deviceFormOpen, setDeviceFormOpen] = useState(false);
  const [selectedDeviceId, setSelectedDeviceId] = useState("");
  const [selectedDayId, setSelectedDayId] = useState("");
  const [receiptSearch, setReceiptSearch] = useState("");
  const [receiptStatus, setReceiptStatus] = useState("");
  const [dayStatus, setDayStatus] = useState("");
  const [statusPanel, setStatusPanel] = useState(null);

  useEffect(() => {
    if (open) loadAll();
  }, [open]);

  const selectedDevice = useMemo(
    () => devices.find((d) => String(d.id) === String(selectedDeviceId)) || devices[0] || null,
    [devices, selectedDeviceId]
  );

  const openDay = useMemo(
    () => days.find((d) => ["opened", "close_failed", "close_initiated"].includes(String(d.status || "").toLowerCase())) || null,
    [days]
  );

  const selectedDay = useMemo(
    () => days.find((d) => String(d.id) === String(selectedDayId)) || openDay || days[0] || null,
    [days, selectedDayId, openDay]
  );

  const filteredDays = useMemo(
    () => (dayStatus ? days.filter((d) => d.status === dayStatus) : days),
    [days, dayStatus]
  );

  const filteredReceipts = useMemo(() => {
    const q = receiptSearch.toLowerCase();
    return receipts.filter((r) => {
      const okStatus = receiptStatus ? r.status === receiptStatus : true;
      const okSearch = [r.invoice_no, r.fdms_receipt_id, r.operation_id, r.status, r.error, r.receipt_currency, r.receipt_global_no]
        .join(" ")
        .toLowerCase()
        .includes(q);
      return okStatus && okSearch;
    });
  }, [receipts, receiptSearch, receiptStatus]);

  if (!open) return null;

  const toast = (msg) => {
    setNotice(msg);
    setTimeout(() => setNotice(""), 4500);
  };

  async function loadAll() {
    try {
      setLoading(true);
      const [settingsRes, deviceRes, dayRes, receiptRes] = await Promise.all([
        getZimraSettings(),
        getFiscalDevices({ per_page: 100 }),
        getFiscalDays({ per_page: 100 }),
        getZimraReceipts({ per_page: 100 }),
      ]);
      const loadedDevices = rows(deviceRes);
      const loadedDays = rows(dayRes);
      setSettings({ ...emptySettings, ...(obj(settingsRes) || {}) });
      setDevices(loadedDevices);
      setDays(loadedDays);
      setReceipts(rows(receiptRes));
      if (!selectedDeviceId && loadedDevices.length) setSelectedDeviceId(loadedDevices[0].id);
      if (!selectedDayId && loadedDays.length) setSelectedDayId(loadedDays[0].id);
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function saveSettings() {
    try {
      setLoading(true);
      const res = await updateZimraSettings({
        enabled: boolValue(settings.enabled),
        environment: settings.environment || "testing",
        base_url: settings.base_url || null,
        auto_open_day: boolValue(settings.auto_open_day),
        block_sales_without_open_day: boolValue(settings.block_sales_without_open_day),
        block_sale_if_fiscalisation_fails: boolValue(settings.block_sale_if_fiscalisation_fails),
      });
      setSettings({ ...emptySettings, ...(res?.data || res) });
      toast("ZIMRA settings saved successfully.");
      await loadAll();
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function submitDevice() {
    if (!deviceForm.certificate_file) return toast("Certificate file is required.");
    if (!deviceForm.private_key_file) return toast("Private key file is required.");
    try {
      setLoading(true);
      const res = await saveFiscalDevice(deviceForm);
      const saved = res?.data || res;
      toast("Fiscal device saved successfully.");
      setDeviceForm({ ...emptyDeviceForm, environment: settings.environment || "testing", base_url: settings.base_url || "" });
      setDeviceFormOpen(false);
      await loadAll();
      if (saved?.id) setSelectedDeviceId(saved.id);
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function syncDevice(id = selectedDevice?.id) {
    if (!id) return toast("Select a fiscal device first.");
    try {
      setLoading(true);
      const res = await syncFiscalDeviceConfig(id);
      setStatusPanel(res?.data || res);
      toast("Device config synced successfully.");
      await loadAll();
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function checkStatus(id = selectedDevice?.id) {
    if (!id) return toast("Select a fiscal device first.");
    try {
      setLoading(true);
      const res = await getFiscalDeviceStatus(id);
      setStatusPanel(res?.data || res);
      toast("Device status fetched successfully.");
      await loadAll();
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function openDayForDevice(id = selectedDevice?.id) {
    if (!id) return toast("Select a fiscal device first.");
    try {
      setLoading(true);
      const res = await openFiscalDay({ fiscal_device_id: id });
      const day = res?.data || res;
      toast("Fiscal day opened successfully.");
      await loadAll();
      if (day?.id) setSelectedDayId(day.id);
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function closeDay(id = selectedDay?.id) {
    if (!id) return toast("Select a fiscal day first.");
    if (!await requestEnterpriseConfirmation({
      title: "Close Fiscal Day",
      message: "Close this fiscal day?",
      confirmLabel: "Close fiscal day",
    })) return;
    try {
      setLoading(true);
      const res = await closeFiscalDay(id);
      const day = res?.data || res;
      toast("Fiscal day close initiated successfully.");
      await loadAll();
      if (day?.id) setSelectedDayId(day.id);
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function pollDay(id = selectedDay?.id) {
    if (!id) return toast("Select a fiscal day first.");
    try {
      setLoading(true);
      const res = await pollFiscalDay(id);
      const day = res?.data || res;
      setStatusPanel(day?.last_status_response || day);
      toast("Fiscal day status updated successfully.");
      await loadAll();
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  async function retryReceipt(receipt) {
    if (!receipt?.sales_transaction_id) return toast("Receipt has no sale reference.");
    try {
      setLoading(true);
      await retryZimraSale(receipt.sales_transaction_id);
      toast("Receipt retry completed.");
      await loadAll();
    } catch (e) {
      toast(errMsg(e));
    } finally {
      setLoading(false);
    }
  }

  return (
    <FullScreenModal open={open} onClose={onClose} title="ZIMRA FISCALISATION" subtitle="FDMS CONTROL CENTRE">
      <div className="relative h-[calc(100vh-86px)] overflow-hidden bg-[#D8DFDF] text-[#1C2C2B]">
        {notice && <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 whitespace-pre-line border border-[#004343] bg-[#004344] px-4 py-2 text-[12px] font-semibold text-white shadow-xl">{notice}</div>}
        <div className="grid h-full min-h-0 grid-rows-[auto_minmax(0,1fr)] gap-2 p-2">
          <TopBar activeTab={activeTab} setActiveTab={setActiveTab} loading={loading} loadAll={loadAll} settings={settings} openDay={openDay} selectedDevice={selectedDevice} />
          {activeTab === "overview" && <Overview settings={settings} devices={devices} days={days} receipts={receipts} openDay={openDay} selectedDevice={selectedDevice} setActiveTab={setActiveTab} loading={loading} syncDevice={syncDevice} checkStatus={checkStatus} openDayForDevice={openDayForDevice} closeDay={closeDay} pollDay={pollDay} />}
          {activeTab === "settings" && <Settings settings={settings} setSettings={setSettings} saveSettings={saveSettings} loading={loading} />}
          {activeTab === "devices" && <Devices devices={devices} selectedDeviceId={selectedDeviceId} setSelectedDeviceId={setSelectedDeviceId} form={deviceForm} setForm={setDeviceForm} submitDevice={submitDevice} loading={loading} settings={settings} selectedDevice={selectedDevice} syncDevice={syncDevice} checkStatus={checkStatus} statusPanel={statusPanel} formOpen={deviceFormOpen} setFormOpen={setDeviceFormOpen} />}
          {activeTab === "days" && <Days days={filteredDays} devices={devices} selectedDeviceId={selectedDeviceId} setSelectedDeviceId={setSelectedDeviceId} selectedDayId={selectedDayId} setSelectedDayId={setSelectedDayId} dayStatus={dayStatus} setDayStatus={setDayStatus} openDayForDevice={openDayForDevice} closeDay={closeDay} pollDay={pollDay} loading={loading} />}
          {activeTab === "receipts" && <Receipts receipts={filteredReceipts} search={receiptSearch} setSearch={setReceiptSearch} status={receiptStatus} setStatus={setReceiptStatus} retryReceipt={retryReceipt} loading={loading} />}
        </div>
      </div>
    </FullScreenModal>
  );
}

function TopBar({ activeTab, setActiveTab, loading, loadAll, settings, openDay, selectedDevice }) {
  return <div className="shrink-0 border border-[#AFB9B9] bg-white">
    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1">
      <div className="flex flex-wrap gap-1">{["overview", "settings", "devices", "days", "receipts"].map((t) => <Tab key={t} active={activeTab === t} onClick={() => setActiveTab(t)}>{t === "days" ? "Fiscal Days" : t[0].toUpperCase() + t.slice(1)}</Tab>)}</div>
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={settings?.enabled ? "success" : "danger"}>{settings?.enabled ? "ZIMRA Enabled" : "ZIMRA Disabled"}</Badge>
        <Badge tone={openDay ? "success" : "warning"}>{openDay ? `Day Open: ${openDay.fiscal_day_no}` : "No Open Day"}</Badge>
        <Badge tone={selectedDevice ? "success" : "danger"}>{selectedDevice ? `Device ${selectedDevice.device_id}` : "No Device"}</Badge>
        <button onClick={loadAll} disabled={loading} className="flex h-8 items-center gap-1 border border-[#B9C3C3] bg-white px-3 text-[12px] font-semibold disabled:opacity-60">{loading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}Refresh</button>
      </div>
    </div>
    <div className="grid grid-cols-1 gap-1 bg-[#F8FAFC] px-2 py-1 text-[11px] text-[#627474] xl:grid-cols-3"><div>Environment: <b>{settings?.environment || "testing"}</b></div><div className="truncate">Base URL: <b>{settings?.base_url || "config/.env fallback"}</b></div><div>Files: <b>base_path()/zimra/devices</b></div></div>
  </div>;
}

function Overview({ settings, devices, days, receipts, openDay, selectedDevice, setActiveTab, loading, syncDevice, checkStatus, openDayForDevice, closeDay, pollDay }) {
  const submitted = receipts.filter((r) => r.status === "submitted").length;
  const failed = receipts.filter((r) => r.status === "failed").length;
  const pending = receipts.filter((r) => r.status === "pending").length;
  return <div className="grid min-h-0 gap-2 xl:grid-cols-[minmax(0,1fr)_420px]">
    <div className="grid min-h-0 grid-rows-[auto_minmax(0,1fr)] gap-2">
      <div className="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-4"><Metric title="ZIMRA" value={settings.enabled ? "Enabled" : "Disabled"} tone={settings.enabled ? "success" : "danger"} icon={ShieldCheck} footer={settings.environment} /><Metric title="Devices" value={devices.length} tone={devices.length ? "success" : "warning"} icon={MonitorCheck} footer={selectedDevice ? `Selected ${selectedDevice.device_id}` : "No device"} /><Metric title="Open Day" value={openDay ? openDay.fiscal_day_no : "None"} tone={openDay ? "success" : "warning"} icon={Clock3} footer={openDay?.status || "Required for sales"} /><Metric title="Receipts" value={receipts.length} tone={failed ? "danger" : "success"} icon={Printer} footer={`${submitted} submitted / ${failed} failed`} /></div>
      <Panel title="Fiscalisation Flow"><div className="grid gap-2 p-1 xl:grid-cols-2"><Step n="01" title="Enable Company ZIMRA" ok={settings.enabled} text="Turn fiscalisation on and configure environment." action="Settings" onClick={() => setActiveTab("settings")} /><Step n="02" title="Save Fiscal Device" ok={devices.length} text="Upload issued certificate and private key under base_path()." action="Devices" onClick={() => setActiveTab("devices")} /><Step n="03" title="Sync Device Config" ok={selectedDevice?.last_config_response} text="Fetch taxpayer info, QR URL and operating mode." action="Sync" onClick={() => syncDevice(selectedDevice?.id)} disabled={!selectedDevice || loading} /><Step n="04" title="Open Fiscal Day" ok={openDay} text="Open day before fiscal receipt submission." action="Open" onClick={() => openDayForDevice(selectedDevice?.id)} disabled={!selectedDevice || loading} /><Step n="05" title="Submit Sales Receipts" ok={submitted} text="Sales save locally first, then submit to FDMS." action="Receipts" onClick={() => setActiveTab("receipts")} /><Step n="06" title="Close And Poll" ok={!openDay} text="Close day then poll until FDMS confirms closed." action="Fiscal Days" onClick={() => setActiveTab("days")} /></div></Panel>
    </div>
    <div className="grid min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2"><Panel title="Quick Actions"><div className="grid gap-2"><Action icon={ServerCog} title="Check Device Status" text="Calls FDMS GetStatus." onClick={() => checkStatus(selectedDevice?.id)} disabled={!selectedDevice || loading} /><Action icon={RefreshCw} title="Sync Device Config" text="Updates taxpayer, QR URL and certificate expiry." onClick={() => syncDevice(selectedDevice?.id)} disabled={!selectedDevice || loading} /><Action icon={PlayCircle} title="Open Fiscal Day" text="Starts the current fiscal trading day." onClick={() => openDayForDevice(selectedDevice?.id)} disabled={!selectedDevice || loading} /><Action icon={XCircle} title="Close Open Day" text="Close after receipts are submitted." onClick={() => closeDay(openDay?.id)} disabled={!openDay || loading} danger /><Action icon={RotateCcw} title="Poll Fiscal Day" text="Check close status." onClick={() => pollDay(openDay?.id)} disabled={!openDay || loading} /></div></Panel><Panel title="Receipt Summary"><Info label="Submitted" value={submitted} /><Info label="Pending" value={pending} /><Info label="Failed" value={failed} danger={failed > 0} /></Panel><Panel title="Latest Fiscal Days"><div className="overflow-auto">{days.slice(0, 8).map((d) => <div key={d.id} className="border-b px-2 py-2 text-[12px]"><div className="flex justify-between"><b>Day {d.fiscal_day_no}</b><Badge tone={tone(d.status)}>{d.status}</Badge></div><div className="text-[11px] text-[#627474]">Device {d.device_id} / {fmt(d.opened_at)}</div></div>)} {!days.length && <Empty title="No fiscal days" text="Open a fiscal day after setting up a device." />}</div></Panel></div>
  </div>;
}

function Settings({ settings, setSettings, saveSettings, loading }) {
  return <div className="grid min-h-0 gap-2 xl:grid-cols-[minmax(0,1fr)_420px]"><div className="overflow-auto border border-[#AFB9B9] bg-white"><Header icon={ShieldCheck} title="Company ZIMRA Settings" sub="Control whether fiscalisation is active for this company." /><div className="grid gap-3 p-3 xl:grid-cols-2"><Card title="Activation"><Check label="Enable ZIMRA Fiscalisation" text="Disabled means sale is created normally and fiscal_status is skipped." checked={settings.enabled} onChange={(v) => setSettings((p) => ({ ...p, enabled: v }))} /><Select label="Environment" value={settings.environment} onChange={(v) => setSettings((p) => ({ ...p, environment: v }))} options={[["testing", "Testing"], ["production", "Production"]]} /><Input label="Base URL" value={settings.base_url || ""} onChange={(v) => setSettings((p) => ({ ...p, base_url: v }))} placeholder="Leave empty to use config/.env fallback" /></Card><Card title="Sale Behaviour"><Check label="Auto Open Fiscal Day" text="System tries to open day automatically during sale fiscalisation." checked={settings.auto_open_day} onChange={(v) => setSettings((p) => ({ ...p, auto_open_day: v }))} /><Check label="Block Sales Without Open Day" text="Prevents fiscalised sales without an open day." checked={settings.block_sales_without_open_day} onChange={(v) => setSettings((p) => ({ ...p, block_sales_without_open_day: v }))} /><Check label="Block Sale If Fiscalisation Fails" text="Returns 409 when FDMS submission fails after local save." checked={settings.block_sale_if_fiscalisation_fails} onChange={(v) => setSettings((p) => ({ ...p, block_sale_if_fiscalisation_fails: v }))} /></Card></div><div className="border-t bg-[#F8FAFC] px-3 py-2"><button onClick={saveSettings} disabled={loading} className="h-9 border border-[#027A48] bg-[#027A48] px-5 text-[12px] font-semibold text-white disabled:opacity-60">{loading ? "Saving..." : "Save Company Settings"}</button></div></div><Panel title="Architecture Rules"><Rule ok={settings.enabled} text="If disabled: fiscal_status is skipped." /><Rule ok text="Sale is saved locally before FDMS submission." /><Rule ok text="Certificate and key are saved under Laravel base_path()." /><Rule ok text="FDMS URLs are configurable." /><Rule ok={settings.block_sales_without_open_day} text="Open fiscal day is required before receipt submission." /></Panel></div>;
}

function Devices({ devices, selectedDeviceId, setSelectedDeviceId, form, setForm, submitDevice, loading, settings, selectedDevice, syncDevice, checkStatus, statusPanel, formOpen, setFormOpen }) {
  useEffect(() => {
    setForm((p) => ({ ...p, environment: p.environment || settings.environment || "testing", base_url: p.base_url || settings.base_url || "" }));
  }, [settings.environment, settings.base_url, setForm]);

  const openRegistration = () => {
    setForm({ ...emptyDeviceForm, environment: settings.environment || "testing", base_url: settings.base_url || "" });
    setFormOpen(true);
  };

  return (
    <div className="grid min-h-0 gap-2 xl:grid-cols-[minmax(0,1fr)_390px]">
      <EnterpriseFormModal open={formOpen} title="Register Fiscal Device" subtitle="Upload the issued certificate and private key" onClose={() => setFormOpen(false)} busy={loading} width="760px">
        <div className="grid gap-3 md:grid-cols-2">
          <Input label="Device ID" value={form.device_id} onChange={(v) => setForm((p) => ({ ...p, device_id: v }))} placeholder="28751" />
          <Input label="Device Serial No" value={form.device_serial_no} onChange={(v) => setForm((p) => ({ ...p, device_serial_no: v }))} />
          <Input label="Model Name" value={form.model_name} onChange={(v) => setForm((p) => ({ ...p, model_name: v }))} />
          <Input label="Model Version" value={form.model_version} onChange={(v) => setForm((p) => ({ ...p, model_version: v }))} />
          <Select label="Environment" value={form.environment} onChange={(v) => setForm((p) => ({ ...p, environment: v }))} options={[["testing", "Testing"], ["production", "Production"]]} />
          <Input label="Device Base URL" value={form.base_url} onChange={(v) => setForm((p) => ({ ...p, base_url: v }))} placeholder="Optional" />
          <FileInput label="Certificate File" icon={FileKey2} file={form.certificate_file} accept=".pem,.crt,.cer,.txt" onChange={(file) => setForm((p) => ({ ...p, certificate_file: file }))} />
          <FileInput label="Private Key File" icon={KeyRound} file={form.private_key_file} accept=".pem,.key,.txt" onChange={(file) => setForm((p) => ({ ...p, private_key_file: file }))} />
          <div className="md:col-span-2"><Input label="Certificate Password" type="password" value={form.certificate_password} onChange={(v) => setForm((p) => ({ ...p, certificate_password: v }))} placeholder="Optional" /></div>
        </div>
        <div className="mt-4 flex justify-end gap-2 border-t border-[#D8DFDF] pt-3">
          <button type="button" onClick={() => setFormOpen(false)} className="h-9 border border-[#B9C3C3] bg-white px-5 text-[12px] font-semibold">Cancel</button>
          <button type="button" onClick={submitDevice} disabled={loading} className="flex h-9 items-center gap-2 border border-[#027A48] bg-[#027A48] px-5 text-[12px] font-semibold text-white disabled:opacity-60"><Upload className="h-4 w-4" />Save Fiscal Device</button>
        </div>
      </EnterpriseFormModal>

      <div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white">
        <Header icon={MonitorCheck} title="Fiscal Device Register" sub="Certificate/key paths are relative to base_path()." right={<div className="flex gap-1"><button onClick={openRegistration} className="h-8 border border-[#027A48] bg-[#027A48] px-3 text-[11px] font-semibold text-white">Register Device</button><button onClick={() => syncDevice(selectedDevice?.id)} disabled={!selectedDevice || loading} className="h-8 border bg-white px-3 text-[11px] font-semibold disabled:opacity-60">Sync Config</button><button onClick={() => checkStatus(selectedDevice?.id)} disabled={!selectedDevice || loading} className="h-8 border border-[#004343] bg-[#004344] px-3 text-[11px] font-semibold text-white disabled:opacity-60">Check Status</button></div>} />
        <div className="overflow-auto">
          <table className="w-full min-w-[940px] text-[12px]">
            <thead className="sticky top-0 bg-[#F8FAFC]"><tr className="border-b text-left text-[10px] uppercase text-[#627474]"><th className="px-3 py-2">Device</th><th>Model</th><th>Environment</th><th>Certificate Path</th><th>Taxpayer</th><th>Status</th></tr></thead>
            <tbody>{devices.map((d) => <tr key={d.id} onClick={() => setSelectedDeviceId(d.id)} className={`cursor-pointer border-b hover:bg-[#F8FAFC] ${String(d.id) === String(selectedDeviceId) ? "bg-[#E5F2F2]" : "bg-white"}`}><td className="px-3 py-2"><b>{d.device_id}</b><div className="text-[10px] text-[#627474]">{d.device_serial_no || "-"}</div></td><td>{d.model_name}<div className="text-[10px] text-[#627474]">{d.model_version}</div></td><td>{d.environment}</td><td><div className="max-w-[300px] truncate text-[10px]" title={d.certificate_path}>{d.certificate_path}</div><div className="max-w-[300px] truncate text-[10px] text-[#627474]" title={d.private_key_path}>{d.private_key_path}</div></td><td>{d.taxpayer_name || "-"}<div className="text-[10px] text-[#627474]">TIN: {d.taxpayer_tin || "-"}</div></td><td><Badge tone={d.is_active ? "success" : "danger"}>{d.is_active ? "Active" : "Inactive"}</Badge></td></tr>)}{!devices.length && <tr><td colSpan={6}><Empty title="No fiscal devices" text="Upload certificate and key to begin." /></td></tr>}</tbody>
          </table>
        </div>
      </div>
      <Panel title="Device Details">{selectedDevice ? <div className="grid gap-1 text-[12px]"><Info label="Device ID" value={selectedDevice.device_id} /><Info label="Taxpayer" value={selectedDevice.taxpayer_name || "-"} /><Info label="TIN" value={selectedDevice.taxpayer_tin || "-"} /><Info label="VAT" value={selectedDevice.vat_number || "-"} /><Info label="QR URL" value={selectedDevice.qr_url || "-"} /><Info label="Certificate Expiry" value={selectedDevice.certificate_valid_till || "-"} /><pre className="mt-3 max-h-[300px] overflow-auto border bg-[#F8FAFC] p-2 text-[10px]">{JSON.stringify(statusPanel || selectedDevice.last_status_response || selectedDevice.last_config_response || {}, null, 2)}</pre></div> : <Empty title="No device selected" text="Select a device from the register." />}</Panel>
    </div>
  );
}

function Days({ days, devices, selectedDeviceId, setSelectedDeviceId, selectedDayId, setSelectedDayId, dayStatus, setDayStatus, openDayForDevice, closeDay, pollDay, loading }) {
  return <div className="grid min-h-0 gap-2 xl:grid-cols-[minmax(0,1fr)_390px]"><div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white"><Header icon={Clock3} title="Fiscal Day Register" sub="Open, close and poll fiscal days." right={<div className="flex gap-1"><select value={selectedDeviceId || ""} onChange={(e) => setSelectedDeviceId(e.target.value)} className="h-8 border bg-white px-2 text-[11px]"><option value="">Select device</option>{devices.map((d) => <option key={d.id} value={d.id}>{d.device_id} - {d.model_name}</option>)}</select><select value={dayStatus} onChange={(e) => setDayStatus(e.target.value)} className="h-8 border bg-white px-2 text-[11px]"><option value="">All</option><option value="opened">Opened</option><option value="close_initiated">Close Initiated</option><option value="close_failed">Close Failed</option><option value="closed">Closed</option></select><button onClick={() => openDayForDevice(selectedDeviceId)} disabled={!selectedDeviceId || loading} className="h-8 border border-[#027A48] bg-[#027A48] px-3 text-[11px] font-semibold text-white disabled:opacity-60">Open Day</button></div>} /><div className="overflow-auto"><table className="w-full min-w-[900px] text-[12px]"><thead className="sticky top-0 bg-[#F8FAFC]"><tr className="border-b text-left text-[10px] uppercase text-[#627474]"><th className="px-3 py-2">Day</th><th>Device</th><th>Opened</th><th>Closed</th><th>Receipts</th><th>Status</th><th className="text-right">Actions</th></tr></thead><tbody>{days.map((d) => <tr key={d.id} onClick={() => setSelectedDayId(d.id)} className={`cursor-pointer border-b hover:bg-[#F8FAFC] ${String(d.id) === String(selectedDayId) ? "bg-[#E5F2F2]" : "bg-white"}`}><td className="px-3 py-2"><b>Day {d.fiscal_day_no}</b><div className="text-[10px] text-[#627474]">{d.id}</div></td><td>{d.device_id}</td><td>{fmt(d.opened_at)}</td><td>{fmt(d.closed_at)}</td><td>{d.receipt_counter || 0}</td><td><Badge tone={tone(d.status)}>{d.status}</Badge></td><td className="text-right"><button onClick={(e) => { e.stopPropagation(); pollDay(d.id); }} disabled={loading} className="mr-1 h-7 border bg-white px-2 text-[11px] font-semibold">Poll</button><button onClick={(e) => { e.stopPropagation(); closeDay(d.id); }} disabled={loading || !["opened", "close_failed"].includes(d.status)} className="h-7 border border-[#FECDCA] bg-white px-2 text-[11px] font-semibold text-[#B42318] disabled:opacity-50">Close</button></td></tr>)} {!days.length && <tr><td colSpan={7}><Empty title="No fiscal days" text="Open fiscal day for your active device." /></td></tr>}</tbody></table></div></div><Panel title="Selected Day">{days.find((d) => String(d.id) === String(selectedDayId)) ? <pre className="max-h-full overflow-auto border bg-[#F8FAFC] p-2 text-[10px]">{JSON.stringify(days.find((d) => String(d.id) === String(selectedDayId)), null, 2)}</pre> : <Empty title="No fiscal day selected" text="Select a day from the table." />}</Panel></div>;
}

function Receipts({ receipts, search, setSearch, status, setStatus, retryReceipt, loading }) {
  return <div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white"><Header icon={Printer} title="ZIMRA Receipt Register" sub="FDMS status, QR data and retry control." right={<div className="flex gap-1"><div className="relative"><Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search invoice, FDMS ID..." className="h-8 w-[260px] border bg-white pl-7 pr-2 text-[11px]" /></div><select value={status} onChange={(e) => setStatus(e.target.value)} className="h-8 border bg-white px-2 text-[11px]"><option value="">All statuses</option><option value="pending">Pending</option><option value="submitted">Submitted</option><option value="failed">Failed</option></select></div>} /><div className="overflow-auto"><table className="w-full min-w-[1280px] text-[12px]"><thead className="sticky top-0 bg-[#F8FAFC]"><tr className="border-b text-left text-[10px] uppercase text-[#627474]"><th className="px-3 py-2">Invoice</th><th>FDMS Receipt</th><th>Device / Day</th><th>Counter</th><th className="text-right">Total</th><th>QR</th><th>Submitted</th><th>Status</th><th className="text-right">Action</th></tr></thead><tbody>{receipts.map((r) => <tr key={r.id} className="border-b hover:bg-[#F8FAFC]"><td className="px-3 py-2"><b>{r.invoice_no}</b><div className="text-[10px] text-[#627474]">{r.sales_transaction_id}</div></td><td>{r.fdms_receipt_id || "-"}<div className="text-[10px] text-[#627474]">{r.operation_id || "-"}</div></td><td>Device {r.device_id}<div className="text-[10px] text-[#627474]">Day {r.fiscal_day_no}</div></td><td>R: {r.receipt_counter}<div className="text-[10px] text-[#627474]">G: {r.receipt_global_no}</div></td><td className="text-right font-semibold">{money(r?.payload?.receipt?.receiptTotal || 0, r.receipt_currency)}</td><td><div className="max-w-[260px] truncate text-[10px]" title={r.qr_string || r.qr_url}>{r.qr_string || r.qr_url || "-"}</div></td><td>{fmt(r.submitted_at)}</td><td><Badge tone={tone(r.status)}>{r.status}</Badge>{r.error && <div className="max-w-[220px] truncate text-[10px] text-[#B42318]" title={r.error}>{r.error}</div>}</td><td className="text-right"><button onClick={() => retryReceipt(r)} disabled={loading || r.status === "submitted"} className="h-7 border bg-white px-3 text-[11px] font-semibold disabled:opacity-50">Retry</button></td></tr>)} {!receipts.length && <tr><td colSpan={9}><Empty title="No ZIMRA receipts" text="Receipts will appear after sales are fiscalised." /></td></tr>}</tbody></table></div></div>;
}

function Tab({ active, children, onClick }) { return <button type="button" onClick={onClick} className={`h-8 border px-4 text-[12px] font-semibold ${active ? "border-[#7C8888] bg-white text-[#0C1B1B]" : "border-transparent text-[#465757] hover:border-[#B9C3C3] hover:bg-white"}`}>{children}</button>; }
function Header({ icon: Icon, title, sub, right }) { return <div className="flex items-center justify-between gap-2 border-b border-[#D8DFDF] bg-[#EEF2F2] px-3 py-2"><div className="flex min-w-0 items-center gap-2">{Icon && <div className="flex h-8 w-8 shrink-0 items-center justify-center border border-[#B9C3C3] bg-white"><Icon className="h-4 w-4" /></div>}<div className="min-w-0"><div className="truncate text-[13px] font-semibold">{title}</div><div className="truncate text-[11px] text-[#627474]">{sub}</div></div></div>{right}</div>; }
function Panel({ title, children }) { return <div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white"><div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-3 py-2 text-[13px] font-semibold">{title}</div><div className="h-[calc(100%-38px)] overflow-auto p-3">{children}</div></div>; }
function Card({ title, children }) { return <div className="border border-[#D8DFDF] bg-white"><div className="border-b bg-[#F8FAFC] px-3 py-2 text-[12px] font-semibold">{title}</div><div className="grid gap-3 p-3">{children}</div></div>; }
function Input({ label, value, onChange, type = "text", placeholder = "" }) { return <label><span className="mb-1 block text-[11px] font-semibold uppercase text-[#627474]">{label}</span><input type={type} value={value || ""} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none focus:border-[#004343]" /></label>; }
function Select({ label, value, onChange, options }) { return <label><span className="mb-1 block text-[11px] font-semibold uppercase text-[#627474]">{label}</span><select value={value || ""} onChange={(e) => onChange(e.target.value)} className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none">{options.map(([k, t]) => <option key={k} value={k}>{t}</option>)}</select></label>; }
function Check({ label, text, checked, onChange }) { return <label className="grid cursor-pointer grid-cols-[18px_1fr] gap-2 border border-[#D8DFDF] bg-[#F8FAFC] p-2"><input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} className="mt-0.5" /><span><span className="block text-[12px] font-semibold">{label}</span>{text && <span className="block text-[10px] text-[#627474]">{text}</span>}</span></label>; }
function FileInput({ label, icon: Icon, file, accept, onChange }) { const id = `file-${label.replace(/\s+/g, "-")}`; return <label htmlFor={id} className="block"><span className="mb-1 block text-[11px] font-semibold uppercase text-[#627474]">{label}</span><div className="grid cursor-pointer grid-cols-[34px_1fr] border border-[#B9C3C3] bg-white"><div className="flex h-9 items-center justify-center border-r bg-[#F8FAFC]"><Icon className="h-4 w-4" /></div><div className="flex h-9 items-center px-2 text-[12px]"><span className="truncate">{file?.name || "Choose file..."}</span></div></div><input id={id} type="file" accept={accept} onChange={(e) => onChange(e.target.files?.[0] || null)} className="hidden" /></label>; }
function Badge({ tone: t = "default", children }) { const tones = { success: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]", warning: "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]", danger: "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]", default: "border-[#CFD6D6] bg-[#F9FAFB] text-[#465757]" }; return <span className={`inline-flex border px-2 py-1 text-[10px] font-semibold uppercase ${tones[t] || tones.default}`}>{children}</span>; }
function Metric({ title, value, footer, icon: Icon, tone: t }) { return <div className="border border-[#AFB9B9] bg-white p-3"><div className="flex justify-between"><div><div className="text-[11px] font-semibold uppercase text-[#627474]">{title}</div><div className="mt-2 text-[22px] font-semibold">{value}</div></div><div className="flex h-9 w-9 items-center justify-center border"><Icon className="h-5 w-5" /></div></div><div className="mt-2 truncate text-[11px] text-[#627474]">{footer}</div></div>; }
function Step({ n, title, text, ok, action, onClick, disabled }) { return <div className="border border-[#D8DFDF] bg-white p-3"><div className="flex gap-3"><div className="flex h-8 w-8 shrink-0 items-center justify-center border bg-[#EEF2F2] text-[11px] font-semibold">{n}</div><div className="flex-1"><div className="flex justify-between gap-2"><b className="text-[13px]">{title}</b><Badge tone={ok ? "success" : "warning"}>{ok ? "done" : "attention"}</Badge></div><div className="mt-1 text-[11px] text-[#627474]">{text}</div>{action && <button onClick={onClick} disabled={disabled} className="mt-3 h-7 border bg-white px-3 text-[11px] font-semibold disabled:opacity-50">{action}</button>}</div></div></div>; }
function Action({ icon: Icon, title, text, onClick, disabled, danger }) { return <button onClick={onClick} disabled={disabled} className={`grid grid-cols-[34px_1fr] gap-2 border p-2 text-left disabled:opacity-50 ${danger ? "border-[#FECDCA] bg-[#FEF3F2]" : "border-[#D8DFDF] bg-white hover:bg-[#F8FAFC]"}`}><div className="flex h-8 w-8 items-center justify-center border"><Icon className="h-4 w-4" /></div><div><div className="text-[12px] font-semibold">{title}</div><div className="text-[10px] text-[#627474]">{text}</div></div></button>; }
function Info({ label, value, danger }) { return <div className="grid grid-cols-[150px_minmax(0,1fr)] border-b py-1 text-[12px]"><span className="text-[#627474]">{label}</span><span className={`truncate text-right ${danger ? "font-semibold text-[#B42318]" : ""}`}>{value}</span></div>; }
function Rule({ ok, text }) { return <div className="flex gap-2 border-b py-2 text-[12px]">{ok ? <CheckCircle2 className="h-4 w-4 text-[#067647]" /> : <AlertTriangle className="h-4 w-4 text-[#B54708]" />}<span>{text}</span></div>; }
function Empty({ title, text }) { return <div className="flex flex-col items-center justify-center px-4 py-8 text-center"><Database className="mb-2 h-8 w-8 text-[#96A5A4]" /><div className="text-[13px] font-semibold">{title}</div><div className="mt-1 text-[11px] text-[#627474]">{text}</div></div>; }
function tone(status) { const s = String(status || "").toLowerCase(); if (["submitted", "success", "opened", "active", "closed"].includes(s)) return "success"; if (["pending", "close_initiated", "queued"].includes(s)) return "warning"; if (["failed", "close_failed", "inactive"].includes(s)) return "danger"; return "default"; }

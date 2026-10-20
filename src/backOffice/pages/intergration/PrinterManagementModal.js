// src/backOffice/pages/admin/PrinterManagementModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getTills } from "../../../services/TillService";

import {
  getPrinters,
  getPrinter,
  createPrinter,
  updatePrinter,
  deletePrinter,
} from "../../../services/PrinterService";

import {
  getQzCertificates,
  getQzCertificate,
  createQzCertificate,
  updateQzCertificate,
  deleteQzCertificate,
} from "../../../services/qzCertificateService";

import {
  qzConnect,
  qzGetPrinters,
  qzGetDefaultPrinter,
  qzPrintImageBase64,
} from "../../../services/QzService";

const emptyForm = {
  id: null,
  branch_id: "",
  till_id: "",
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

const emptyCertForm = {
  id: null,
  branch_id: "",
  till_id: "",
  name: "",
  certificate: null,
  private_key: null,
  is_active: true,
  notes: "",
};

const targets = [
  { value: "receipt", label: "Receipt" },
  { value: "barcode", label: "Barcode" },
  { value: "kitchen", label: "Kitchen" },
  { value: "bar", label: "Bar" },
  { value: "report", label: "Report" },
  { value: "label", label: "Label" },
  { value: "invoice", label: "Invoice" },
  { value: "general", label: "General" },
];

const paperSizes = ["80mm", "58mm", "A4", "A5", "Custom"];

const PrinterManagementModal = ({ open, onClose, currentUser = null }) => {
  const [branches, setBranches] = useState([]);
  const [tills, setTills] = useState([]);

  const [savedPrinters, setSavedPrinters] = useState([]);
  const [savedCertificates, setSavedCertificates] = useState([]);

  const [detectedPrinters, setDetectedPrinters] = useState([]);
  const [defaultPrinter, setDefaultPrinter] = useState("");

  const [formData, setFormData] = useState(emptyForm);
  const [certForm, setCertForm] = useState(emptyCertForm);

  const [printerModalOpen, setPrinterModalOpen] = useState(false);
  const [certificateModalOpen, setCertificateModalOpen] = useState(false);

  const [search, setSearch] = useState("");
  const [branchFilter, setBranchFilter] = useState("");
  const [targetFilter, setTargetFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");

  const [certBranchFilter, setCertBranchFilter] = useState("");
  const [certStatusFilter, setCertStatusFilter] = useState("");

  const [loading, setLoading] = useState(false);
  const [loadingQz, setLoadingQz] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savingCert, setSavingCert] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [deletingCertId, setDeletingCertId] = useState(null);
  const [deleteConfirmation, setDeleteConfirmation] = useState(null);
  const [testingPrinter, setTestingPrinter] = useState(false);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;
  const isEditingCert = !!certForm.id;

  const showMessage = ({ type = "success", title = "", message = "" }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.data && typeof responseData.data === "object") {
      const values = Object.values(responseData.data).flat();
      if (values.length > 0) return values.join("\n");
    }

    if (responseData?.error) return responseData.error;
    if (responseData?.message) return responseData.message;
    if (source?.displayMessage) return source.displayMessage;
    if (source?.message && !source?.isAxiosError) return source.message;

    return fallback;
  };

  const normalizeRows = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;

    return [];
  };

  const normalizeDetail = (response) => {
    return response?.data || response?.printer || response?.certificate || response || null;
  };

  const getBranchName = (branch) => {
    return branch?.branch_name || branch?.name || "Unnamed Branch";
  };

  const getTillName = (till) => {
    if (!till) return "-";
    return `${till.till_code || ""} ${
      till.till_name ? `- ${till.till_name}` : ""
    }`;
  };

  const getPrinterBranchName = (printer) => {
    return (
      printer?.branch?.branch_name ||
      printer?.branch?.name ||
      branches.find((branch) => branch.id === printer.branch_id)?.branch_name ||
      branches.find((branch) => branch.id === printer.branch_id)?.name ||
      "Company Level"
    );
  };

  const getPrinterTillName = (printer) => {
    return (
      printer?.till?.till_code ||
      printer?.till?.till_name ||
      tills.find((till) => till.id === printer.till_id)?.till_code ||
      tills.find((till) => till.id === printer.till_id)?.till_name ||
      "All Tills"
    );
  };

  const getCertificateBranchName = (certificate) => {
    return (
      certificate?.branch?.branch_name ||
      certificate?.branch?.name ||
      branches.find((branch) => branch.id === certificate.branch_id)?.branch_name ||
      branches.find((branch) => branch.id === certificate.branch_id)?.name ||
      "Company Level"
    );
  };

  const getCertificateTillName = (certificate) => {
    return (
      certificate?.till?.till_code ||
      certificate?.till?.till_name ||
      tills.find((till) => till.id === certificate.till_id)?.till_code ||
      tills.find((till) => till.id === certificate.till_id)?.till_name ||
      "All Tills"
    );
  };

  const getDefaultBranchId = () => {
    return currentUser?.branch_id || currentUser?.branch?.id || branches?.[0]?.id || "";
  };

  const availableTills = useMemo(() => {
    if (!formData.branch_id) return tills;

    return tills.filter((till) => till.branch_id === formData.branch_id);
  }, [tills, formData.branch_id]);

  const availableCertTills = useMemo(() => {
    if (!certForm.branch_id) return tills;

    return tills.filter((till) => till.branch_id === certForm.branch_id);
  }, [tills, certForm.branch_id]);

  const activeCount = useMemo(() => {
    return savedPrinters.filter((item) => item.is_active).length;
  }, [savedPrinters]);

  const activeCertCount = useMemo(() => {
    return savedCertificates.filter((item) => item.is_active).length;
  }, [savedCertificates]);

  const loadBranches = async () => {
    const response = await getAuthorisedCompany();

    const rows =
      response?.company?.branches ||
      response?.data?.branches ||
      response?.data?.company?.branches ||
      [];

    setBranches(rows);
  };

  const loadTills = async () => {
    const response = await getTills({ per_page: 200, is_active: true });
    setTills(normalizeRows(response));
  };

  const loadSavedPrinters = async () => {
    const params = {
      per_page: 100,
    };

    if (search.trim()) params.q = search.trim();
    if (branchFilter) params.branch_id = branchFilter;
    if (targetFilter) params.target_printing = targetFilter;
    if (statusFilter !== "") params.is_active = statusFilter;

    const response = await getPrinters(params);
    setSavedPrinters(normalizeRows(response));
  };

  const loadSavedCertificates = async () => {
    const params = {
      per_page: 100,
    };

    if (certBranchFilter) params.branch_id = certBranchFilter;
    if (certStatusFilter !== "") params.is_active = certStatusFilter;

    const response = await getQzCertificates(params);
    setSavedCertificates(normalizeRows(response));
  };

  const loadData = async () => {
    try {
      setLoading(true);

      await Promise.all([
        loadBranches(),
        loadTills(),
        loadSavedPrinters(),
        loadSavedCertificates(),
      ]);
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

  const resetForm = () => {
    setFormData({
      ...emptyForm,
      branch_id: getDefaultBranchId(),
      printer_name: defaultPrinter || detectedPrinters?.[0] || "",
    });
  };

  const resetCertForm = () => {
    setCertForm({
      ...emptyCertForm,
      branch_id: formData.branch_id || getDefaultBranchId(),
      till_id: formData.till_id || "",
    });
  };

  const applyQzContextFromPrinterForm = () => {
    if (formData.branch_id) {
      localStorage.setItem("branch_id", formData.branch_id);
    } else {
      localStorage.removeItem("branch_id");
    }

    if (formData.till_id) {
      localStorage.setItem("till_id", formData.till_id);
    } else {
      localStorage.removeItem("till_id");
    }
  };

  const detectLocalPrinters = async () => {
    try {
      setLoadingQz(true);

      applyQzContextFromPrinterForm();

      await qzConnect();

      const [printers, def] = await Promise.all([
        qzGetPrinters(),
        qzGetDefaultPrinter(),
      ]);

      setDetectedPrinters(printers);
      setDefaultPrinter(def || "");

      if (!formData.printer_name && printers.length > 0) {
        setFormData((prev) => ({
          ...prev,
          printer_name: def || printers[0],
        }));
      }

      showMessage({
        type: "success",
        title: "Printers Detected",
        message: `${printers.length} printer(s) found on this computer.`,
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Printer Detection Failed",
        message:
          getApiMessage(error) ||
          "Could not connect to QZ Tray. Make sure QZ Tray is installed and running.",
      });
    } finally {
      setLoadingQz(false);
    }
  };

  useEffect(() => {
    if (!open) return;

    const defaultBranchId = getDefaultBranchId();

    setFormData({
      ...emptyForm,
      branch_id: defaultBranchId,
    });

    setCertForm({
      ...emptyCertForm,
      branch_id: defaultBranchId,
    });

    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;

    if (!formData.branch_id && branches.length > 0) {
      setFormData((prev) => ({
        ...prev,
        branch_id: getDefaultBranchId(),
      }));
    }

    if (!certForm.branch_id && branches.length > 0) {
      setCertForm((prev) => ({
        ...prev,
        branch_id: getDefaultBranchId(),
      }));
    }

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branches]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadSavedPrinters();
    }, 300);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, branchFilter, targetFilter, statusFilter]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadSavedCertificates();
    }, 300);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [certBranchFilter, certStatusFilter]);

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target;

    setFormData((prev) => {
      const next = {
        ...prev,
        [name]: type === "checkbox" ? checked : value,
      };

      if (name === "branch_id") {
        next.till_id = "";
      }

      if (name === "printer_type" && value !== "network") {
        next.ip_address = "";
        next.port = "";
      }

      return next;
    });
  };

  const handleCertChange = (e) => {
    const { name, value, type, checked } = e.target;

    setCertForm((prev) => {
      const next = {
        ...prev,
        [name]: type === "checkbox" ? checked : value,
      };

      if (name === "branch_id") {
        next.till_id = "";
      }

      return next;
    });
  };

  const handleCertFileChange = (e) => {
    const { name, files } = e.target;

    setCertForm((prev) => ({
      ...prev,
      [name]: files?.[0] || null,
    }));
  };

  const handlePickDetectedPrinter = (name) => {
    setFormData((prev) => ({
      ...prev,
      printer_name: name,
      printer_type: "qz",
      connection_type: "local",
    }));
  };

  const usePrinterScopeForCertificate = () => {
    setCertForm((prev) => ({
      ...prev,
      branch_id: formData.branch_id || "",
      till_id: formData.till_id || "",
    }));
  };

  const validateForm = () => {
    if (!formData.printer_name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Printer name is required.",
      });
      return false;
    }

    if (!formData.target_printing) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Target printing is required.",
      });
      return false;
    }

    if (formData.printer_type === "network" && !formData.ip_address.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "IP address is required for network printer.",
      });
      return false;
    }

    return true;
  };

  const validateCertForm = () => {
    if (!isEditingCert && !certForm.certificate) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Digital certificate file is required.",
      });
      return false;
    }

    if (!isEditingCert && !certForm.private_key) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Private key file is required.",
      });
      return false;
    }

    return true;
  };

  const buildPayload = () => ({
    branch_id: formData.branch_id || null,
    till_id: formData.till_id || null,
    printer_name: formData.printer_name.trim(),
    target_printing: formData.target_printing,
    printer_type: formData.printer_type || "qz",
    paper_size: formData.paper_size || null,
    connection_type: formData.connection_type || "local",
    ip_address: formData.ip_address || null,
    port: formData.port ? Number(formData.port) : null,
    is_default: !!formData.is_default,
    is_active: !!formData.is_active,
    notes: formData.notes || null,
  });

  const buildCertPayload = () => ({
    branch_id: certForm.branch_id || null,
    till_id: certForm.till_id || null,
    name: certForm.name || null,
    certificate: certForm.certificate || null,
    private_key: certForm.private_key || null,
    is_active: !!certForm.is_active,
    notes: certForm.notes || null,
  });

  const handleSubmit = async (e) => {
    if (e?.preventDefault) e.preventDefault();

    if (!validateForm()) return;

    try {
      setSaving(true);

      const payload = buildPayload();

      const response = isEditing
        ? await updatePrinter(formData.id, payload)
        : await createPrinter(payload);

      showMessage({
        type: "success",
        title: isEditing ? "Printer Updated" : "Printer Saved",
        message: response?.message || "Printer saved successfully.",
      });

      resetForm();
      await loadSavedPrinters();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Save Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
    }
  };

  const handleCertificateSubmit = async (e) => {
    if (e?.preventDefault) e.preventDefault();

    if (!validateCertForm()) return;

    try {
      setSavingCert(true);

      const payload = buildCertPayload();

      const response = isEditingCert
        ? await updateQzCertificate(certForm.id, payload)
        : await createQzCertificate(payload);

      showMessage({
        type: "success",
        title: isEditingCert ? "Certificate Updated" : "Certificate Uploaded",
        message: response?.message || "QZ certificate saved successfully.",
      });

      resetCertForm();
      await loadSavedCertificates();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditingCert ? "Certificate Update Failed" : "Certificate Upload Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingCert(false);
    }
  };

  const handleEdit = async (printer) => {
    try {
      const response = await getPrinter(printer.id);
      const detail = normalizeDetail(response);

      setFormData({
        id: detail.id,
        branch_id: detail.branch_id || "",
        till_id: detail.till_id || "",
        printer_name: detail.printer_name || "",
        target_printing: detail.target_printing || "receipt",
        printer_type: detail.printer_type || "qz",
        paper_size: detail.paper_size || "80mm",
        connection_type: detail.connection_type || "local",
        ip_address: detail.ip_address || "",
        port: detail.port || "",
        is_default: detail.is_default ?? false,
        is_active: detail.is_active ?? true,
        notes: detail.notes || "",
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Printer Detail Failed",
        message: getApiMessage(error),
      });
    }
  };

  const handleCertificateEdit = async (certificate) => {
    try {
      const response = await getQzCertificate(certificate.id);
      const detail = normalizeDetail(response);

      setCertForm({
        id: detail.id,
        branch_id: detail.branch_id || "",
        till_id: detail.till_id || "",
        name: detail.name || "",
        certificate: null,
        private_key: null,
        is_active: detail.is_active ?? true,
        notes: detail.notes || "",
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Certificate Detail Failed",
        message: getApiMessage(error),
      });
    }
  };

  const handleDelete = async (printer) => {
    try {
      setDeletingId(printer.id);

      const response = await deletePrinter(printer.id);

      showMessage({
        type: "success",
        title: "Printer Deleted",
        message: response?.message || "Printer deleted successfully.",
      });

      if (formData.id === printer.id) resetForm();

      await loadSavedPrinters();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
      setDeleteConfirmation(null);
    }
  };

  const handleCertificateDelete = async (certificate) => {
    try {
      setDeletingCertId(certificate.id);

      const response = await deleteQzCertificate(certificate.id);

      showMessage({
        type: "success",
        title: "Certificate Deleted",
        message: response?.message || "QZ certificate deleted successfully.",
      });

      if (certForm.id === certificate.id) resetCertForm();

      await loadSavedCertificates();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Certificate Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingCertId(null);
      setDeleteConfirmation(null);
    }
  };

  const makePrinterTestPngBase64 = () => {
    const canvas = document.createElement("canvas");
    canvas.width = 576;
    canvas.height = 300;

    const ctx = canvas.getContext("2d");

    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.fillStyle = "#000000";
    ctx.font = "bold 28px Arial";
    ctx.fillText("SERVENEST POS", 30, 50);

    ctx.font = "16px Arial";
    ctx.fillText("Printer Test Page", 30, 85);
    ctx.fillText(`Printer: ${formData.printer_name || "-"}`, 30, 120);
    ctx.fillText(`Target: ${formData.target_printing || "-"}`, 30, 150);
    ctx.fillText(`Paper: ${formData.paper_size || "-"}`, 30, 180);
    ctx.fillText(`Branch: ${formData.branch_id || "Company Level"}`, 30, 210);
    ctx.fillText(`Till: ${formData.till_id || "All Tills"}`, 30, 240);
    ctx.fillText(`Time: ${new Date().toLocaleString()}`, 30, 270);

    return canvas.toDataURL("image/png").replace(/^data:image\/png;base64,/, "");
  };

  const testPrinter = async () => {
    if (!formData.printer_name) {
      showMessage({
        type: "error",
        title: "No Printer",
        message: "Select or detect a printer first.",
      });
      return;
    }

    try {
      setTestingPrinter(true);

      applyQzContextFromPrinterForm();

      const pngBase64 = makePrinterTestPngBase64();

      await qzPrintImageBase64({
        printerName: formData.printer_name,
        imageBase64: pngBase64,
        jobName: "ServeNest Printer Test",
      });

      showMessage({
        type: "success",
        title: "Test Sent",
        message: "Printer test job sent successfully.",
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Test Failed",
        message:
          error?.displayMessage ||
          error?.response?.data?.message ||
          error?.message ||
          "Printer test failed.",
      });
    } finally {
      setTestingPrinter(false);
    }
  };

  const getCertificateForPrinter = (printer) => {
    return savedCertificates.find((certificate) => {
      const certificateBranch = certificate.branch_id || "";
      const certificateTill = certificate.till_id || "";
      const printerBranch = printer.branch_id || "";
      const printerTill = printer.till_id || "";

      return (
        String(certificateBranch) === String(printerBranch) &&
        String(certificateTill) === String(printerTill) &&
        Boolean(certificate.is_active)
      );
    });
  };

  const openPrinterForm = (printer = null) => {
    if (!printer) {
      setFormData({
        ...emptyForm,
        branch_id: getDefaultBranchId(),
        printer_name: defaultPrinter || detectedPrinters?.[0] || "",
      });
      setPrinterModalOpen(true);
      return;
    }

    handleEdit(printer).then(() => {
      setPrinterModalOpen(true);
    });
  };

  const openCertificateForm = (certificate = null, printer = null) => {
    if (certificate) {
      handleCertificateEdit(certificate).then(() => {
        setCertificateModalOpen(true);
      });
      return;
    }

    setCertForm({
      ...emptyCertForm,
      branch_id: printer?.branch_id || formData.branch_id || getDefaultBranchId(),
      till_id: printer?.till_id || formData.till_id || "",
      name: printer?.printer_name ? `${printer.printer_name} QZ` : "",
    });

    setCertificateModalOpen(true);
  };

  const handlePrinterModalSubmit = async (event) => {
    await handleSubmit(event);
    if (!event?.defaultPrevented) {
      setPrinterModalOpen(false);
    }
  };

  const handleCertificateModalSubmit = async (event) => {
    await handleCertificateSubmit(event);
    if (!event?.defaultPrevented) {
      setCertificateModalOpen(false);
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="PRINTER MANAGEMENT"
        subtitle="Printers and QZ certificates"
      >
        <div className="flex h-full min-h-0 flex-col border border-[#CAD5D5] bg-white">
          <div className="flex h-12 shrink-0 items-center justify-between border-b border-[#D8E1E0] bg-[#F8FAFC] px-3">
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => openPrinterForm()}
                className="h-8 border border-[#008A8A] bg-[#00BCBC] px-3 text-[11px] font-semibold uppercase tracking-wide text-[#004141]"
              >
                Add Printer
              </button>

              <button
                type="button"
                onClick={() => openCertificateForm()}
                className="h-8 border border-[#ACB8B8] bg-white px-3 text-[11px] font-semibold uppercase tracking-wide text-[#004141]"
              >
                Add Certificate
              </button>

              <button
                type="button"
                onClick={detectLocalPrinters}
                disabled={loadingQz}
                className="h-8 border border-[#ACB8B8] bg-white px-3 text-[11px] font-semibold uppercase tracking-wide text-[#004141] disabled:opacity-60"
              >
                {loadingQz ? "Detecting" : "Detect"}
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

            <div className="flex items-center gap-2">
              <select
                value={branchFilter}
                onChange={(event) => setBranchFilter(event.target.value)}
                className="h-8 border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
              >
                <option value="">All Branches</option>
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>
                    {getBranchName(branch)}
                  </option>
                ))}
              </select>

              <select
                value={targetFilter}
                onChange={(event) => setTargetFilter(event.target.value)}
                className="h-8 border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
              >
                <option value="">All Targets</option>
                {targets.map((target) => (
                  <option key={target.value} value={target.value}>
                    {target.label}
                  </option>
                ))}
              </select>

              <select
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value)}
                className="h-8 border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
              >
                <option value="">All Status</option>
                <option value="true">Active</option>
                <option value="false">Inactive</option>
              </select>

              <input
                type="text"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search"
                className="h-8 w-[220px] border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
              />
            </div>
          </div>

          <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_280px]">
            <div className="min-h-0 overflow-auto bg-[#EFF3F3] p-3">
              {loading ? (
                <div className="flex h-full items-center justify-center text-[12px] text-[#637877]">
                  Loading printers...
                </div>
              ) : savedPrinters.length === 0 ? (
                <div className="flex h-full items-center justify-center text-[12px] text-[#637877]">
                  No printers saved.
                </div>
              ) : (
                <div className="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-3">
                  {savedPrinters.map((printer) => {
                    const certificate = getCertificateForPrinter(printer);
                    const hasCertificate = Boolean(certificate);

                    return (
                      <PrinterCard
                        key={printer.id}
                        printer={printer}
                        certificate={certificate}
                        hasCertificate={hasCertificate}
                        branchName={getPrinterBranchName(printer)}
                        tillName={getPrinterTillName(printer)}
                        deleting={deletingId === printer.id}
                        onEdit={() => openPrinterForm(printer)}
                        onDelete={() => setDeleteConfirmation({ type: "printer", item: printer })}
                        onCertificate={() => openCertificateForm(certificate, printer)}
                        onSelect={() =>
                          setFormData((prev) => ({
                            ...prev,
                            printer_name: printer.printer_name,
                            branch_id: printer.branch_id || "",
                            till_id: printer.till_id || "",
                          }))
                        }
                        onTest={() => {
                          setFormData((prev) => ({
                            ...prev,
                            printer_name: printer.printer_name,
                            branch_id: printer.branch_id || "",
                            till_id: printer.till_id || "",
                            target_printing: printer.target_printing || prev.target_printing,
                            paper_size: printer.paper_size || prev.paper_size,
                          }));
                          setTimeout(() => testPrinter(), 0);
                        }}
                      />
                    );
                  })}
                </div>
              )}
            </div>

            <aside className="min-h-0 border-l border-[#D8E1E0] bg-white">
              <div className="border-b border-[#D8E1E0] bg-[#F8FAFC] px-3 py-2">
                <div className="text-[11px] font-medium uppercase tracking-wide text-[#004141]">
                  Certificates
                </div>
              </div>

              <div className="border-b border-[#D8E1E0] p-2">
                <select
                  value={certBranchFilter}
                  onChange={(event) => setCertBranchFilter(event.target.value)}
                  className="mb-2 h-8 w-full border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
                >
                  <option value="">All Branches</option>
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {getBranchName(branch)}
                    </option>
                  ))}
                </select>

                <select
                  value={certStatusFilter}
                  onChange={(event) => setCertStatusFilter(event.target.value)}
                  className="h-8 w-full border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none"
                >
                  <option value="">All Status</option>
                  <option value="true">Active</option>
                  <option value="false">Inactive</option>
                </select>
              </div>

              <div className="h-[calc(100%-122px)] overflow-auto p-2">
                {savedCertificates.length === 0 ? (
                  <div className="px-2 py-6 text-center text-[11px] text-[#637877]">
                    No certificates.
                  </div>
                ) : (
                  <div className="space-y-2">
                    {savedCertificates.map((certificate) => (
                      <CertificateMiniCard
                        key={certificate.id}
                        certificate={certificate}
                        branchName={getCertificateBranchName(certificate)}
                        tillName={getCertificateTillName(certificate)}
                        deleting={deletingCertId === certificate.id}
                        onEdit={() => openCertificateForm(certificate)}
                        onDelete={() => setDeleteConfirmation({ type: "certificate", item: certificate })}
                      />
                    ))}
                  </div>
                )}
              </div>
            </aside>
          </div>
        </div>

        <AppInlineStyles />
      </FullScreenModal>

      {printerModalOpen && (
        <PrinterFormModal
          formData={formData}
          isEditing={isEditing}
          branches={branches}
          availableTills={availableTills}
          detectedPrinters={detectedPrinters}
          defaultPrinter={defaultPrinter}
          loadingQz={loadingQz}
          saving={saving}
          testingPrinter={testingPrinter}
          handleChange={handleChange}
          handleSubmit={handlePrinterModalSubmit}
          detectLocalPrinters={detectLocalPrinters}
          handlePickDetectedPrinter={handlePickDetectedPrinter}
          testPrinter={testPrinter}
          onClose={() => {
            setPrinterModalOpen(false);
            resetForm();
          }}
          getBranchName={getBranchName}
          getTillName={getTillName}
        />
      )}

      {certificateModalOpen && (
        <CertificateFormModal
          certForm={certForm}
          isEditingCert={isEditingCert}
          branches={branches}
          availableCertTills={availableCertTills}
          savingCert={savingCert}
          handleCertChange={handleCertChange}
          handleCertFileChange={handleCertFileChange}
          handleCertificateSubmit={handleCertificateModalSubmit}
          onClose={() => {
            setCertificateModalOpen(false);
            resetCertForm();
          }}
          getBranchName={getBranchName}
          getTillName={getTillName}
        />
      )}

      <EnterpriseConfirmModal
        open={Boolean(deleteConfirmation)}
        title={deleteConfirmation?.type === "certificate" ? "Delete QZ Certificate" : "Delete Printer"}
        message={deleteConfirmation?.type === "certificate"
          ? `Delete QZ certificate ${deleteConfirmation?.item?.name || "this certificate"}?`
          : `Delete printer ${deleteConfirmation?.item?.printer_name || "this printer"}?`}
        confirmLabel={deleteConfirmation?.type === "certificate" ? "Delete certificate" : "Delete printer"}
        busy={Boolean(deletingId || deletingCertId)}
        onClose={() => !deletingId && !deletingCertId && setDeleteConfirmation(null)}
        onConfirm={() => {
          if (!deleteConfirmation?.item) return;
          if (deleteConfirmation.type === "certificate") handleCertificateDelete(deleteConfirmation.item);
          else handleDelete(deleteConfirmation.item);
        }}
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

const PrinterIcon = ({ className = "h-8 w-8" }) => (
  <svg viewBox="0 0 24 24" className={className} fill="none" aria-hidden="true">
    <path d="M7 8V4h10v4" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    <path d="M6 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-1" stroke="currentColor" strokeWidth="2" />
    <path d="M7 14h10v6H7v-6Z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    <path d="M17.5 12h.01" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
  </svg>
);

const CertificateIcon = ({ className = "h-6 w-6" }) => (
  <svg viewBox="0 0 24 24" className={className} fill="none" aria-hidden="true">
    <path d="M6 3h9l3 3v15H6V3Z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    <path d="M15 3v4h4" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    <path d="M8.5 12h7M8.5 15h5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
  </svg>
);

const PrinterCard = ({
  printer,
  certificate,
  hasCertificate,
  branchName,
  tillName,
  deleting,
  onEdit,
  onDelete,
  onCertificate,
  onSelect,
  onTest,
}) => (
  <div className="border border-[#CAD5D5] bg-white shadow-[0_4px_14px_rgba(9,27,27,0.06)]">
    <div className="flex items-start gap-3 border-b border-[#EFF2F2] p-3">
      <div className="flex h-12 w-12 shrink-0 items-center justify-center border border-[#BAC8C8] bg-[#EFF3F3] text-[#004141]">
        <PrinterIcon />
      </div>

      <div className="min-w-0 flex-1">
        <div className="truncate text-[13px] font-medium text-[#091B1B]" title={printer.printer_name}>
          {printer.printer_name}
        </div>
        <div className="mt-1 text-[11px] text-[#637877]">
          {printer.target_printing || "-"} · {printer.paper_size || "-"}
        </div>
      </div>

      <button
        type="button"
        onClick={onCertificate}
        title={hasCertificate ? "Certificate exists" : "No certificate"}
        className={`flex h-9 w-9 shrink-0 items-center justify-center border ${
          hasCertificate
            ? "border-[#86EFAC] bg-[#F0FDF4] text-[#166534]"
            : "border-[#FCA5A5] bg-[#FEF2F2] text-[#B42318]"
        }`}
      >
        <CertificateIcon />
      </button>
    </div>

    <div className="grid grid-cols-2 gap-2 p-3 text-[11px]">
      <CardLine label="Branch" value={branchName} />
      <CardLine label="Till" value={tillName} />
      <CardLine label="Type" value={printer.printer_type || "-"} />
      <CardLine label="Status" value={printer.is_active ? "Active" : "Inactive"} />
    </div>

    <div className="flex items-center justify-between border-t border-[#EFF2F2] bg-[#F8FAFC] p-2">
      <div className="text-[10px] text-[#637877]">
        {hasCertificate ? certificate?.name || "Certificate ready" : "Certificate missing"}
      </div>

      <div className="flex gap-1">
        <SmallButton onClick={onSelect}>Select</SmallButton>
        <SmallButton onClick={onTest}>Test</SmallButton>
        <SmallButton onClick={onEdit}>Edit</SmallButton>
        <SmallButton danger onClick={onDelete} disabled={deleting}>
          {deleting ? "..." : "Del"}
        </SmallButton>
      </div>
    </div>
  </div>
);

const CertificateMiniCard = ({ certificate, branchName, tillName, deleting, onEdit, onDelete }) => {
  const active = Boolean(certificate.is_active);

  return (
    <div className="border border-[#D8E1E0] bg-white">
      <div className="flex items-start gap-2 p-2">
        <div
          className={`flex h-8 w-8 shrink-0 items-center justify-center border ${
            active
              ? "border-[#86EFAC] bg-[#F0FDF4] text-[#166534]"
              : "border-[#FCA5A5] bg-[#FEF2F2] text-[#B42318]"
          }`}
        >
          <CertificateIcon className="h-5 w-5" />
        </div>

        <div className="min-w-0 flex-1">
          <div className="truncate text-[12px] font-medium text-[#091B1B]">
            {certificate.name || "QZ Certificate"}
          </div>
          <div className="mt-1 truncate text-[10px] text-[#637877]">
            {branchName} · {tillName}
          </div>
        </div>
      </div>

      <div className="flex justify-end gap-1 border-t border-[#EFF2F2] bg-[#F8FAFC] p-1.5">
        <SmallButton onClick={onEdit}>Edit</SmallButton>
        <SmallButton danger onClick={onDelete} disabled={deleting}>
          {deleting ? "..." : "Del"}
        </SmallButton>
      </div>
    </div>
  );
};

const PrinterFormModal = ({
  formData,
  isEditing,
  branches,
  availableTills,
  detectedPrinters,
  defaultPrinter,
  loadingQz,
  saving,
  testingPrinter,
  handleChange,
  handleSubmit,
  detectLocalPrinters,
  handlePickDetectedPrinter,
  testPrinter,
  onClose,
  getBranchName,
  getTillName,
}) => (
  <EnterpriseFormModal open title={isEditing ? "Edit Printer" : "Add Printer"} onClose={onClose} busy={saving}>
    <form onSubmit={handleSubmit} className="flex min-h-0 flex-col">
      <div className="min-h-0 flex-1 overflow-auto p-4">
        <div className="grid grid-cols-12 gap-3">
          <Field label="Printer Name" span="6">
            <input name="printer_name" value={formData.printer_name} onChange={handleChange} className="pm-input" />
          </Field>
          <Field label="Target" span="3">
            <select name="target_printing" value={formData.target_printing} onChange={handleChange} className="pm-input bg-white">
              {targets.map((target) => <option key={target.value} value={target.value}>{target.label}</option>)}
            </select>
          </Field>
          <Field label="Paper" span="3">
            <select name="paper_size" value={formData.paper_size} onChange={handleChange} className="pm-input bg-white">
              {paperSizes.map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
          </Field>
          <Field label="Branch" span="4">
            <select name="branch_id" value={formData.branch_id} onChange={handleChange} className="pm-input bg-white">
              <option value="">Company Level</option>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
            </select>
          </Field>
          <Field label="Till" span="4">
            <select name="till_id" value={formData.till_id} onChange={handleChange} className="pm-input bg-white">
              <option value="">All Tills</option>
              {availableTills.map((till) => <option key={till.id} value={till.id}>{getTillName(till)}</option>)}
            </select>
          </Field>
          <Field label="Type" span="4">
            <select name="printer_type" value={formData.printer_type} onChange={handleChange} className="pm-input bg-white">
              <option value="qz">QZ Local</option>
              <option value="browser">Browser</option>
              <option value="network">Network</option>
            </select>
          </Field>
          {formData.printer_type === "network" && (
            <>
              <Field label="IP Address" span="4"><input name="ip_address" value={formData.ip_address} onChange={handleChange} className="pm-input" /></Field>
              <Field label="Port" span="2"><input type="number" name="port" value={formData.port} onChange={handleChange} className="pm-input" /></Field>
            </>
          )}
          <Field label="Notes" span={formData.printer_type === "network" ? "6" : "12"}>
            <input name="notes" value={formData.notes} onChange={handleChange} className="pm-input" />
          </Field>
          <div className="col-span-12 grid grid-cols-2 gap-2 md:col-span-4">
            <CheckBox name="is_default" checked={formData.is_default} onChange={handleChange} label="Default" />
            <CheckBox name="is_active" checked={formData.is_active} onChange={handleChange} label="Active" />
          </div>
          <div className="col-span-12 border border-[#D8E1E0]">
            <div className="flex h-10 items-center justify-between border-b border-[#D8E1E0] bg-[#F8FAFC] px-3">
              <span className="text-[11px] font-medium uppercase tracking-wide text-[#324444]">Detected Printers</span>
              <button type="button" onClick={detectLocalPrinters} disabled={loadingQz} className="h-7 border border-[#ACB8B8] bg-white px-3 text-[10px] font-medium uppercase text-[#004141]">{loadingQz ? "Detecting" : "Detect"}</button>
            </div>
            <div className="max-h-[160px] overflow-auto p-2">
              {detectedPrinters.length === 0 ? <div className="py-4 text-center text-[11px] text-[#637877]">No printers detected.</div> : (
                <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                  {detectedPrinters.map((printer) => (
                    <button key={printer} type="button" onClick={() => handlePickDetectedPrinter(printer)} className={`border px-2 py-2 text-left text-[11px] ${formData.printer_name === printer ? "border-[#00BCBC] bg-[#E8F7F5]" : "border-[#D8E1E0] bg-white hover:bg-[#F8FAFC]"}`}>
                      <span className="font-medium">{printer}</span>{printer === defaultPrinter && <span className="ml-2 text-[9px] text-[#008A8A]">Default</span>}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
      <DialogFooter>
        <button type="button" onClick={onClose} className="pm-btn-secondary">Cancel</button>
        <button type="button" onClick={testPrinter} disabled={testingPrinter || !formData.printer_name} className="pm-btn-secondary">{testingPrinter ? "Testing" : "Test"}</button>
        <button type="submit" disabled={saving} className="pm-btn-primary">{saving ? "Saving" : isEditing ? "Update" : "Save"}</button>
      </DialogFooter>
    </form>
  </EnterpriseFormModal>
);

const CertificateFormModal = ({ certForm, isEditingCert, branches, availableCertTills, savingCert, handleCertChange, handleCertFileChange, handleCertificateSubmit, onClose, getBranchName, getTillName }) => (
  <EnterpriseFormModal open title={isEditingCert ? "Edit Certificate" : "Add Certificate"} onClose={onClose} busy={savingCert}>
    <form onSubmit={handleCertificateSubmit} className="flex min-h-0 flex-col">
      <div className="min-h-0 flex-1 overflow-auto p-4">
        <div className="grid grid-cols-12 gap-3">
          <Field label="Name" span="6"><input name="name" value={certForm.name} onChange={handleCertChange} className="pm-input" /></Field>
          <Field label="Branch" span="3">
            <select name="branch_id" value={certForm.branch_id} onChange={handleCertChange} className="pm-input bg-white">
              <option value="">Company Level</option>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
            </select>
          </Field>
          <Field label="Till" span="3">
            <select name="till_id" value={certForm.till_id} onChange={handleCertChange} className="pm-input bg-white">
              <option value="">All Tills</option>
              {availableCertTills.map((till) => <option key={till.id} value={till.id}>{getTillName(till)}</option>)}
            </select>
          </Field>
          <Field label={isEditingCert ? "Certificate File Optional" : "Certificate File"} span="6"><input type="file" name="certificate" accept=".txt,.pem,.crt,.cer" onChange={handleCertFileChange} className="pm-file" /></Field>
          <Field label={isEditingCert ? "Private Key Optional" : "Private Key"} span="6"><input type="file" name="private_key" accept=".pem,.key,.txt" onChange={handleCertFileChange} className="pm-file" /></Field>
          <Field label="Notes" span="9"><input name="notes" value={certForm.notes} onChange={handleCertChange} className="pm-input" /></Field>
          <div className="col-span-12 pt-5 md:col-span-3"><CheckBox name="is_active" checked={certForm.is_active} onChange={handleCertChange} label="Active" /></div>
        </div>
      </div>
      <DialogFooter>
        <button type="button" onClick={onClose} className="pm-btn-secondary">Cancel</button>
        <button type="submit" disabled={savingCert} className="pm-btn-primary">{savingCert ? "Saving" : isEditingCert ? "Update" : "Save"}</button>
      </DialogFooter>
    </form>
  </EnterpriseFormModal>
);

const DialogFooter = ({ children }) => <div className="flex h-12 shrink-0 items-center justify-end gap-2 border-t border-[#D8E1E0] bg-[#F8FAFC] px-4">{children}</div>;

const Field = ({ label, span = "3", children }) => {
  const spanClass = { 2: "col-span-12 md:col-span-2", 3: "col-span-12 md:col-span-3", 4: "col-span-12 md:col-span-4", 6: "col-span-12 md:col-span-6", 9: "col-span-12 md:col-span-9", 12: "col-span-12" }[span] || "col-span-12 md:col-span-3";
  return <label className={spanClass}><div className="mb-1 text-[11px] font-medium uppercase tracking-wide text-[#324444]">{label}</div>{children}</label>;
};

const CheckBox = ({ name, checked, onChange, label }) => (
  <label className="flex h-9 items-center gap-2 border border-[#D8E1E0] bg-white px-2 text-[12px] text-[#324444]">
    <input type="checkbox" name={name} checked={checked} onChange={onChange} className="h-3.5 w-3.5" />{label}
  </label>
);

const CardLine = ({ label, value }) => <div className="min-w-0"><div className="text-[9px] font-medium uppercase tracking-wide text-[#637877]">{label}</div><div className="mt-0.5 truncate text-[11px] text-[#091B1B]" title={value}>{value || "-"}</div></div>;

const SmallButton = ({ children, onClick, danger = false, disabled = false }) => (
  <button type="button" onClick={onClick} disabled={disabled} className={`h-7 border px-2 text-[10px] font-medium uppercase disabled:opacity-60 ${danger ? "border-[#FCA5A5] bg-white text-[#B42318] hover:bg-[#FEF3F2]" : "border-[#ACB8B8] bg-white text-[#004141] hover:bg-[#F0F5F5]"}`}>{children}</button>
);

const AppInlineStyles = () => (
  <style>{`
    .pm-input { height: 36px; width: 100%; border: 1px solid #ACB8B8; background: #ffffff; padding: 0 8px; font-size: 12px; outline: none; }
    .pm-file { min-height: 36px; width: 100%; border: 1px solid #ACB8B8; background: #ffffff; padding: 6px 8px; font-size: 11px; outline: none; }
    .pm-input:focus, .pm-file:focus { border-color: #00BCBC; }
    .pm-btn-primary, .pm-btn-secondary { height: 32px; padding: 0 16px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
    .pm-btn-primary { border: 1px solid #008A8A; background: #00BCBC; color: #004141; }
    .pm-btn-secondary { border: 1px solid #ACB8B8; background: #ffffff; color: #004141; }
    button, input, select, textarea { border-radius: 0 !important; }
  `}</style>
);

export default PrinterManagementModal;

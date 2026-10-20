import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import {
  getCompanyLicenses,
  renewLicenseOffline,
} from "../../../services/GeneralSettingsService";
import {
  formatLicenseKey,
  getLicenseKeyValidationMessage,
  isValidLicenseKey,
  LICENSE_KEY_FORMATTED_LENGTH,
  normalizeLicenseKey,
} from "../../../utils/licenseKey";

const CompanyLicenseModal = ({ open, onClose, companyId }) => {
  const [company, setCompany] = useState(null);
  const [licenses, setLicenses] = useState([]);
  const [loading, setLoading] = useState(false);
  const [activating, setActivating] = useState(false);
  const [activePanel, setActivePanel] = useState("activate");
  const [licenseKey, setLicenseKey] = useState("");
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
      const validationMessages = Object.values(responseData.data).flat();

      if (validationMessages.length > 0) {
        return validationMessages.join("\n");
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
    setMessageModal({ open: true, type, title, message });
  };

  const formatDate = (value) => {
    if (!value) return "-";

    const dateOnly = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:$|T)/);
    const date = dateOnly
      ? new Date(
          Number(dateOnly[1]),
          Number(dateOnly[2]) - 1,
          Number(dateOnly[3])
        )
      : new Date(value);

    if (Number.isNaN(date.getTime())) return "-";

    return date.toLocaleDateString("en-GB", {
      year: "numeric",
      month: "short",
      day: "2-digit",
    });
  };

  const maskLicenseKey = (value) => {
    const key = String(value || "").trim();

    if (!key) return "-";

    const compact = key.replace(/[^A-Z0-9]/gi, "").toUpperCase();

    if (compact.length >= 8) {
      return `${compact.slice(0, 4)}-••••-••••-${compact.slice(-4)}`;
    }

    return "••••••••";
  };

  const getStatusClass = (status) => {
    const normalized = String(status || "").toLowerCase();

    if (normalized === "active") {
      return "border-[#86EFAC] bg-[#DCFCE7] text-[#166534]";
    }

    if (normalized === "expired") {
      return "border-red-200 bg-red-100 text-red-700";
    }

    if (normalized === "suspended") {
      return "border-[#FCD34D] bg-[#FEF3C7] text-[#92400E]";
    }

    return "border-[#ACB8B8] bg-[#E6ECEC] text-[#003636]";
  };

  const normalizeResponse = (response) => {
    const responseCompany = response?.company || response?.data?.company || null;

    return {
      company: responseCompany,
      licenses:
        responseCompany?.licenses ||
        response?.licenses ||
        response?.data?.licenses ||
        [],
    };
  };

  const sortedLicenses = useMemo(() => {
    return [...licenses].sort((a, b) => {
      const dateA = new Date(a.created_at || a.starts_at || 0).getTime();
      const dateB = new Date(b.created_at || b.starts_at || 0).getTime();

      return dateB - dateA;
    });
  }, [licenses]);

  const activeLicense = useMemo(
    () =>
      sortedLicenses.find(
        (license) => String(license.status).toLowerCase() === "active"
      ) || null,
    [sortedLicenses]
  );

  const loadLicenses = async () => {
    if (!companyId) return;

    try {
      setLoading(true);
      const response = await getCompanyLicenses(companyId);
      const normalized = normalizeResponse(response);

      setCompany(normalized.company);
      setLicenses(normalized.licenses);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Licenses Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open && companyId) {
      loadLicenses();
      setActivePanel("activate");
      setLicenseKey("");
    }
    // Load only when this modal opens for a company.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, companyId]);

  const handleActivate = async (event) => {
    event.preventDefault();

    if (activating) return;

    if (!isValidLicenseKey(licenseKey)) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: getLicenseKeyValidationMessage(licenseKey),
      });
      return;
    }

    try {
      setActivating(true);
      const response = await renewLicenseOffline({
        license_key: normalizeLicenseKey(licenseKey),
      });

      showMessage({
        type: "success",
        title: "License Activated",
        message: getApiMessage(response, "License activated successfully."),
      });
      setLicenseKey("");
      await loadLicenses();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Activation Failed",
        message: getApiMessage(error),
      });
    } finally {
      setActivating(false);
    }
  };

  const renderLimits = (license) => (
    <span>
      {license?.max_branches ?? "-"} branches&nbsp;&nbsp;•&nbsp;&nbsp;
      {license?.max_users ?? "-"} users&nbsp;&nbsp;•&nbsp;&nbsp;
      {license?.max_devices ?? "-"} devices
    </span>
  );

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="LICENSE MANAGEMENT"
        subtitle="SERVENEST ENTERPRISE LICENSING"
      >
        <div className="h-full overflow-y-auto bg-[#F3F6F8] text-[#003636]">
          <div className="mx-auto w-full max-w-[1120px] px-3 py-4 sm:px-5 sm:py-5">
            <section
              aria-label="Company and active license"
              className="border border-[#CAD5D5] bg-white"
            >
              <div className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <div className="text-[9px] font-black uppercase tracking-wide text-[#637877]">
                    Company
                  </div>
                  <div className="truncate text-[15px] font-black">
                    {company?.company_name || (loading ? "Loading..." : "-")}
                  </div>
                  {company?.domain && (
                    <div className="truncate text-[10px] text-[#637877]">
                      {company.domain}
                    </div>
                  )}
                </div>

                <button
                  type="button"
                  onClick={loadLicenses}
                  disabled={loading}
                  className="h-8 self-start border border-[#ACB8B8] bg-[#F8FAFC] px-3 text-[9px] font-black uppercase text-[#324444] hover:bg-[#EFF3F3] disabled:opacity-50 sm:self-auto"
                >
                  {loading ? "Refreshing..." : "Refresh"}
                </button>
              </div>

              <div className="border-t border-[#E2E9E9] bg-[#F8FAFC] px-4 py-3">
                {activeLicense ? (
                  <div className="grid gap-3 md:grid-cols-[minmax(180px,1.15fr)_minmax(150px,0.85fr)_minmax(210px,1fr)] md:items-center">
                    <div className="min-w-0">
                      <div className="mb-1 flex flex-wrap items-center gap-2">
                        <span className="text-[9px] font-black uppercase tracking-wide text-[#637877]">
                          Active license
                        </span>
                        <span
                          className={`border px-2 py-0.5 text-[8px] font-black uppercase ${getStatusClass(
                            activeLicense.status
                          )}`}
                        >
                          {activeLicense.status || "Unknown"}
                        </span>
                      </div>
                      <div className="truncate text-[12px] font-black uppercase">
                        {activeLicense.license_type || "License"}
                      </div>
                      <div className="truncate font-mono text-[10px] font-bold text-[#465858]">
                        {maskLicenseKey(activeLicense.license_key)}
                      </div>
                    </div>

                    <div>
                      <div className="text-[9px] font-black uppercase tracking-wide text-[#637877]">
                        Valid until
                      </div>
                      <div className="text-[12px] font-black">
                        {formatDate(activeLicense.expires_at)}
                      </div>
                    </div>

                    <div>
                      <div className="text-[9px] font-black uppercase tracking-wide text-[#637877]">
                        Limits
                      </div>
                      <div className="text-[10px] font-bold text-[#324444]">
                        {renderLimits(activeLicense)}
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="text-[11px] text-[#637877]">
                    {loading
                      ? "Loading license details..."
                      : "No active license is assigned to this company."}
                  </div>
                )}
              </div>
            </section>

            <div
              role="tablist"
              aria-label="License management sections"
              className="mt-4 flex border-b border-[#BAC4C4]"
            >
              <button
                id="license-activate-tab"
                type="button"
                role="tab"
                aria-selected={activePanel === "activate"}
                aria-controls="license-activate-panel"
                onClick={() => setActivePanel("activate")}
                className={`h-10 min-w-[132px] border-x border-t border-[#BAC4C4] px-4 text-[10px] font-black uppercase ${
                  activePanel === "activate"
                    ? "border-b-2 border-b-[#069495] bg-white text-[#003636]"
                    : "bg-[#E6ECEC] text-[#556262]"
                }`}
              >
                Activate
              </button>

              <button
                id="license-history-tab"
                type="button"
                role="tab"
                aria-selected={activePanel === "history"}
                aria-controls="license-history-panel"
                onClick={() => setActivePanel("history")}
                className={`h-10 min-w-[132px] border-r border-t border-[#BAC4C4] px-4 text-[10px] font-black uppercase ${
                  activePanel === "history"
                    ? "border-b-2 border-b-[#069495] bg-white text-[#003636]"
                    : "bg-[#E6ECEC] text-[#556262]"
                }`}
              >
                History ({licenses.length})
              </button>
            </div>

            {activePanel === "activate" && (
              <div
                id="license-activate-panel"
                role="tabpanel"
                aria-labelledby="license-activate-tab"
                className="border-x border-b border-[#CAD5D5] bg-white px-3 py-6 sm:px-6 sm:py-8"
              >
                <form
                  onSubmit={handleActivate}
                  className="mx-auto w-full max-w-[650px]"
                >
                  <div className="mb-5 text-center">
                    <h2 className="text-[15px] font-black uppercase">
                      Activate a license
                    </h2>
                    <p className="mt-1 text-[10px] leading-relaxed text-[#637877]">
                      Enter the 16-character key provided for this company.
                    </p>
                  </div>

                  <label
                    htmlFor="company-license-key"
                    className="mb-1 block text-[9px] font-black uppercase tracking-wide text-[#465858]"
                  >
                    License key
                  </label>

                  <div className="flex flex-col gap-2 sm:flex-row">
                    <input
                      id="company-license-key"
                      type="text"
                      inputMode="text"
                      autoCapitalize="characters"
                      autoComplete="off"
                      spellCheck={false}
                      maxLength={LICENSE_KEY_FORMATTED_LENGTH}
                      value={licenseKey}
                      onChange={(event) =>
                        setLicenseKey(formatLicenseKey(event.target.value))
                      }
                      placeholder="XXXX-XXXX-XXXX-XXXX"
                      className="h-11 min-w-0 flex-1 border border-[#ACB8B8] px-3 font-mono text-[15px] font-black uppercase tracking-[0.13em] outline-none focus:border-[#069495] focus:ring-1 focus:ring-[#069495]"
                    />

                    <button
                      type="submit"
                      disabled={activating}
                      className="h-11 shrink-0 bg-[#069495] px-6 text-[10px] font-black uppercase text-white hover:bg-[#067C7C] disabled:opacity-50"
                    >
                      {activating ? "Activating..." : "Activate License"}
                    </button>
                  </div>

                  <div className="mt-2 flex min-h-[20px] items-start justify-between gap-3">
                    <span className="text-[9px] leading-relaxed text-[#637877]">
                      Format: XXXX-XXXX-XXXX-XXXX
                    </span>
                    {licenseKey && (
                      <button
                        type="button"
                        onClick={() => setLicenseKey("")}
                        className="shrink-0 text-[9px] font-black uppercase text-[#067C7C] hover:underline"
                      >
                        Clear
                      </button>
                    )}
                  </div>
                </form>
              </div>
            )}

            {activePanel === "history" && (
              <div
                id="license-history-panel"
                role="tabpanel"
                aria-labelledby="license-history-tab"
                className="border-x border-b border-[#CAD5D5] bg-white"
              >
                <div className="border-b border-[#E2E9E9] px-4 py-3">
                  <div>
                    <h2 className="text-[11px] font-black uppercase">
                      License history
                    </h2>
                    <p className="mt-0.5 text-[9px] text-[#637877]">
                      Previous and current licenses for this company.
                    </p>
                  </div>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full min-w-[760px] border-collapse">
                    <thead>
                      <tr className="border-b border-[#D7DFDE] bg-[#F3F6F8]">
                        <th className="px-3 py-2 text-left text-[9px] font-black uppercase">
                          License key
                        </th>
                        <th className="px-3 py-2 text-left text-[9px] font-black uppercase">
                          Plan
                        </th>
                        <th className="px-3 py-2 text-left text-[9px] font-black uppercase">
                          Period
                        </th>
                        <th className="px-3 py-2 text-left text-[9px] font-black uppercase">
                          Limits
                        </th>
                        <th className="px-3 py-2 text-left text-[9px] font-black uppercase">
                          Status
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {loading && sortedLicenses.length === 0 ? (
                        <tr>
                          <td
                            colSpan={5}
                            className="px-3 py-8 text-center text-[11px] text-[#637877]"
                          >
                            Loading licenses...
                          </td>
                        </tr>
                      ) : sortedLicenses.length === 0 ? (
                        <tr>
                          <td
                            colSpan={5}
                            className="px-3 py-8 text-center text-[11px] text-[#637877]"
                          >
                            No licenses found.
                          </td>
                        </tr>
                      ) : (
                        sortedLicenses.map((license) => (
                          <tr
                            key={license.id || license.license_key}
                            className="border-b border-[#EFF2F2] last:border-b-0 hover:bg-[#F8FAFC]"
                          >
                            <td className="px-3 py-3 font-mono text-[10px] font-bold">
                              {maskLicenseKey(license.license_key)}
                            </td>
                            <td className="px-3 py-3 text-[10px] font-black uppercase">
                              {license.license_type || "-"}
                            </td>
                            <td className="px-3 py-3 text-[10px]">
                              <div>{formatDate(license.starts_at)}</div>
                              <div className="text-[9px] text-[#637877]">
                                to {formatDate(license.expires_at)}
                              </div>
                            </td>
                            <td className="px-3 py-3 text-[9px] font-bold text-[#465858]">
                              {renderLimits(license)}
                            </td>
                            <td className="px-3 py-3">
                              <span
                                className={`border px-2 py-1 text-[8px] font-black uppercase ${getStatusClass(
                                  license.status
                                )}`}
                              >
                                {license.status || "Unknown"}
                              </span>
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        </div>
      </FullScreenModal>

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={() =>
          setMessageModal((previous) => ({ ...previous, open: false }))
        }
      />
    </>
  );
};

export default CompanyLicenseModal;

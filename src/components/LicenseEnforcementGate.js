import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAdminAuth } from "../backOffice/context/AdminAuthContext";
import { useFrontAuth } from "../context/FrontAuthContext";
import { getLicenseStatus } from "../services/GeneralSettingsService";
import {
  clearWorkspaceAuthentication,
  getLicenseFailure,
  LICENSE_BLOCKED_EVENT,
} from "../utils/apiBack";
import {
  isSuperAdministrator,
  isUnscopedPlatformAdministrator,
} from "../utils/workspaceAccess";

const BLOCKED_CODES = new Set([
  "LICENSE_EXPIRED",
  "LICENSE_INVALID",
  "LICENSE_MISSING",
  "LICENSE_INACTIVE",
  "LICENSE_NOT_ACTIVE",
  "LICENSE_NOT_FOUND",
  "LICENSE_REQUIRED",
  "LICENSE_REVOKED",
  "LICENSE_SUSPENDED",
  "NO_ACTIVE_LICENSE",
]);

const BLOCKED_STATUSES = new Set([
  "expired",
  "invalid",
  "missing",
  "inactive",
  "revoked",
  "suspended",
]);

const truthy = (value) =>
  value === true || value === 1 || value === "1" || String(value || "").toLowerCase() === "true";

export const unwrapLicenseStatus = (payload) =>
  payload?.license || payload?.data?.license || payload?.data || payload || {};

const hasBlockedUnderlyingStatus = (license) => {
  if (!license) return false;
  const code = String(license.code || "").toUpperCase();
  const status = String(license.status || "").toLowerCase();
  const explicitlyInvalid = license.valid === false || String(license.valid).toLowerCase() === "false";

  return explicitlyInvalid || BLOCKED_CODES.has(code) || BLOCKED_STATUSES.has(status);
};

export const isBlockedLicense = (license) =>
  Boolean(license && license.bypass !== true && hasBlockedUnderlyingStatus(license));

const serverDateFor = (payload, license) => {
  const supplied = license?.server_date || payload?.server_date || payload?.data?.server_date;

  if (/^\d{4}-\d{2}-\d{2}$/.test(String(supplied || ""))) {
    return String(supplied);
  }

  return new Date().toISOString().slice(0, 10);
};

const warningKeyFor = (license, user) => {
  const companyId = license?.company_id
    || user?.selectedCompanyId
    || user?.selectedCompany?.id
    || user?.company_id
    || "company";
  const licenseId = license?.license_id || license?.id || license?.expires_at || "license";

  return `salexa_license_warning:${companyId}:${licenseId}`;
};

const shouldWarn = (license) => {
  if (!license || hasBlockedUnderlyingStatus(license)) return false;

  const daysRemaining = Number(license.days_remaining);
  const warningDays = Number(license.warning_days ?? 90);

  return truthy(license.warning)
    || (Number.isFinite(daysRemaining)
      && Number.isFinite(warningDays)
      && daysRemaining >= 0
      && daysRemaining <= warningDays);
};

const formatExpiry = (value) => {
  if (!value) return "Not supplied";

  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleDateString("en-GB", {
        year: "numeric",
        month: "long",
        day: "2-digit",
      });
};

export default function LicenseEnforcementGate({ children }) {
  const location = useLocation();
  const navigate = useNavigate();
  const adminAuth = useAdminAuth();
  const frontAuth = useFrontAuth();
  const inFlightRef = useRef(false);
  const [checkedSession, setCheckedSession] = useState(null);
  const [warningLicense, setWarningLicense] = useState(null);

  const isFrontRoute = location.pathname.startsWith("/front");
  const isAdminRoute = location.pathname.startsWith("/admin");
  const activeAuth = isFrontRoute ? frontAuth : isAdminRoute ? adminAuth : null;
  const activeUser = activeAuth?.user || null;
  const activeAuthenticated = Boolean(activeAuth?.isAuthenticated && activeUser);
  const activeIsSuperAdmin = isSuperAdministrator(activeUser);
  const activeIsUnscopedPlatform = isAdminRoute
    && isUnscopedPlatformAdministrator(activeUser);
  const sessionKey = activeAuthenticated
    ? `${isFrontRoute ? "front" : "back"}:${activeUser?.id || activeUser?.username || "user"}`
    : null;

  const clearNonSuperSessions = useCallback(() => {
    if (!isSuperAdministrator(frontAuth.user)) frontAuth.logout();
    if (!isSuperAdministrator(adminAuth.user)) adminAuth.logout();
    setWarningLicense(null);
    navigate("/", { replace: true });
  }, [adminAuth, frontAuth, navigate]);

  useEffect(() => {
    const onLicenseBlocked = () => {
      if (activeIsSuperAdmin) return;
      clearNonSuperSessions();
    };

    window.addEventListener(LICENSE_BLOCKED_EVENT, onLicenseBlocked);
    return () => window.removeEventListener(LICENSE_BLOCKED_EVENT, onLicenseBlocked);
  }, [activeIsSuperAdmin, clearNonSuperSessions]);

  const evaluateStatus = useCallback(async () => {
    if (!activeAuthenticated || !sessionKey || inFlightRef.current) return;

    if (activeIsUnscopedPlatform) {
      setWarningLicense(null);
      setCheckedSession(sessionKey);
      return;
    }

    inFlightRef.current = true;

    try {
      const payload = await getLicenseStatus();
      const license = unwrapLicenseStatus(payload);

      if (!activeIsSuperAdmin && isBlockedLicense(license)) {
        clearWorkspaceAuthentication({ failure: license });
        return;
      }

      if (shouldWarn(license)) {
        const serverDate = serverDateFor(payload, license);
        const warningKey = warningKeyFor(license, activeUser);

        if (localStorage.getItem(warningKey) !== serverDate) {
          localStorage.setItem(warningKey, serverDate);
          setWarningLicense(license);
        }
      } else {
        setWarningLicense(null);
      }
    } catch (error) {
      const failure = getLicenseFailure(error);

      if (failure && !activeIsSuperAdmin) {
        clearWorkspaceAuthentication({ failure });
      }
    } finally {
      inFlightRef.current = false;
      setCheckedSession(sessionKey);
    }
  }, [
    activeAuthenticated,
    activeIsSuperAdmin,
    activeIsUnscopedPlatform,
    activeUser,
    sessionKey,
  ]);

  useEffect(() => {
    if (!activeAuthenticated || !sessionKey) {
      setCheckedSession(null);
      setWarningLicense(null);
      return undefined;
    }

    if (activeIsUnscopedPlatform) {
      setCheckedSession(sessionKey);
      setWarningLicense(null);
      return undefined;
    }

    evaluateStatus();
    const heartbeat = window.setInterval(evaluateStatus, 60 * 1000);
    const onFocus = () => evaluateStatus();
    const onVisibilityChange = () => {
      if (document.visibilityState === "visible") evaluateStatus();
    };

    window.addEventListener("focus", onFocus);
    document.addEventListener("visibilitychange", onVisibilityChange);

    return () => {
      window.clearInterval(heartbeat);
      window.removeEventListener("focus", onFocus);
      document.removeEventListener("visibilitychange", onVisibilityChange);
    };
  }, [activeAuthenticated, activeIsUnscopedPlatform, evaluateStatus, sessionKey]);

  const warningDetails = useMemo(() => {
    if (!warningLicense) return null;

    const days = Number(warningLicense.days_remaining);
    return {
      days: Number.isFinite(days) ? Math.max(0, Math.ceil(days)) : null,
      expiry: formatExpiry(warningLicense.expires_at),
      phone: String(warningLicense.license_support_phone || "").trim(),
    };
  }, [warningLicense]);

  if (
    activeAuthenticated &&
    !activeIsUnscopedPlatform &&
    checkedSession !== sessionKey
  ) {
    return (
      <main
        role="status"
        aria-live="polite"
        className="flex min-h-screen items-center justify-center bg-[#F4F7F9] text-[#004141]"
      >
        <div className="border border-[#ACB8B8] bg-white px-8 py-6 text-center shadow-lg">
          <div className="text-[12px] font-black uppercase tracking-[0.14em]">
            Checking company license
          </div>
          <div className="mt-2 text-[11px] text-[#637877]">
            Preparing your workspace...
          </div>
        </div>
      </main>
    );
  }

  return (
    <>
      {children}

      {warningDetails && (
        <div
          className="fixed inset-0 z-[999999] flex items-center justify-center bg-black/65 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="license-expiry-warning-title"
        >
          <section className="w-full max-w-[560px] border border-[#ACB8B8] bg-white text-[#003636] shadow-2xl">
            <header className="border-b-4 border-[#F59E0B] bg-[#004142] px-5 py-4 text-white">
              <div className="text-[10px] font-black uppercase tracking-[0.18em] text-[#FCD34D]">
                License reminder
              </div>
              <h2 id="license-expiry-warning-title" className="mt-1 text-[17px] font-black uppercase">
                Company license expires soon
              </h2>
            </header>

            <div className="space-y-4 p-5">
              <p className="text-[13px] font-semibold leading-6">
                {warningDetails.days === null
                  ? "The company license is within its renewal period."
                  : `${warningDetails.days} day${warningDetails.days === 1 ? "" : "s"} remaining on this company license.`}
              </p>

              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div className="border border-[#D7DFDE] bg-[#F8FAFC] p-3">
                  <div className="text-[9px] font-black uppercase text-[#637877]">Expires</div>
                  <div className="mt-1 text-[12px] font-black">{warningDetails.expiry}</div>
                </div>
                <div className="border border-[#D7DFDE] bg-[#F8FAFC] p-3">
                  <div className="text-[9px] font-black uppercase text-[#637877]">Contact administrator</div>
                  <div className="mt-1 text-[12px] font-black">
                    {warningDetails.phone || "Contact details are not configured."}
                  </div>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setWarningLicense(null)}
                className="h-10 w-full bg-[#069495] text-[11px] font-black uppercase text-white hover:bg-[#067C7C]"
              >
                Continue
              </button>
            </div>
          </section>
        </div>
      )}
    </>
  );
}

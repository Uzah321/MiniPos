// src/backOffice/login/AdminLogin.jsx

import React, { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import { Building2, ChevronRight, CornerDownLeft, Delete, Eye, EyeOff, Globe2, Keyboard, LoaderCircle, LockKeyhole, Search, Space, X } from "lucide-react";

import {
  getAuthorisedCompany,
  getPosLoginModeSetting,
  loginUser,
  selectBackOfficeCompany,
} from "../../services/AuthenticationService";

import { checkSystemClock } from "../../services/SystemClockService";
import { renewLicenseOffline } from "../../services/GeneralSettingsService";
import { getLocalTill } from "../../services/TillService";
import {
  getSavedCompanyCode,
  normalizeCompanyCode,
  saveCompanyCode,
} from "../../utils/apiConfig";
import { mergePlatformAdministratorIdentity } from "../../utils/workspaceAccess";
import { PLATFORM_FALLBACK_LOGO } from "../../utils/hospitalityHierarchy";
import { publicAssetUrl } from "../../utils/publicAssetUrl";
import { useAdminAuth } from "../context/AdminAuthContext";
import { useFrontAuth } from "../../context/FrontAuthContext";
import {
  formatLicenseKey,
  getLicenseKeyValidationMessage,
  isValidLicenseKey,
  LICENSE_KEY_FORMATTED_LENGTH,
  normalizeLicenseKey,
} from "../../utils/licenseKey";

import buildInfo from "../../buildInfo.json";
import {
  backOfficeCompanyLocation,
  backOfficeCompanyName,
  extractBackOfficeCompanyChoices,
} from "./backOfficeLoginCompanies";

const MAX_ALLOWED_DRIFT_MS = 5 * 60 * 1000;

const DEFAULT_LOGIN_CODE_POLICY = Object.freeze({
  minimum_length: 4,
  maximum_length: 8,
  numeric_only: true,
  reject_repeated_digits: false,
  reject_sequences: false,
});

const normalizeLoginMode = (value) => {
  const clean = String(value || "")
    .trim()
    .toLowerCase()
    .replaceAll("-", "_")
    .replaceAll(" ", "_");

  if (["code", "pin", "pin_code", "login_code", "code_branch"].includes(clean)) {
    return "code";
  }

  if (["both", "either", "code_or_password", "code_and_password"].includes(clean)) {
    return "both";
  }

  return "password";
};

const LOGIN_MODE_STORAGE_KEY = "servenest_pos_login_method";

const readRememberedLoginMode = () => {
  try {
    const mode = localStorage.getItem(LOGIN_MODE_STORAGE_KEY);
    return mode === "code" || mode === "password" ? mode : null;
  } catch {
    return null;
  }
};

const rememberLoginMode = (mode) => {
  try {
    localStorage.setItem(LOGIN_MODE_STORAGE_KEY, mode);
  } catch {
    // Storage can be unavailable (private window); the choice still applies now.
  }
};

const KEYBOARD_ROWS = [
  ["1", "2", "3", "4", "5", "6", "7", "8", "9", "0"],
  ["q", "w", "e", "r", "t", "y", "u", "i", "o", "p"],
  ["a", "s", "d", "f", "g", "h", "j", "k", "l"],
  ["z", "x", "c", "v", "b", "n", "m", ".", "@", "_"],
];

const formatClockTime = (value) => {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) return "-";

  return date.toLocaleString("en-ZW", {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
};

const getBranchLabel = (branch) => {
  if (!branch) return "Select branch";

  const name = branch.branch_name || branch.name || "Unnamed Branch";
  const code = branch.branch_code || branch.code || "";

  return code ? `${name} (${code})` : name;
};

const setNativeValue = (element, value) => {
  if (!element) return;

  const proto =
    element instanceof HTMLTextAreaElement
      ? window.HTMLTextAreaElement.prototype
      : window.HTMLInputElement.prototype;

  const descriptor = Object.getOwnPropertyDescriptor(proto, "value");

  if (descriptor?.set) {
    descriptor.set.call(element, value);
  } else {
    element.value = value;
  }

  element.dispatchEvent(new Event("input", { bubbles: true }));
  element.dispatchEvent(new Event("change", { bubbles: true }));
};

const insertIntoInput = (element, text) => {
  if (!element) return;

  const current = String(element.value || "");
  const canSelect =
    typeof element.selectionStart === "number" &&
    typeof element.selectionEnd === "number" &&
    element.type !== "number";

  if (!canSelect) {
    setNativeValue(element, `${current}${text}`);
    element.focus?.({ preventScroll: true });
    return;
  }

  const start = element.selectionStart ?? current.length;
  const end = element.selectionEnd ?? current.length;
  const next = `${current.slice(0, start)}${text}${current.slice(end)}`;
  const cursor = start + text.length;

  setNativeValue(element, next);

  try {
    element.setSelectionRange(cursor, cursor);
  } catch {
    // Ignore unsupported selection APIs.
  }

  element.focus?.({ preventScroll: true });
};

const backspaceInput = (element) => {
  if (!element) return;

  const current = String(element.value || "");
  if (!current) return;

  const canSelect =
    typeof element.selectionStart === "number" &&
    typeof element.selectionEnd === "number" &&
    element.type !== "number";

  if (!canSelect) {
    setNativeValue(element, current.slice(0, -1));
    element.focus?.({ preventScroll: true });
    return;
  }

  const start = element.selectionStart ?? current.length;
  const end = element.selectionEnd ?? current.length;
  const deleteStart = start === end ? Math.max(0, start - 1) : start;
  const next = `${current.slice(0, deleteStart)}${current.slice(end)}`;

  setNativeValue(element, next);

  try {
    element.setSelectionRange(deleteStart, deleteStart);
  } catch {
    // Ignore unsupported selection APIs.
  }

  element.focus?.({ preventScroll: true });
};

const AdminLogin = ({ workspace = "back-office", embedded = false, onClose }) => {
  const isFrontOffice = workspace === "front-office";
  const [showPassword, setShowPassword] = useState(false);
  const [showBranchDropdown, setShowBranchDropdown] = useState(false);
  const [branchSearch, setBranchSearch] = useState("");
  const [branchMenuStyle, setBranchMenuStyle] = useState({});
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState({});
  const [branches, setBranches] = useState([]);
  const [branchChoices, setBranchChoices] = useState([]);
  const [branchSelectionOpen, setBranchSelectionOpen] = useState(false);
  const [branchSelectionSearch, setBranchSelectionSearch] = useState("");
  const [companyChoices, setCompanyChoices] = useState([]);
  const [companySelectionOpen, setCompanySelectionOpen] = useState(false);
  const [companySelectionSearch, setCompanySelectionSearch] = useState("");
  const [companySelectionToken, setCompanySelectionToken] = useState("");
  const [selectedLoginCompanyId, setSelectedLoginCompanyId] = useState(null);
  const [loginMode, setLoginMode] = useState("password");
  const [loginModePolicy, setLoginModePolicy] = useState("password");
  const [loginCodePolicy, setLoginCodePolicy] = useState(DEFAULT_LOGIN_CODE_POLICY);
  const [loginModeLoading, setLoginModeLoading] = useState(isFrontOffice);
  // Set once the operator picks a method, so a late policy response never
  // switches the screen out from under them.
  const loginModeChosenRef = useRef(false);

  const [localTillDisplay, setLocalTillDisplay] = useState("CONNECTING");
  const [localTillConnected, setLocalTillConnected] = useState(false);

  const [licenseModalOpen, setLicenseModalOpen] = useState(false);
  const [licenseKey, setLicenseKey] = useState("");
  const [renewingLicense, setRenewingLicense] = useState(false);
  const [licenseError, setLicenseError] = useState("");
  const [licenseSuccess, setLicenseSuccess] = useState("");

  const [clockChecking, setClockChecking] = useState(true);
  const [clockOk, setClockOk] = useState(false);
  const [clockError, setClockError] = useState("");
  const [clockDetails, setClockDetails] = useState(null);

  const [isFullscreen, setIsFullscreen] = useState(false);
  const [keyboardOpen, setKeyboardOpen] = useState(false);
  const [keyboardTargetName, setKeyboardTargetName] = useState("Input");
  const [backOfficeLoginOpen, setBackOfficeLoginOpen] = useState(false);

  const [formData, setFormData] = useState({
    company_code: isFrontOffice ? getSavedCompanyCode() : "",
    username: "",
    password: "",
    login_code: "",
    branch_id: isFrontOffice ? getLocalTill()?.branch_id || "" : "",
  });

  const branchButtonRef = useRef(null);
  const branchSearchRef = useRef(null);
  const usernameRef = useRef(null);
  const passwordRef = useRef(null);
  const loginCodeRef = useRef(null);
  const licenseKeyRef = useRef(null);
  const keyboardTargetRef = useRef(null);
  const loginInFlightRef = useRef(false);

  const { login: adminLogin } = useAdminAuth();
  const { login: frontLogin } = useFrontAuth();
  const navigate = useNavigate();

  const selectedBranch = branches.find(
    (branch) => String(branch.id) === String(formData.branch_id)
  );
  const pairedTill = getLocalTill();
  const pairedBranchId = isFrontOffice ? pairedTill?.branch_id || "" : "";
  const pairedBranch = branches.find(
    (branch) => String(branch.id) === String(pairedBranchId),
  );
  const buildVersion = buildInfo?.displayVersion || buildInfo?.version || "1.0.0";

  const clockStatusText = clockChecking ? "CHECKING" : clockOk ? "VALID" : "BLOCKED";
  const tillStatusText = localTillConnected ? localTillDisplay : "NOT CONNECTED";
  const systemReady = clockOk && (!isFrontOffice || localTillConnected);
  const isCodeLogin = isFrontOffice && loginMode === "code";
  const loginMethodCanSwitch = isFrontOffice && loginModePolicy === "both";
  const loginCodeMinimumLength = Math.max(
    4,
    Math.min(12, Number(loginCodePolicy?.minimum_length) || DEFAULT_LOGIN_CODE_POLICY.minimum_length),
  );
  const loginCodeMaximumLength = Math.max(
    loginCodeMinimumLength,
    Math.min(12, Number(loginCodePolicy?.maximum_length) || DEFAULT_LOGIN_CODE_POLICY.maximum_length),
  );
  const loginCodeNumericOnly = loginCodePolicy?.numeric_only !== false;
  const loginCodeLengthLabel = loginCodeMinimumLength === loginCodeMaximumLength
    ? `${loginCodeMaximumLength}`
    : `${loginCodeMinimumLength}-${loginCodeMaximumLength}`;
  const loginCodeLength = String(formData.login_code || "").length;
  const loginCodeReady = loginCodeLength >= loginCodeMinimumLength
    && loginCodeLength <= loginCodeMaximumLength;
  const loginLogo = isFrontOffice && pairedTill?.brand_logo
    ? pairedTill.brand_logo
    : PLATFORM_FALLBACK_LOGO;
  const loginLogoAlt = isFrontOffice && pairedTill?.brand_name
    ? pairedTill.brand_name
    : "ServeNest";

  const applyLoginModePolicy = (value, credentialPolicy = null) => {
    const policy = normalizeLoginMode(value || "password");
    setLoginModePolicy(policy);
    setLoginMode((current) => {
      if (policy === "code") return "code";
      if (policy !== "both") return "password";
      if (loginModeChosenRef.current) return current;
      // When both methods are allowed, start where this till last left off.
      const remembered = readRememberedLoginMode();
      return remembered || "code";
    });

    const rawLoginCodePolicy = credentialPolicy?.login_code ?? credentialPolicy;
    if (rawLoginCodePolicy && typeof rawLoginCodePolicy === "object") {
      const minimumLength = Math.max(
        4,
        Math.min(12, Number(rawLoginCodePolicy.minimum_length) || DEFAULT_LOGIN_CODE_POLICY.minimum_length),
      );
      const maximumLength = Math.max(
        minimumLength,
        Math.min(12, Number(rawLoginCodePolicy.maximum_length) || DEFAULT_LOGIN_CODE_POLICY.maximum_length),
      );
      setLoginCodePolicy({
        minimum_length: minimumLength,
        maximum_length: maximumLength,
        numeric_only: rawLoginCodePolicy.numeric_only !== false,
        reject_repeated_digits: rawLoginCodePolicy.reject_repeated_digits === true,
        reject_sequences: rawLoginCodePolicy.reject_sequences === true,
      });
    }
  };

  const sanitizeLoginCode = (value) => {
    const raw = String(value || "");
    const allowed = loginCodeNumericOnly ? raw.replace(/\D/g, "") : raw;
    return allowed.slice(0, loginCodeMaximumLength);
  };

  const chooseLoginMode = (mode) => {
    if (!loginMethodCanSwitch || !["code", "password"].includes(mode)) return;

    loginModeChosenRef.current = true;
    rememberLoginMode(mode);
    setLoginMode(mode);
    setErrors((current) => ({
      ...current,
      login_code: "",
      username: "",
      password: "",
      submit: "",
    }));
  };

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

  const isLicenseError = (error) => {
    const responseData = error?.response?.data || {};
    const licenseCode = String(
      responseData?.code || responseData?.license?.code || ""
    ).toUpperCase();
    const message = getApiMessage(error, "").toLowerCase();

    return (
      ["LICENSE_EXPIRED", "LICENSE_INVALID", "LICENSE_MISSING"].includes(
        licenseCode
      ) ||
      error?.response?.status === 423 ||
      message.includes("no active license") ||
      message.includes("license expired") ||
      message.includes("license is invalid") ||
      message.includes("invalid or expired") ||
      message.includes("company license") ||
      message.includes("license not found") ||
      message.includes("license required")
    );
  };

  const openKeyboardFor = (element, label = "Input") => {
    if (!element) return;

    keyboardTargetRef.current = element;
    setKeyboardTargetName(label);
    setKeyboardOpen(true);

    window.setTimeout(() => {
      element.focus?.({ preventScroll: true });
    }, 40);
  };

  useEffect(() => {
    if (!embedded || isCodeLogin || loginModeLoading) return undefined;
    const keyboardTimer = window.setTimeout(() => {
      openKeyboardFor(usernameRef.current, "Username");
    }, 180);
    return () => window.clearTimeout(keyboardTimer);
    // openKeyboardFor is intentionally scoped to the current render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [embedded, isCodeLogin, loginModeLoading]);

  useEffect(() => {
    if (isFrontOffice || embedded) return undefined;

    const handleBackOfficeLandingKey = (event) => {
      if (!backOfficeLoginOpen && ["Enter", " "].includes(event.key)) {
        event.preventDefault();
        setBackOfficeLoginOpen(true);
      } else if (backOfficeLoginOpen && event.key === "Escape" && !loading) {
        setBackOfficeLoginOpen(false);
        setKeyboardOpen(false);
      }
    };

    window.addEventListener("keydown", handleBackOfficeLandingKey);
    return () => window.removeEventListener("keydown", handleBackOfficeLandingKey);
  }, [backOfficeLoginOpen, embedded, isFrontOffice, loading]);

  useEffect(() => {
    if (isFrontOffice || embedded || !backOfficeLoginOpen) return undefined;

    // Focus the username only; the on-screen keyboard stays closed until the
    // operator opens it with the single keyboard button.
    const focusTimer = window.setTimeout(() => {
      keyboardTargetRef.current = usernameRef.current;
      setKeyboardTargetName("Username");
      usernameRef.current?.focus?.({ preventScroll: true });
    }, 120);

    return () => window.clearTimeout(focusTimer);
  }, [backOfficeLoginOpen, embedded, isFrontOffice]);

  const refreshLocalTillDisplay = () => {
    const till = getLocalTill();

    const tillCode = till?.till_code || "";
    const tillName = till?.till_name || "";
    const tillToken = till?.till_token || "";
    const tillBranchId = till?.branch_id || "";

    if (!tillToken || !tillBranchId) {
      setLocalTillDisplay("CONNECTING");
      setLocalTillConnected(false);
      return;
    }

    setLocalTillDisplay(
      tillCode && tillName
        ? `${tillCode} - ${tillName}`
        : tillCode || tillName || till?.id || "REGISTERED TILL"
    );
    setLocalTillConnected(true);
  };

  const filteredBranches = useMemo(() => {
    const search = branchSearch.trim().toLowerCase();

    if (!search) return branches;

    return branches.filter((branch) => {
      const name = String(branch.branch_name || branch.name || "").toLowerCase();
      const code = String(branch.branch_code || branch.code || "").toLowerCase();

      return name.includes(search) || code.includes(search);
    });
  }, [branches, branchSearch]);

  const updateBranchMenuPosition = () => {
    if (!branchButtonRef.current) return;

    const rect = branchButtonRef.current.getBoundingClientRect();
    const menuWidth = rect.width;
    const menuHeight = 280;
    const spaceBelow = window.innerHeight - rect.bottom;
    const spaceAbove = rect.top;
    const openUp = spaceBelow < menuHeight && spaceAbove > spaceBelow;

    setBranchMenuStyle({
      position: "fixed",
      left: `${rect.left}px`,
      width: `${menuWidth}px`,
      top: openUp ? "auto" : `${rect.bottom + 4}px`,
      bottom: openUp ? `${window.innerHeight - rect.top + 4}px` : "auto",
      zIndex: 999999,
    });
  };

  const openBranchMenu = () => {
    setShowBranchDropdown(true);

    window.setTimeout(() => {
      updateBranchMenuPosition();
      branchSearchRef.current?.focus();
    }, 0);
  };

  const closeBranchMenu = () => {
    setShowBranchDropdown(false);
    setBranchSearch("");
  };

  const verifySystemClock = async () => {
    const companyCode = isFrontOffice
      ? normalizeCompanyCode(formData.company_code)
      : null;
    if (!isFrontOffice) {
      setClockChecking(false);
      setClockOk(true);
      setClockError("");
      setClockDetails(null);
      return true;
    }

    try {
      setClockChecking(true);
      setClockError("");
      setClockOk(false);

      const response = await checkSystemClock({ workspace, companyCode });
      const data = response?.data || response || {};

      const serverTimestamp = Number(data.server_timestamp_ms || 0);
      const browserTimestamp = Date.now();

      if (!serverTimestamp) {
        setClockOk(false);
        setClockDetails(null);
        setClockError("Server time could not be verified.");
        return false;
      }

      const driftMs = Math.abs(browserTimestamp - serverTimestamp);
      const driftMinutes = Math.round(driftMs / 60000);

      const details = {
        ...data,
        browser_timestamp_ms: browserTimestamp,
        browser_time: new Date(browserTimestamp).toISOString(),
        drift_ms: driftMs,
        drift_minutes: driftMinutes,
      };

      setClockDetails(details);

      if (driftMs > MAX_ALLOWED_DRIFT_MS) {
        setClockOk(false);
        setClockError("Computer date/time is incorrect. Fix it before login.");
        return false;
      }

      setClockOk(true);
      setClockError("");
      return true;
    } catch (error) {
      setClockOk(false);
      setClockError(
        getApiMessage(error, "Clock verification failed. Login is blocked.")
      );
      setClockDetails(error?.response?.data?.data || null);
      return false;
    } finally {
      setClockChecking(false);
    }
  };

  const loadLoginModeSetting = async () => {
    if (!isFrontOffice) {
      applyLoginModePolicy("password");
      setLoginModeLoading(false);
      return;
    }

    try {
      setLoginModeLoading(true);

      const loadedConfiguration = await getPosLoginModeSetting({
        workspace,
        companyCode: normalizeCompanyCode(formData.company_code),
        includePolicy: true,
      });
      applyLoginModePolicy(
        loadedConfiguration?.login_mode || "password",
        loadedConfiguration?.credential_policy,
      );
    } catch {
      applyLoginModePolicy("password");
    } finally {
      setLoginModeLoading(false);
    }
  };

  const loadCompanyInfo = async () => {
    const companyCode = isFrontOffice
      ? normalizeCompanyCode(formData.company_code)
      : null;
    if (!isFrontOffice) {
      setBranches([]);
      setLicenseModalOpen(false);
      setLicenseError("");
      return;
    }

    try {
      const res = await getAuthorisedCompany({ workspace, companyCode });

      const info =
        res?.company ||
        res?.schoolInfo ||
        res?.data?.companyInfo ||
        res?.data?.schoolInfo ||
        res?.data ||
        null;

      const apiBranches =
        res?.company?.branches ||
        res?.data?.branches ||
        info?.branches ||
        [];

      const scopedBranches = isFrontOffice && pairedBranchId
        ? apiBranches.filter((branch) => String(branch.id) === String(pairedBranchId))
        : apiBranches;

      setBranches(scopedBranches);

      if (isFrontOffice && pairedBranchId && apiBranches.length > 0 && scopedBranches.length === 0) {
        setErrors((current) => ({
          ...current,
          branch_id: "The paired branch is not available for this company.",
        }));
      }

      setLicenseModalOpen(false);
      setLicenseError("");
    } catch (err) {
      console.error("Error loading company info:", err);

      if (isLicenseError(err)) {
        setLicenseModalOpen(true);
        setLicenseError(
          getApiMessage(err, "No active license found for this company.")
        );
        return;
      }

      setErrors((prev) => ({
        ...prev,
        submit: getApiMessage(err, "Could not load company information."),
      }));
    }
  };

  useEffect(() => {
    if (!isFrontOffice) {
      // A Back Office login is resolved from the user account itself. Remove
      // any legacy tenant hint before the first request so it cannot influence
      // authentication or reveal a previous operator's company.
      saveCompanyCode("");
      setLocalTillDisplay("MANAGEMENT");
      setLocalTillConnected(false);
      return undefined;
    }

    refreshLocalTillDisplay();

    window.addEventListener("storage", refreshLocalTillDisplay);
    window.addEventListener("salexa_till_updated", refreshLocalTillDisplay);

    return () => {
      window.removeEventListener("storage", refreshLocalTillDisplay);
      window.removeEventListener("salexa_till_updated", refreshLocalTillDisplay);
    };
  }, [isFrontOffice]);

  useEffect(() => {
    verifySystemClock();
    loadCompanyInfo();
    loadLoginModeSetting();
  }, []);


  useEffect(() => {
    if (!showBranchDropdown) return;

    const closeOnOutsideClick = (event) => {
      const menu = document.getElementById("branch-portal-menu");

      if (
        branchButtonRef.current &&
        !branchButtonRef.current.contains(event.target) &&
        menu &&
        !menu.contains(event.target)
      ) {
        closeBranchMenu();
      }
    };

    const reposition = () => updateBranchMenuPosition();

    document.addEventListener("mousedown", closeOnOutsideClick);
    window.addEventListener("resize", reposition);
    window.addEventListener("scroll", reposition, true);

    return () => {
      document.removeEventListener("mousedown", closeOnOutsideClick);
      window.removeEventListener("resize", reposition);
      window.removeEventListener("scroll", reposition, true);
    };
  }, [showBranchDropdown]);

  useEffect(() => {
    const enterFullscreenOnFirstInteraction = async () => {
      try {
        if (!document.fullscreenElement) {
          await document.documentElement.requestFullscreen();
        }
      } catch (error) {
        console.warn("Fullscreen requires user interaction:", error);
      } finally {
        window.removeEventListener("click", enterFullscreenOnFirstInteraction);
        window.removeEventListener("keydown", enterFullscreenOnFirstInteraction);
        window.removeEventListener("touchstart", enterFullscreenOnFirstInteraction);
      }
    };

    const handleFullscreenChange = () => {
      setIsFullscreen(Boolean(document.fullscreenElement));
    };

    window.addEventListener("click", enterFullscreenOnFirstInteraction);
    window.addEventListener("keydown", enterFullscreenOnFirstInteraction);
    window.addEventListener("touchstart", enterFullscreenOnFirstInteraction);
    document.addEventListener("fullscreenchange", handleFullscreenChange);

    return () => {
      window.removeEventListener("click", enterFullscreenOnFirstInteraction);
      window.removeEventListener("keydown", enterFullscreenOnFirstInteraction);
      window.removeEventListener("touchstart", enterFullscreenOnFirstInteraction);
      document.removeEventListener("fullscreenchange", handleFullscreenChange);
    };
  }, []);

  const validate = () => {
    const newErrors = {};

    if (isCodeLogin) {
      const code = sanitizeLoginCode(formData.login_code);

      if (!code) {
        newErrors.login_code = `A ${loginCodeLengthLabel} character login code is required`;
      } else if (code.length < loginCodeMinimumLength || code.length > loginCodeMaximumLength) {
        newErrors.login_code = loginCodeMinimumLength === loginCodeMaximumLength
          ? `Login code must be exactly ${loginCodeMaximumLength} characters`
          : `Login code must be ${loginCodeMinimumLength} to ${loginCodeMaximumLength} characters`;
      } else if (loginCodeNumericOnly && !/^\d+$/.test(code)) {
        newErrors.login_code = "Login code must contain digits only";
      }
    } else {
      if (!formData.username.trim()) {
        newErrors.username = "Username is required";
      }

      if (!formData.password) {
        newErrors.password = "Password is required";
      }
    }

    setErrors(newErrors);

    return Object.keys(newErrors).length === 0;
  };

  const handleRenewLicense = async (event) => {
    event.preventDefault();

    if (renewingLicense) return;

    if (!isValidLicenseKey(licenseKey)) {
      setLicenseError(getLicenseKeyValidationMessage(licenseKey));
      return;
    }

    try {
      setRenewingLicense(true);
      setLicenseError("");
      setLicenseSuccess("");

      const response = await renewLicenseOffline({
        license_key: normalizeLicenseKey(licenseKey),
      });

      setLicenseSuccess(
        response?.message ||
          response?.data?.message ||
          "License renewed successfully."
      );

      setLicenseKey("");

      await loadCompanyInfo();

      window.setTimeout(() => {
        setLicenseModalOpen(false);
        setLicenseSuccess("");
        setLicenseError("");
      }, 900);
    } catch (error) {
      setLicenseError(getApiMessage(error, "License renewal failed."));
    } finally {
      setRenewingLicense(false);
    }
  };

  const finalizeLogin = (data, activeBranchId = null) => {
    const { token, user: loggedUser } = data || {};

    if (!token || !loggedUser) {
      throw new Error("Invalid login response.");
    }

    const workspaceContext = data?.workspace_context || {};
    const authenticatedBranchId =
      workspaceContext?.branch_id ||
      workspaceContext?.branch?.id ||
      data?.branch_id ||
      data?.branch?.id ||
      loggedUser?.branch_id ||
      loggedUser?.branch?.id ||
      null;
    if (
      isFrontOffice &&
      pairedBranchId &&
      authenticatedBranchId &&
      String(authenticatedBranchId) !== String(pairedBranchId)
    ) {
      throw new Error("Login branch does not match this terminal's paired branch.");
    }

    const loginBranch = branches.find((branch) => String(branch.id) === String(activeBranchId))
      || branchChoices.find((branch) => String(branch.id) === String(activeBranchId))
      || workspaceContext?.branch
      || data?.branch
      || loggedUser?.branch
      || null;
    const loginCompany = workspaceContext?.company || data?.company || loggedUser?.company || null;
    const authenticatedCompanyId = workspaceContext?.company_id
      || data?.company_id
      || loginCompany?.id
      || loggedUser?.company_id
      || null;

    const updatedUser = {
      ...mergePlatformAdministratorIdentity(loggedUser, data),
      company_id: data?.platform_context_required ? null : authenticatedCompanyId,
      company: data?.platform_context_required ? null : loginCompany,
      branch_id: data?.platform_context_required ? null : authenticatedBranchId,
      branch: data?.platform_context_required ? null : loginBranch,
      selectedCompanyId: authenticatedCompanyId,
      selectedCompany: loginCompany,
      selectedBranchId: authenticatedBranchId,
      selectedBranch: loginBranch,
      active_shift:
        data?.active_shift ||
        loggedUser?.active_shift ||
        loggedUser?.active_shift_session ||
        null,
      sales_screen_layout:
        data?.sales_screen_layout ||
        loggedUser?.sales_screen_layout ||
        null,
    };

    if (isFrontOffice) {
      frontLogin(updatedUser, token);
    } else {
      adminLogin(updatedUser, token);
    }

    setCompanySelectionToken("");
    setCompanyChoices([]);
    setSelectedLoginCompanyId(null);
    window.dispatchEvent(new Event("salexa_auth_changed"));
    navigate(isFrontOffice ? "/front" : "/admin");
  };

  const handleSubmit = async (event, branchOverride = null) => {
    event?.preventDefault?.();

    if (
      isFrontOffice &&
      pairedBranchId &&
      branchOverride &&
      String(branchOverride) !== String(pairedBranchId)
    ) {
      setErrors({ branch_id: "This POS is paired to a different branch." });
      return;
    }

    const clockPassed = await verifySystemClock();

    if (!clockPassed) return;
    if (!validate()) return;
    // React state does not disable the form until the next render. A rapid
    // Enter/click pair can otherwise submit two login requests and consume the
    // server's intentional brute-force throttle allowance.
    if (loginInFlightRef.current) return;
    loginInFlightRef.current = true;

    setLoading(true);
    setErrors({});

    try {
      const activeBranchId = pairedBranchId || branchOverride || formData.branch_id || undefined;
      const loginPayload = isCodeLogin
        ? {
            login_mode: "code",
            login_code: sanitizeLoginCode(formData.login_code),
            branch_id: activeBranchId,
            workspace,
          }
        : {
            login_mode: "password",
            username: formData.username,
            password: formData.password,
            branch_id: activeBranchId,
            workspace,
          };

      const data = await loginUser(loginPayload);

      if (!isFrontOffice && data?.company_selection_required) {
        const availableCompanies = extractBackOfficeCompanyChoices(data);
        setCompanyChoices(availableCompanies);
        setCompanySelectionSearch("");
        setCompanySelectionOpen(true);
        setCompanySelectionToken(String(data?.selection_token || ""));
        setSelectedLoginCompanyId(null);
        setKeyboardOpen(false);
        setFormData((current) => ({ ...current, password: "" }));

        if (availableCompanies.length === 0 || !data?.selection_token) {
          setCompanySelectionOpen(false);
          setErrors({ submit: "The secure company selection could not be started. Sign in again." });
        }
        return;
      }

      if (data?.branch_selection_required) {
        if (isFrontOffice && pairedBranchId) {
          const pairedChoice = (Array.isArray(data.branches) ? data.branches : []).find(
            (branch) => String(branch.id) === String(pairedBranchId),
          );
          setErrors({
            branch_id: pairedChoice
              ? "The server did not accept this terminal's paired branch. Contact an administrator."
              : "Your account cannot access the branch paired to this POS.",
          });
          return;
        }
        setBranchChoices(Array.isArray(data.branches) ? data.branches : []);
        setBranchSelectionSearch("");
        setBranchSelectionOpen(true);
        setKeyboardOpen(false);
        return;
      }

      finalizeLogin(data, activeBranchId);
    } catch (err) {
      if (isLicenseError(err)) {
        setLicenseModalOpen(true);
        setLicenseError(
          getApiMessage(err, "No active license found for this company.")
        );
        setErrors({});
        return;
      }

      setErrors({
        submit:
          err?.response?.data?.message ||
          err?.message ||
          "Login failed. Please try again.",
      });
    } finally {
      loginInFlightRef.current = false;
      setLoading(false);
    }
  };

  const selectLoginBranch = async (branch) => {
    if (!branch?.id || loading) return;
    if (pairedBranchId && String(branch.id) !== String(pairedBranchId)) {
      setErrors({ branch_id: "This POS is locked to its paired branch." });
      return;
    }
    setFormData((prev) => ({ ...prev, branch_id: branch.id }));
    setBranchSelectionOpen(false);

    if (!isFrontOffice && companySelectionToken && selectedLoginCompanyId) {
      setLoading(true);
      setErrors({});
      try {
        const data = await selectBackOfficeCompany({
          selectionToken: companySelectionToken,
          companyId: selectedLoginCompanyId,
          branchId: branch.id,
        });
        finalizeLogin(data, branch.id);
      } catch (error) {
        setCompanySelectionToken("");
        setSelectedLoginCompanyId(null);
        setErrors({ submit: getApiMessage(error, "The secure company selection expired. Sign in again.") });
      } finally {
        setLoading(false);
      }
      return;
    }

    await handleSubmit(null, branch.id);
  };

  const selectLoginCompany = async (company) => {
    if (!company?.id || !companySelectionToken || loading) return;
    setSelectedLoginCompanyId(company.id);
    setCompanySelectionOpen(false);
    setErrors({});
    setLoading(true);

    try {
      const data = await selectBackOfficeCompany({
        selectionToken: companySelectionToken,
        companyId: company.id,
      });

      if (data?.branch_selection_required) {
        setBranchChoices(Array.isArray(data.branches) ? data.branches : []);
        setBranchSelectionSearch("");
        setBranchSelectionOpen(true);
        return;
      }

      finalizeLogin(data);
    } catch (error) {
      setCompanySelectionToken("");
      setSelectedLoginCompanyId(null);
      setErrors({ submit: getApiMessage(error, "The secure company selection expired. Sign in again.") });
    } finally {
      setLoading(false);
    }
  };

  const cancelCompanySelection = () => {
    setCompanySelectionOpen(false);
    setCompanySelectionToken("");
    setCompanyChoices([]);
    setCompanySelectionSearch("");
    setSelectedLoginCompanyId(null);
    setFormData((current) => ({ ...current, password: "" }));
    window.setTimeout(() => passwordRef.current?.focus?.({ preventScroll: true }), 40);
  };

  const loginDisabled =
    loading ||
    clockChecking ||
    (isFrontOffice && (!localTillConnected || !pairedTill?.till_token)) ||
    !clockOk;

  const branchMenu = showBranchDropdown ? (
    <div
      id="branch-portal-menu"
      style={branchMenuStyle}
      className="border border-[#90A2A1] bg-white shadow-2xl"
    >
      <div className="flex h-9 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3">
        <span className="text-[11px] font-black uppercase text-white">
          Select Branch
        </span>
        <button
          type="button"
          onClick={closeBranchMenu}
          className="h-6 bg-white/10 px-2 text-[10px] font-black text-white hover:bg-white/20"
        >
          CLOSE
        </button>
      </div>

      <div className="relative">
        <input
          ref={branchSearchRef}
          type="text"
          value={branchSearch}
          onChange={(event) => setBranchSearch(event.target.value)}
          placeholder="Search branch"
          className="h-9 w-full border-b border-[#ACB8B8] px-3 pr-11 text-[12px] outline-none focus:border-[#069495]"
        />
        <button
          type="button"
          onClick={() => openKeyboardFor(branchSearchRef.current, "Branch Search")}
          className="absolute right-0 top-0 flex h-9 w-10 items-center justify-center border-l border-[#ACB8B8] bg-[#F4F7F9] text-[#004141] hover:bg-[#E8F7F5]"
        >
          <Keyboard className="h-4 w-4" />
        </button>
      </div>

      <div className="max-h-[225px] overflow-y-auto">
        {filteredBranches.length > 0 ? (
          filteredBranches.map((branch) => (
            <button
              type="button"
              key={branch.id}
              onClick={() => {
                setFormData((prev) => ({
                  ...prev,
                  branch_id: branch.id,
                }));
                setErrors((prev) => ({
                  ...prev,
                  branch_id: "",
                }));
                closeBranchMenu();
              }}
              className={`w-full border-b border-[#EFF2F2] px-3 py-2 text-left hover:bg-[#E8F7F5] ${
                String(formData.branch_id) === String(branch.id)
                  ? "bg-[#E8F7F5] text-[#006869]"
                  : "text-[#003636]"
              }`}
            >
              <div className="truncate text-[12px] font-semibold">
                {branch.branch_name || branch.name || "Unnamed Branch"}
              </div>
              <div className="truncate text-[10px] text-[#637877]">
                {branch.branch_code || branch.code || "No code"}
              </div>
            </button>
          ))
        ) : (
          <div className="px-3 py-6 text-center text-[12px] text-[#637877]">
            No branches found.
          </div>
        )}
      </div>
    </div>
  ) : null;

  const filteredBranchChoices = branchChoices.filter((branch) => {
    const query = branchSelectionSearch.trim().toLowerCase();
    if (!query) return true;
    return [branch.branch_name, branch.name, branch.branch_code, branch.code, branch.branch_city]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(query));
  });

  const filteredCompanyChoices = companyChoices.filter((company) => {
    const query = companySelectionSearch.trim().toLowerCase();
    if (!query) return true;

    return [
      backOfficeCompanyName(company),
      backOfficeCompanyLocation(company),
      company?.status,
    ].some((value) => String(value || "").toLowerCase().includes(query));
  });

  // Company and branch pickers share the plain sign-in card look: white card,
  // neutral list rows, teal only on focus.
  const branchSelectionModal = branchSelectionOpen ? (
    <div className="fixed inset-0 z-[1000001] flex bg-black/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="branch-selection-title">
      <div className="m-auto flex max-h-full w-full max-w-[520px] flex-col border border-[#E3E3E3] bg-white text-[#1C1C1C] shadow-[0_24px_64px_rgba(0,0,0,.35)]">
        <div className="shrink-0 p-6 pb-4">
          <div className="flex items-start justify-between gap-4">
            <img src="/servenest-static/servenest-logo.svg" alt="ServeNest" className="h-9 w-[140px] object-contain object-left" />
            <button type="button" onClick={() => setBranchSelectionOpen(false)} className="-mr-2 -mt-2 grid h-9 w-9 place-items-center text-[#6B6B6B] hover:bg-[#F2F2F2] hover:text-[#1C1C1C]" aria-label="Close branch selection"><X className="h-5 w-5" /></button>
          </div>
          <h2 id="branch-selection-title" className="mt-6 text-[20px] font-semibold">Choose a branch</h2>
          <p className="mt-1 text-[13px] text-[#6B6B6B]">Select where you are working.</p>
          <label className="relative mt-4 block">
            <span className="sr-only">Search branches</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#9A9A9A]" />
            <input
              type="search"
              value={branchSelectionSearch}
              onChange={(event) => setBranchSelectionSearch(event.target.value)}
              placeholder="Search branch name, code or city"
              autoFocus
              className="h-11 w-full border border-[#CFCFCF] bg-white pl-10 pr-3 text-[14px] text-[#1C1C1C] outline-none placeholder:text-[#9A9A9A] focus:border-[#069494] focus:shadow-[0_0_0_1px_#069494] focus-visible:outline-none"
            />
          </label>
        </div>
        <div className="min-h-0 overflow-y-auto border-t border-[#EDEDED]">
          {filteredBranchChoices.map((branch) => (
            <button
              key={branch.id}
              type="button"
              disabled={loading}
              onClick={() => selectLoginBranch(branch)}
              className="group flex w-full items-center justify-between gap-4 border-b border-[#EDEDED] px-6 py-4 text-left hover:bg-[#F7F7F7] focus-visible:bg-[#F7F7F7] focus-visible:shadow-[inset_3px_0_0_#069494] focus-visible:outline-none disabled:opacity-50"
            >
              <span className="min-w-0">
                <span className="block truncate text-[15px] font-semibold">{branch.branch_name || branch.name || "Unnamed Branch"}</span>
                <span className="mt-0.5 block truncate text-[12px] text-[#6B6B6B]">{branch.branch_code || branch.code || "Branch"}</span>
              </span>
              <ChevronRight className="h-5 w-5 shrink-0 text-[#9A9A9A] transition group-hover:translate-x-0.5 group-hover:text-[#1C1C1C]" />
            </button>
          ))}
          {filteredBranchChoices.length === 0 && (
            <p className="px-6 py-10 text-center text-[13px] text-[#6B6B6B]">No matching branches</p>
          )}
        </div>
      </div>
    </div>
  ) : null;

  const companySelectionModal = companySelectionOpen ? (
    <div className="fixed inset-0 z-[1000002] flex bg-black/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="company-selection-title">
      <div className="m-auto flex max-h-full w-full max-w-[520px] flex-col border border-[#E3E3E3] bg-white text-[#1C1C1C] shadow-[0_24px_64px_rgba(0,0,0,.35)]">
        <div className="shrink-0 p-6 pb-4">
          <div className="flex items-start justify-between gap-4">
            <img src="/servenest-static/servenest-logo.svg" alt="ServeNest" className="h-9 w-[140px] object-contain object-left" />
            <button type="button" onClick={cancelCompanySelection} className="-mr-2 -mt-2 grid h-9 w-9 place-items-center text-[#6B6B6B] hover:bg-[#F2F2F2] hover:text-[#1C1C1C]" aria-label="Cancel company selection"><X className="h-5 w-5" /></button>
          </div>
          <h2 id="company-selection-title" className="mt-6 text-[20px] font-semibold">Choose a company</h2>
          <p className="mt-1 text-[13px] text-[#6B6B6B]">You can manage any company on the platform. Pick one for this session.</p>
          <label className="relative mt-4 block">
            <span className="sr-only">Search companies</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#9A9A9A]" />
            <input
              type="search"
              value={companySelectionSearch}
              onChange={(event) => setCompanySelectionSearch(event.target.value)}
              placeholder="Search by company or location"
              autoFocus
              className="h-11 w-full border border-[#CFCFCF] bg-white pl-10 pr-3 text-[14px] text-[#1C1C1C] outline-none placeholder:text-[#9A9A9A] focus:border-[#069494] focus:shadow-[0_0_0_1px_#069494] focus-visible:outline-none"
            />
          </label>
        </div>

        <div className="min-h-0 overflow-y-auto border-t border-[#EDEDED]">
          {filteredCompanyChoices.map((company) => (
            <button
              key={company.id}
              type="button"
              disabled={loading}
              onClick={() => selectLoginCompany(company)}
              className="group flex w-full items-center gap-4 border-b border-[#EDEDED] px-6 py-4 text-left hover:bg-[#F7F7F7] focus-visible:bg-[#F7F7F7] focus-visible:shadow-[inset_3px_0_0_#069494] focus-visible:outline-none disabled:opacity-50"
            >
              <span className="grid h-11 w-11 shrink-0 place-items-center overflow-hidden border border-[#E3E3E3] bg-[#F7F7F7] text-[#6B6B6B]">
                {company?.logo ? (
                  <img src={publicAssetUrl(company.logo_path || company.logo || company.logo_url)} alt="" className="h-full w-full object-contain p-1.5" />
                ) : (
                  <Building2 className="h-5 w-5" />
                )}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[15px] font-semibold">{backOfficeCompanyName(company)}</span>
                <span className="mt-0.5 flex items-center gap-1.5 truncate text-[12px] text-[#6B6B6B]"><Globe2 className="h-3.5 w-3.5 shrink-0" /> {backOfficeCompanyLocation(company)}</span>
              </span>
              <ChevronRight className="h-5 w-5 shrink-0 text-[#9A9A9A] transition group-hover:translate-x-0.5 group-hover:text-[#1C1C1C]" />
            </button>
          ))}
          {filteredCompanyChoices.length === 0 && (
            <p className="px-6 py-10 text-center text-[13px] text-[#6B6B6B]">No matching companies</p>
          )}
        </div>
      </div>
    </div>
  ) : null;

  const pairedBranchName = pairedBranch?.branch_name || pairedBranch?.name || pairedTill?.shop_name || pairedBranchId;
  const pairedBranchNotice = isFrontOffice && pairedBranchId ? (
    <div className="border border-[#00BABA]/45 bg-[#003636]/80 px-3 py-2 text-[9px] uppercase tracking-[0.12em] text-white/65">
      Paired shop: <strong className="text-white">{pairedBranchName}</strong>
    </div>
  ) : null;

  if (!isFrontOffice) {
    const openBackOfficeLogin = () => {
      if (!backOfficeLoginOpen) setBackOfficeLoginOpen(true);
    };

    const closeBackOfficeLogin = () => {
      if (loading) return;
      setBackOfficeLoginOpen(false);
      setKeyboardOpen(false);
      setErrors({});
    };

    // One on-screen keyboard serves both credentials. It types into the field
    // focused last, which is the username until the password field is used.
    const rememberKeyboardField = (element, label) => {
      keyboardTargetRef.current = element;
      setKeyboardTargetName(label);
    };

    const toggleBackOfficeKeyboard = () => {
      if (keyboardOpen) {
        setKeyboardOpen(false);
        return;
      }

      const passwordActive = keyboardTargetRef.current === passwordRef.current;
      openKeyboardFor(
        passwordActive ? passwordRef.current : usernameRef.current,
        passwordActive ? "Password" : "Username",
      );
    };

    return (
      <div
        className={`${embedded ? "h-full" : "h-screen"} flex min-h-[620px] flex-col overflow-hidden bg-[#040808] font-sans text-white`}
        onClick={openBackOfficeLogin}
        role="presentation"
      >
        <header className="relative z-10 flex h-[64px] shrink-0 items-center justify-between border-b border-white/10 bg-[#050B0A]/95 px-6">
          <img src="/servenest-static/servenest-logo.svg" alt="ServeNest" className="h-10 w-[158px] object-contain object-left" />
          <span className="text-[9px] font-bold uppercase tracking-[0.16em] text-white/35">V {buildVersion}</span>
        </header>

        <main
          className="relative flex min-h-0 flex-1 cursor-pointer items-center justify-center overflow-hidden bg-cover bg-center"
          style={{ backgroundImage: "linear-gradient(rgba(3,7,7,.42),rgba(3,7,7,.58)),url('/servenest-static/servenest-backoffice-login.png')" }}
          aria-label="Open Back Office sign in"
        >
          <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,rgba(3,8,8,.16),rgba(3,8,8,.04)_46%,rgba(3,8,8,.65))]" aria-hidden="true" />
          <button
            type="button"
            onClick={(event) => { event.stopPropagation(); openBackOfficeLogin(); }}
            className="group relative z-10 flex min-w-[320px] flex-col items-center px-8 py-8 text-white outline-none"
            aria-label="Enter Back Office"
          >
            <span className="grid h-16 w-16 place-items-center border border-white/45 bg-black/35 text-white transition group-hover:border-white group-hover:bg-black/50">
              <Building2 className="h-7 w-7" />
            </span>
            <span className="mt-5 text-[10px] font-black uppercase tracking-[0.5em] text-white/70">Manage</span>
            <span className="mt-2 text-[42px] font-light uppercase tracking-[-0.035em] sm:text-[54px]">Back Office</span>
            <span className="mt-5 h-12 w-px bg-gradient-to-b from-white/70 to-transparent" aria-hidden="true" />
          </button>
        </main>

        <footer className="relative z-10 h-[30px] shrink-0 border-t border-white/10 bg-[#050B0A]" />

        {/* The company/branch pickers replace the sign-in card rather than stacking over it. */}
        {backOfficeLoginOpen && !companySelectionOpen && !branchSelectionOpen && (
          <div
            className="fixed inset-0 z-[1000001] flex flex-col bg-black/60 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-label="Back Office sign in"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="flex min-h-0 flex-1 overflow-y-auto p-4">
              <form
                onSubmit={handleSubmit}
                autoComplete="off"
                className="m-auto w-full max-w-[400px] border border-[#E3E3E3] bg-white p-6 text-[#1C1C1C] shadow-[0_24px_64px_rgba(0,0,0,.35)]"
                aria-label="Back Office credentials"
              >
                <div className="flex items-start justify-between gap-4">
                  <img src="/servenest-static/servenest-logo.svg" alt="ServeNest" className="h-9 w-[140px] object-contain object-left" />
                  <button type="button" onClick={closeBackOfficeLogin} disabled={loading} className="-mr-2 -mt-2 grid h-9 w-9 place-items-center text-[#6B6B6B] hover:bg-[#F2F2F2] hover:text-[#1C1C1C] disabled:opacity-40" aria-label="Close sign in"><X className="h-5 w-5" /></button>
                </div>

                <h2 className="mt-6 text-[20px] font-semibold">Sign in to Back Office</h2>

                <label htmlFor="back-office-username" className="mb-1.5 mt-6 block text-[13px] font-medium text-[#3D3D3D]">Username</label>
                <input
                  id="back-office-username"
                  ref={usernameRef}
                  type="text"
                  autoComplete="off"
                  autoCapitalize="none"
                  spellCheck={false}
                  value={formData.username}
                  onFocus={() => rememberKeyboardField(usernameRef.current, "Username")}
                  onChange={(event) => {
                    setFormData((current) => ({ ...current, username: event.target.value }));
                    setErrors((current) => ({ ...current, username: "", submit: "" }));
                  }}
                  aria-invalid={Boolean(errors.username)}
                  className={`h-11 w-full border bg-white px-3 text-[14px] text-[#1C1C1C] outline-none focus:border-[#069494] focus:shadow-[0_0_0_1px_#069494] focus-visible:outline-none ${errors.username ? "border-[#D92D20]" : "border-[#CFCFCF]"}`}
                />

                <label htmlFor="back-office-password" className="mb-1.5 mt-4 block text-[13px] font-medium text-[#3D3D3D]">Password</label>
                <div className="relative">
                  <input
                    id="back-office-password"
                    ref={passwordRef}
                    type={showPassword ? "text" : "password"}
                    autoComplete="new-password"
                    value={formData.password}
                    onFocus={() => rememberKeyboardField(passwordRef.current, "Password")}
                    onChange={(event) => {
                      setFormData((current) => ({ ...current, password: event.target.value }));
                      setErrors((current) => ({ ...current, password: "", submit: "" }));
                    }}
                    aria-invalid={Boolean(errors.password)}
                    className={`h-11 w-full border bg-white pl-3 pr-16 text-[14px] text-[#1C1C1C] outline-none focus:border-[#069494] focus:shadow-[0_0_0_1px_#069494] focus-visible:outline-none ${errors.password ? "border-[#D92D20]" : "border-[#CFCFCF]"}`}
                  />
                  <button type="button" onClick={() => setShowPassword((current) => !current)} className="absolute inset-y-0 right-0 px-3 text-[12px] font-medium text-[#555555] hover:text-[#1C1C1C]" aria-label={showPassword ? "Hide password" : "Show password"}>{showPassword ? "Hide" : "Show"}</button>
                </div>

                {(errors.username || errors.password || errors.branch_id || errors.submit || clockError) && (
                  <div className="mt-4 border border-[#F1C4BC] bg-[#FDF2F0] px-3 py-2 text-[13px] text-[#B42318]" role="alert" aria-live="polite">{errors.submit || errors.username || errors.password || errors.branch_id || clockError}</div>
                )}

                <button type="submit" disabled={loginDisabled} className="mt-6 flex h-11 w-full items-center justify-center gap-2 bg-[#069494] text-[14px] font-semibold text-white hover:bg-[#067C7C] disabled:cursor-not-allowed disabled:opacity-50">
                  {loading ? <><LoaderCircle className="h-4 w-4 animate-spin" /> Signing in…</> : "Sign in"}
                </button>

                <button
                  type="button"
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={toggleBackOfficeKeyboard}
                  aria-pressed={keyboardOpen}
                  aria-controls="back-office-keyboard"
                  className={`mt-3 flex h-10 w-full items-center justify-center gap-2 border text-[13px] font-medium text-[#3D3D3D] hover:bg-[#F2F2F2] ${keyboardOpen ? "border-[#9A9A9A] bg-[#F2F2F2]" : "border-[#CFCFCF] bg-white"}`}
                >
                  <Keyboard className="h-4 w-4" />
                  {keyboardOpen ? "Hide keyboard" : "Show keyboard"}
                </button>
              </form>
            </div>

            {keyboardOpen && (
              <WirelessKeyboard
                id="back-office-keyboard"
                variant="docked"
                targetName={keyboardTargetName}
                targetRef={keyboardTargetRef}
                onClose={() => setKeyboardOpen(false)}
                onEnter={handleSubmit}
                enterLabel={loading ? "Wait" : "Sign in"}
              />
            )}
          </div>
        )}

        {loading && !companySelectionOpen && !branchSelectionOpen && (
          <div className="fixed inset-0 z-[1000003] grid place-items-center bg-black/60 p-5 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="ServeNest secure sign in">
            <div className="w-full max-w-[360px] border border-[#E3E3E3] bg-white px-8 py-8 text-center text-[#1C1C1C] shadow-[0_24px_64px_rgba(0,0,0,.35)]">
              <img src="/servenest-static/servenest-logo.svg" alt="ServeNest" className="mx-auto h-12 w-[180px] object-contain" />
              <LoaderCircle className="mx-auto mt-6 h-7 w-7 animate-spin text-[#069494]" />
              <p className="mt-4 text-[14px] text-[#3D3D3D]">{selectedLoginCompanyId ? "Opening the selected company workspace…" : "Verifying your ServeNest account…"}</p>
            </div>
          </div>
        )}

        {companySelectionModal}
        {branchSelectionModal}
        {licenseModalOpen && (
          <LicenseRenewalModal
            licenseKey={licenseKey}
            setLicenseKey={setLicenseKey}
            licenseKeyRef={licenseKeyRef}
            openKeyboardFor={openKeyboardFor}
            licenseError={licenseError}
            setLicenseError={setLicenseError}
            licenseSuccess={licenseSuccess}
            setLicenseSuccess={setLicenseSuccess}
            renewingLicense={renewingLicense}
            onSubmit={handleRenewLicense}
            onClose={() => { setLicenseModalOpen(false); setLicenseError(""); setLicenseSuccess(""); setLicenseKey(""); }}
          />
        )}
      </div>
    );
  }

  if (embedded) {
    const clearLogin = () => {
      setFormData({
        company_code: formData.company_code,
        username: "",
        password: "",
        login_code: "",
        branch_id: pairedBranchId,
      });
      setErrors({});
      setBranchSearch("");
    };

    return (
      <div className="embedded-login flex h-full flex-col bg-[#123C36] font-sans text-white" style={{ backgroundImage: "radial-gradient(circle at 15% 20%,rgba(6,148,148,.42),transparent 34%),linear-gradient(145deg,#123C36,#071F1C)" }}>
        <div className="flex h-12 shrink-0 items-center border-b border-[#006161] bg-[#003030]/95 px-4 text-white">
          <img src={loginLogo} alt={loginLogoAlt} className="h-8 w-[118px] object-contain object-left" />
          <div className="ml-2">
            <div className="text-[11px] font-bold uppercase tracking-[0.18em]">{isFrontOffice ? "Front Office" : "Back Office"}</div>
            <div className="text-[8px] uppercase tracking-[0.15em] text-white/45">Secure sign in</div>
          </div>

          {isFrontOffice && (loginMethodCanSwitch ? (
            <div className="ml-auto mr-3 flex h-8 border border-white/20 bg-black/20" aria-label="Allowed POS login methods">
              <button type="button" onClick={() => chooseLoginMode("code")} aria-pressed={isCodeLogin} className={`px-3 text-[9px] font-bold uppercase tracking-wide ${isCodeLogin ? "bg-[#067C7C] text-white" : "text-white/65 hover:bg-white/10"}`}>Code</button>
              <button type="button" onClick={() => chooseLoginMode("password")} aria-pressed={!isCodeLogin} className={`border-l border-white/20 px-3 text-[9px] font-bold uppercase tracking-wide ${!isCodeLogin ? "bg-[#067C7C] text-white" : "text-white/65 hover:bg-white/10"}`}>Username</button>
            </div>
          ) : (
            <div className="ml-auto mr-3 border border-white/20 bg-black/20 px-3 py-1.5 text-[9px] font-bold uppercase tracking-wide text-white/75" title="Configured centrally in Back Office">
              {loginModeLoading ? "Loading login policy" : isCodeLogin ? "Operator code" : "Username + password"}
            </div>
          ))}

          <button
            type="button"
            onClick={onClose}
            className={`${isFrontOffice ? "" : "ml-auto"} flex h-8 w-8 items-center justify-center border border-white/20 bg-white/5 text-white/70 hover:border-red-400 hover:bg-[#B42318] hover:text-white`}
            aria-label="Close login"
            title="Close"
          ><X className="h-4 w-4" /></button>
        </div>

        <div className="flex min-h-0 flex-1 flex-col">
          <div className="flex min-h-0 flex-1 justify-end p-4">
        <form onSubmit={handleSubmit} className="w-[430px] overflow-auto border border-white/20 bg-[#003030]/90 p-5 shadow-[0_18px_55px_rgba(0,0,0,.45)] backdrop-blur-md">
          <div className="mb-4 flex items-center justify-between border-b border-white/15 pb-3">
            <div>
              <div className="text-[9px] font-bold uppercase tracking-[0.2em] text-[#067C7C]">{isCodeLogin ? "Operator access" : "Account access"}</div>
              <h2 className="mt-1 text-[18px] font-semibold tracking-[-0.02em] text-white">{isCodeLogin ? "Enter operator code" : "Enter credentials"}</h2>
            </div>
            <LockKeyhole className="h-5 w-5 text-[#067C7C]" />
          </div>

          {pairedBranchNotice && <div className="mb-3">{pairedBranchNotice}</div>}
          {isCodeLogin ? (
            <div className="flex min-h-[390px] flex-col">
              <input
                ref={loginCodeRef}
                type="password"
                inputMode={loginCodeNumericOnly ? "numeric" : "text"}
                maxLength={loginCodeMaximumLength}
                autoFocus
                value={formData.login_code}
                onChange={(event) => setFormData((prev) => ({ ...prev, login_code: sanitizeLoginCode(event.target.value) }))}
                aria-label="Operator code"
                placeholder={Array.from({ length: loginCodeMinimumLength }, () => "—").join("  ")}
                className={`mb-3 h-14 w-full border bg-[#003636]/95 px-3 text-center text-[25px] font-bold tracking-[0.55em] text-white outline-none focus:border-[#00BABA] ${errors.login_code ? "border-red-500" : "border-white/25"}`}
              />
              <div className="mb-2 text-center text-[8px] font-bold uppercase tracking-[0.18em] text-white/45">
                {String(formData.login_code || "").length} / {loginCodeLengthLabel} {loginCodeNumericOnly ? "digits" : "characters"} entered
              </div>
              <PlainLoginCodeKeypad
                value={formData.login_code}
                numericOnly={loginCodeNumericOnly}
                disabled={loading}
                enterDisabled={loginDisabled || !loginCodeReady}
                onDigit={(digit) => {
                  setFormData((prev) => ({ ...prev, login_code: sanitizeLoginCode(`${prev.login_code || ""}${digit}`) }));
                  setErrors((prev) => ({ ...prev, login_code: "", submit: "" }));
                }}
                onBack={() => setFormData((prev) => ({ ...prev, login_code: String(prev.login_code || "").slice(0, -1) }))}
                onClear={() => setFormData((prev) => ({ ...prev, login_code: "" }))}
                onEnter={handleSubmit}
              />
            </div>
          ) : (
            <div className="space-y-3">
              <div>
                <label className="mb-1 block text-[9px] font-bold uppercase tracking-[0.15em] text-white/55">Username</label>
                <KeyboardInputShell inputRef={usernameRef} onKeyboard={() => openKeyboardFor(usernameRef.current, "Username")}>
                  <input
                    ref={usernameRef}
                    type="text"
                    autoFocus
                    value={formData.username}
                    onFocus={() => openKeyboardFor(usernameRef.current, "Username")}
                    onClick={() => openKeyboardFor(usernameRef.current, "Username")}
                    onChange={(event) => setFormData((prev) => ({ ...prev, username: event.target.value }))}
                    placeholder="USERNAME"
                    className={`login-input h-11 pr-12 text-[13px] ${errors.username ? "border-red-500" : "border-[#ACB8B8]"}`}
                  />
                </KeyboardInputShell>
              </div>

              <div>
                <label className="mb-1 block text-[9px] font-bold uppercase tracking-[0.15em] text-white/55">Password</label>
                <div className="relative">
                  <KeyboardInputShell inputRef={passwordRef} onKeyboard={() => openKeyboardFor(passwordRef.current, "Password")} rightOffset="right-[50px]">
                    <input
                      ref={passwordRef}
                      type={showPassword ? "text" : "password"}
                      value={formData.password}
                      onFocus={() => openKeyboardFor(passwordRef.current, "Password")}
                      onClick={() => openKeyboardFor(passwordRef.current, "Password")}
                      onChange={(event) => setFormData((prev) => ({ ...prev, password: event.target.value }))}
                      placeholder="PASSWORD"
                      className={`login-input h-11 pr-[92px] text-[13px] ${errors.password ? "border-red-500" : "border-[#ACB8B8]"}`}
                    />
                  </KeyboardInputShell>
                  <button type="button" onClick={() => setShowPassword((current) => !current)} className="absolute right-0 top-0 h-11 w-[50px] border-l border-white/20 bg-white/10 text-[8px] font-bold uppercase text-white">
                    {showPassword ? "Hide" : "Show"}
                  </button>
                </div>
              </div>

              <div className="border border-white/10 bg-black/15 px-3 py-2 text-[9px] uppercase tracking-[0.12em] text-white/45">
                Use the keyboard controls below to continue.
              </div>
            </div>
          )}

          {(errors.login_code || errors.username || errors.password || errors.branch_id || errors.submit || clockError) && (
            <div className="mt-3 whitespace-pre-wrap border border-[#FDA29B] bg-[#FEF3F2] px-2 py-1.5 text-[10px] font-semibold text-[#B42318]">
              {errors.submit || errors.login_code || errors.username || errors.password || errors.branch_id || clockError}
            </div>
          )}
        </form>
          </div>

          {!isCodeLogin && keyboardOpen && (
            <WirelessKeyboard
              inline
              targetName={keyboardTargetName}
              targetRef={keyboardTargetRef}
              onClose={() => setKeyboardOpen(false)}
              onEnter={handleSubmit}
              onClear={clearLogin}
              enterLabel={loading ? "WAIT" : "LOGIN"}
            />
          )}
        </div>

        <div className="flex h-8 shrink-0 items-center justify-between border-t border-white/15 bg-[#003030]/95 px-4 text-[8px] font-bold uppercase tracking-[0.12em] text-white/50">
          <span>{isFrontOffice ? (localTillConnected ? localTillDisplay : "Till not connected") : "Secure back office"}</span>
          <span className={clockOk ? "text-[#008383]" : "text-[#B45309]"}>{clockChecking ? "Checking" : clockOk ? "Ready" : "Clock blocked"}</span>
        </div>

        {showBranchDropdown && createPortal(branchMenu, document.body)}
        {branchSelectionModal}
        {licenseModalOpen && (
          <LicenseRenewalModal
            licenseKey={licenseKey}
            setLicenseKey={setLicenseKey}
            licenseKeyRef={licenseKeyRef}
            openKeyboardFor={openKeyboardFor}
            licenseError={licenseError}
            setLicenseError={setLicenseError}
            licenseSuccess={licenseSuccess}
            setLicenseSuccess={setLicenseSuccess}
            renewingLicense={renewingLicense}
            onSubmit={handleRenewLicense}
            onClose={() => { setLicenseModalOpen(false); setLicenseError(""); setLicenseSuccess(""); setLicenseKey(""); }}
          />
        )}
        <style>{`
          .embedded-login .login-input { width:100%; background:rgba(0,54,54,.96); color:white; padding:0 10px; border-width:1px; border-color:rgba(255,255,255,.25); outline:none; }
          .embedded-login .login-input::placeholder { color:rgba(255,255,255,.4); }
          .embedded-login .login-input:focus { border-color:#00BABA; box-shadow:inset 0 0 0 1px #00BABA; }
          button,input,textarea { border-radius:0 !important; }
        `}</style>
      </div>
    );
  }

  // Front Office sign-in: one plain white card, like Back Office. The shop and
  // till are named in the card, and status only appears when it blocks sign-in.
  const tillNotice = isFrontOffice && !localTillConnected
    ? "This till is not connected. Use Device Pairing to connect it."
    : "";
  const signInError = errors.submit || errors.login_code || errors.username || errors.password || errors.branch_id || clockError || tillNotice;
  const signInPlace = [pairedBranchName, localTillConnected ? localTillDisplay : null].filter(Boolean).join(" · ");
  const signInErrorBox = signInError ? (
    <div className="mt-4 whitespace-pre-wrap border border-[#F1C4BC] bg-[#FDF2F0] px-3 py-2 text-[13px] text-[#B42318]" role="alert" aria-live="polite">
      {signInError}
    </div>
  ) : null;

  // One on-screen keyboard serves both credentials and types into the field
  // focused last, as on the Back Office sign-in.
  const rememberFrontOfficeKeyboardField = (element, label) => {
    keyboardTargetRef.current = element;
    setKeyboardTargetName(label);
  };

  const toggleFrontOfficeKeyboard = () => {
    if (keyboardOpen) {
      setKeyboardOpen(false);
      return;
    }

    const passwordActive = keyboardTargetRef.current === passwordRef.current;
    openKeyboardFor(
      passwordActive ? passwordRef.current : usernameRef.current,
      passwordActive ? "Password" : "Username",
    );
  };

  return (
    <div className={`${embedded ? "h-full" : "h-screen"} servenest-login-shell flex flex-col overflow-hidden bg-[#F4F6F6] font-sans text-[#1C1C1C]`}>
      <main className="flex min-h-0 flex-1 overflow-y-auto p-4">
        <form
          onSubmit={handleSubmit}
          autoComplete="off"
          aria-label="Front Office sign in"
          className="m-auto w-full max-w-[440px] border border-[#E3E3E3] bg-white p-6 shadow-[0_12px_40px_rgba(0,0,0,.08)]"
        >
          <img src={loginLogo} alt={loginLogoAlt} className="h-9 w-[140px] object-contain object-left" />

          <h2 className="mt-6 text-[20px] font-semibold">Sign in</h2>
          {signInPlace && <p className="mt-1 text-[13px] text-[#6B6B6B]">{signInPlace}</p>}

          {loginMethodCanSwitch && !loginModeLoading && (
            <div className="mt-5 grid grid-cols-2 border border-[#CFCFCF]" role="group" aria-label="Allowed POS login methods">
              <button
                type="button"
                onClick={() => chooseLoginMode("code")}
                aria-pressed={isCodeLogin}
                className={`h-11 text-[14px] font-medium ${isCodeLogin ? "bg-[#069494] text-white" : "bg-white text-[#3D3D3D] hover:bg-[#F2F2F2]"}`}
              >
                Code
              </button>
              <button
                type="button"
                onClick={() => chooseLoginMode("password")}
                aria-pressed={!isCodeLogin}
                className={`h-11 border-l border-[#CFCFCF] text-[14px] font-medium ${!isCodeLogin ? "bg-[#069494] text-white" : "bg-white text-[#3D3D3D] hover:bg-[#F2F2F2]"}`}
              >
                Username + password
              </button>
            </div>
          )}

          {loginModeLoading ? (
            <div className="mt-8 flex h-40 items-center justify-center gap-2 text-[13px] text-[#6B6B6B]">
              <LoaderCircle className="h-5 w-5 animate-spin text-[#069494]" /> Loading…
            </div>
          ) : isCodeLogin ? (
            <>
              <input
                ref={loginCodeRef}
                type="password"
                // Keep the browser's password manager away from operator PINs:
                // a value autofilled on load can show dots that never reach React state.
                autoComplete="new-password"
                inputMode={loginCodeNumericOnly ? "numeric" : "text"}
                maxLength={loginCodeMaximumLength}
                value={formData.login_code}
                onChange={(event) =>
                  setFormData((prev) => ({
                    ...prev,
                    login_code: sanitizeLoginCode(event.target.value),
                  }))
                }
                aria-label="Operator code"
                placeholder={Array.from({ length: loginCodeMinimumLength }, () => "—").join("  ")}
                className={`mt-5 h-14 w-full border bg-white px-3 text-center text-[26px] font-semibold tracking-[0.5em] text-[#1C1C1C] outline-none placeholder:text-[#C4C4C4] focus:border-[#069494] focus:shadow-[0_0_0_1px_#069494] focus-visible:outline-none ${
                  errors.login_code ? "border-[#D92D20]" : "border-[#CFCFCF]"
                }`}
              />
              {signInErrorBox}
              <PlainLoginCodeKeypad
                value={formData.login_code}
                numericOnly={loginCodeNumericOnly}
                disabled={loading}
                enterDisabled={loginDisabled || !loginCodeReady}
                onDigit={(digit) => {
                  setFormData((prev) => ({
                    ...prev,
                    login_code: sanitizeLoginCode(`${prev.login_code || ""}${digit}`),
                  }));
                  setErrors((prev) => ({ ...prev, login_code: "", submit: "" }));
                }}
                onBack={() => {
                  setFormData((prev) => ({
                    ...prev,
                    login_code: String(prev.login_code || "").slice(0, -1),
                  }));
                }}
                onClear={() => setFormData((prev) => ({ ...prev, login_code: "" }))}
                onEnter={handleSubmit}
              />
            </>
          ) : (
            <>
              <label htmlFor="front-office-username" className="mb-1.5 mt-6 block text-[13px] font-medium text-[#3D3D3D]">Username</label>
              <input
                id="front-office-username"
                ref={usernameRef}
                type="text"
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                value={formData.username}
                onFocus={() => rememberFrontOfficeKeyboardField(usernameRef.current, "Username")}
                onChange={(event) => {
                  setFormData((prev) => ({ ...prev, username: event.target.value }));
                  setErrors((prev) => ({ ...prev, username: "", submit: "" }));
                }}
                aria-invalid={Boolean(errors.username)}
                className={`h-12 w-full border bg-white px-3 text-[15px] text-[#1C1C1C] outline-none focus:border-[#069494] focus:shadow-[0_0_0_1px_#069494] focus-visible:outline-none ${errors.username ? "border-[#D92D20]" : "border-[#CFCFCF]"}`}
              />

              <label htmlFor="front-office-password" className="mb-1.5 mt-4 block text-[13px] font-medium text-[#3D3D3D]">Password</label>
              <div className="relative">
                <input
                  id="front-office-password"
                  ref={passwordRef}
                  type={showPassword ? "text" : "password"}
                  autoComplete="new-password"
                  value={formData.password}
                  onFocus={() => rememberFrontOfficeKeyboardField(passwordRef.current, "Password")}
                  onChange={(event) => {
                    setFormData((prev) => ({ ...prev, password: event.target.value }));
                    setErrors((prev) => ({ ...prev, password: "", submit: "" }));
                  }}
                  aria-invalid={Boolean(errors.password)}
                  className={`h-12 w-full border bg-white pl-3 pr-16 text-[15px] text-[#1C1C1C] outline-none focus:border-[#069494] focus:shadow-[0_0_0_1px_#069494] focus-visible:outline-none ${errors.password ? "border-[#D92D20]" : "border-[#CFCFCF]"}`}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((current) => !current)}
                  className="absolute inset-y-0 right-0 px-3 text-[13px] font-medium text-[#555555] hover:text-[#1C1C1C]"
                  aria-label={showPassword ? "Hide password" : "Show password"}
                >
                  {showPassword ? "Hide" : "Show"}
                </button>
              </div>

              {signInErrorBox}

              <button
                type="submit"
                disabled={loginDisabled}
                className="mt-6 flex h-14 w-full items-center justify-center gap-2 bg-[#069494] text-[15px] font-semibold text-white hover:bg-[#067C7C] disabled:cursor-not-allowed disabled:opacity-50"
              >
                {loading ? <><LoaderCircle className="h-4 w-4 animate-spin" /> Signing in…</> : clockChecking ? "Checking…" : "Sign in"}
              </button>

              <button
                type="button"
                onMouseDown={(event) => event.preventDefault()}
                onClick={toggleFrontOfficeKeyboard}
                aria-pressed={keyboardOpen}
                aria-controls="front-office-keyboard"
                className={`mt-3 flex h-12 w-full items-center justify-center gap-2 border text-[14px] font-medium text-[#3D3D3D] hover:bg-[#F2F2F2] ${keyboardOpen ? "border-[#9A9A9A] bg-[#F2F2F2]" : "border-[#CFCFCF] bg-white"}`}
              >
                <Keyboard className="h-4 w-4" />
                {keyboardOpen ? "Hide keyboard" : "Show keyboard"}
              </button>
            </>
          )}

          <p className="mt-6 text-center text-[11px] text-[#9A9A9A]">Shop POS · {buildVersion}</p>
        </form>
      </main>

      {!isCodeLogin && keyboardOpen && (
        <WirelessKeyboard
          id="front-office-keyboard"
          variant="docked"
          targetName={keyboardTargetName}
          targetRef={keyboardTargetRef}
          onClose={() => setKeyboardOpen(false)}
          onEnter={handleSubmit}
          enterLabel={loading ? "Wait" : "Sign in"}
        />
      )}

      {showBranchDropdown && createPortal(branchMenu, document.body)}
      {branchSelectionModal}

      {licenseModalOpen && (
        <LicenseRenewalModal
          licenseKey={licenseKey}
          setLicenseKey={setLicenseKey}
          licenseKeyRef={licenseKeyRef}
          openKeyboardFor={openKeyboardFor}
          licenseError={licenseError}
          setLicenseError={setLicenseError}
          licenseSuccess={licenseSuccess}
          setLicenseSuccess={setLicenseSuccess}
          renewingLicense={renewingLicense}
          onSubmit={handleRenewLicense}
          onClose={() => {
            setLicenseModalOpen(false);
            setLicenseError("");
            setLicenseSuccess("");
            setLicenseKey("");
          }}
        />
      )}

      <style>{`
        /* Browser autofill paints inputs light blue; keep them white. */
        .servenest-login-shell input:-webkit-autofill {
          -webkit-box-shadow: 0 0 0 1000px #fff inset;
          -webkit-text-fill-color: #1C1C1C;
        }
      `}</style>
    </div>
  );
};

function PlainLoginCodeKeypad({
  value,
  numericOnly = true,
  disabled,
  enterDisabled,
  onDigit,
  onBack,
  onClear,
  onEnter,
}) {
  const keys = numericOnly
    ? ["7", "8", "9", "4", "5", "6", "1", "2", "3", "0"]
    : [..."ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"];
  const canEnter = !disabled && !enterDisabled;
  // Touch targets: keys stay at least 48px tall on short screens; LOGIN is the
  // largest key on the pad.
  const keyHeight = numericOnly
    ? "min-h-14 [@media(max-height:760px)]:min-h-12"
    : "min-h-11";
  const loginHeight = numericOnly
    ? "min-h-16 [@media(max-height:760px)]:min-h-14"
    : "min-h-14";
  const keyStyle = "border border-[#E3E3E3] bg-[#F5F5F5] text-[#1C1C1C] hover:bg-[#EBEBEB] active:bg-[#E0E0E0] disabled:opacity-50";

  return (
    <div className={`mt-4 grid gap-2 ${numericOnly ? "grid-cols-3" : "grid-cols-6"}`}>
      {keys.map((key) => (
        <button
          key={key}
          type="button"
          disabled={disabled}
          onClick={() => onDigit?.(key)}
          className={`${keyHeight} ${keyStyle} font-medium ${numericOnly ? "text-[24px]" : "text-[15px]"}`}
        >
          {key}
        </button>
      ))}

      <button
        type="button"
        disabled={disabled}
        onClick={onBack}
        className={`${keyHeight} ${numericOnly ? "" : "col-span-2"} ${keyStyle} flex items-center justify-center text-[22px]`}
        title="Backspace"
        aria-label="Backspace"
      >
        ⌫
      </button>

      <button
        type="button"
        disabled={disabled}
        onClick={onClear}
        className={`${keyHeight} ${numericOnly ? "" : "col-span-4"} ${keyStyle} flex items-center justify-center text-[14px] font-medium text-[#B42318]`}
        title="Clear code"
      >
        Clear
      </button>

      <button
        type="button"
        disabled={!canEnter}
        onClick={onEnter}
        className={`${loginHeight} ${numericOnly ? "col-span-3" : "col-span-6"} flex items-center justify-center gap-2 bg-[#069494] text-[16px] font-semibold text-white hover:bg-[#067C7C] disabled:cursor-not-allowed disabled:opacity-50`}
        title="Login"
      >
        <CornerDownLeft className="h-5 w-5" /> Sign in
      </button>
    </div>
  );
}

function KeyboardInputShell({ children, onKeyboard, rightOffset = "right-0" }) {
  return (
    <div className="relative">
      {children}
      <button
        type="button"
        onClick={onKeyboard}
        className={`absolute ${rightOffset} top-0 flex h-9 w-10 items-center justify-center border-l border-[#ACB8B8] bg-[#F4F7F9] text-[#004141] hover:bg-[#E8F7F5]`}
      >
        <Keyboard className="h-4 w-4" />
      </button>
    </div>
  );
}

// "floating" and "inline" are the Front Office keyboards. "docked" is the plain
// neutral keyboard the Back Office sign-in opens at the bottom of the screen.
const WIRELESS_KEYBOARD_THEMES = {
  floating: {
    shell: "fixed bottom-4 right-4 z-[999999] w-[min(720px,calc(100vw-32px))] border border-[#90A2A1] bg-white shadow-2xl",
    header: "flex h-10 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white",
    icon: "h-4 w-4 text-[#00BCBC]",
    title: "text-[11px] font-black uppercase tracking-wide",
    titleText: "Wireless Keyboard",
    target: "text-[10px] font-bold text-white/55",
    close: "flex h-7 w-8 items-center justify-center border border-white/20 bg-white/10 hover:bg-white/20",
    body: "bg-[#F4F7F9] p-3",
    letter: "h-10 text-[14px] font-black shadow-sm border-[#CBD6D6] bg-white text-[#003636] hover:bg-[#E8F7F5]",
    control: "h-10 text-[11px] font-black",
    shiftOn: "border-[#069495] bg-[#E8F7F5] text-[#006869]",
    shiftOff: "border-[#CBD6D6] bg-white text-[#003636]",
    space: "border-[#CBD6D6] bg-white text-[#003636] hover:bg-[#E8F7F5]",
    back: "border-[#CBD6D6] bg-white text-[#003636] hover:bg-[#FEF3F2]",
    clear: "h-10 text-[10px] font-black border-[#FDA29B] bg-[#3F141A] text-white",
    enter: "border-[#069495] bg-[#069495] text-white",
    labels: { shift: "SHIFT", space: "SPACE", back: "BACK", clear: "CLR" },
  },
  inline: {
    shell: "relative z-10 w-full shrink-0 border-t border-[#006161] bg-[#003131]",
    header: "flex h-10 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white",
    icon: "h-4 w-4 text-[#00BCBC]",
    title: "text-[11px] font-black uppercase tracking-wide",
    titleText: "Wireless Keyboard",
    target: "text-[10px] font-bold text-white/55",
    close: "flex h-7 w-8 items-center justify-center border border-white/20 bg-white/10 hover:bg-white/20",
    body: "bg-[#003333] p-3",
    letter: "h-10 text-[14px] font-black shadow-sm border-white/15 bg-white/10 text-white hover:bg-[#006E6E]",
    control: "h-10 text-[11px] font-black",
    shiftOn: "border-[#069495] bg-[#E8F7F5] text-[#006869]",
    shiftOff: "border-white/15 bg-white/10 text-white",
    space: "border-white/15 bg-white/10 text-white hover:bg-[#006E6E]",
    back: "border-white/15 bg-white/10 text-white hover:bg-[#7F1D1D]",
    clear: "h-10 text-[10px] font-black border-[#FDA29B] bg-[#3F141A] text-white",
    enter: "border-[#069495] bg-[#069495] text-white",
    labels: { shift: "SHIFT", space: "SPACE", back: "BACK", clear: "CLR" },
  },
  docked: {
    shell: "w-full shrink-0 border-t border-[#D4D4D4] bg-[#F2F2F2] text-[#1C1C1C]",
    header: "mx-auto flex h-11 w-full max-w-[960px] items-center justify-between px-3",
    icon: "h-4 w-4 text-[#6B6B6B]",
    title: "text-[13px] font-semibold",
    titleText: "Keyboard",
    target: "text-[13px] text-[#6B6B6B]",
    close: "flex h-8 w-8 items-center justify-center text-[#555555] hover:bg-[#E3E3E3] hover:text-[#1C1C1C]",
    body: "mx-auto w-full max-w-[960px] px-3 pb-3",
    letter: "h-12 [@media(max-height:820px)]:h-10 text-[16px] font-medium border-[#D4D4D4] bg-white text-[#1C1C1C] hover:bg-[#EBEBEB]",
    control: "h-12 [@media(max-height:820px)]:h-10 text-[13px] font-medium",
    shiftOn: "border-[#1C1C1C] bg-[#1C1C1C] text-white",
    shiftOff: "border-[#D4D4D4] bg-white text-[#1C1C1C] hover:bg-[#EBEBEB]",
    space: "border-[#D4D4D4] bg-white text-[#1C1C1C] hover:bg-[#EBEBEB]",
    back: "border-[#D4D4D4] bg-white text-[#1C1C1C] hover:bg-[#EBEBEB]",
    clear: "h-12 [@media(max-height:820px)]:h-10 text-[13px] font-medium border-[#D4D4D4] bg-white text-[#B42318] hover:bg-[#FDF2F0]",
    enter: "border-[#069494] bg-[#069494] text-white hover:bg-[#067C7C]",
    labels: { shift: "Shift", space: "Space", back: "Back", clear: "Clear" },
  },
};

function WirelessKeyboard({ id, targetName, targetRef, onClose, onEnter, onClear, enterLabel = "ENTER", inline = false, variant }) {
  const [shift, setShift] = useState(false);
  const theme = WIRELESS_KEYBOARD_THEMES[variant] || WIRELESS_KEYBOARD_THEMES[inline ? "inline" : "floating"];

  const pressKey = (key) => {
    const element = targetRef.current;
    if (!element) return;

    const value = shift && key.length === 1 ? key.toUpperCase() : key;
    insertIntoInput(element, value);

    if (shift) setShift(false);
  };

  return (
    <div id={id} className={theme.shell}>
      <div className={theme.header}>
        <div className="flex items-center gap-2">
          <Keyboard className={theme.icon} />
          <span className={theme.title}>{theme.titleText}</span>
          <span className={theme.target}>{targetName}</span>
        </div>

        <button
          type="button"
          onClick={onClose}
          className={theme.close}
          aria-label="Close keyboard"
        >
          <X className="h-4 w-4" />
        </button>
      </div>

      <div className={theme.body}>
        {KEYBOARD_ROWS.map((row, rowIndex) => (
          <div key={rowIndex} className="mb-2 grid grid-cols-10 gap-2">
            {row.map((key) => (
              <button
                key={key}
                type="button"
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => pressKey(key)}
                className={`${theme.letter} border active:scale-95`}
              >
                {shift && key.length === 1 ? key.toUpperCase() : key}
              </button>
            ))}
          </div>
        ))}

        <div className="grid grid-cols-12 gap-2">
          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => setShift((current) => !current)}
            aria-pressed={shift}
            className={`col-span-2 border ${theme.control} ${shift ? theme.shiftOn : theme.shiftOff}`}
          >
            {theme.labels.shift}
          </button>

          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => pressKey(" ")}
            className={`col-span-5 flex items-center justify-center border ${theme.control} ${theme.space}`}
          >
            <Space className="mr-1 h-4 w-4" />
            {theme.labels.space}
          </button>

          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => backspaceInput(targetRef.current)}
            className={`col-span-2 flex items-center justify-center border ${theme.control} ${theme.back}`}
          >
            <Delete className="mr-1 h-4 w-4" />
            {theme.labels.back}
          </button>

          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => {
              if (onClear) {
                onClear();
              } else {
                setNativeValue(targetRef.current, "");
                targetRef.current?.focus?.({ preventScroll: true });
              }
            }}
            className={`col-span-1 border ${theme.clear}`}
          >
            {theme.labels.clear}
          </button>

          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={onEnter}
            className={`col-span-2 flex items-center justify-center border ${theme.control} ${theme.enter}`}
          >
            <CornerDownLeft className="mr-1 h-4 w-4" />
            {enterLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

function StatusText({ label, value, good, warning }) {
  return (
    <span>
      {label}: {" "}
      <span
        className={
          good
            ? "font-black text-[#00BCBC]"
            : warning
              ? "font-black text-[#FBBF24]"
              : "font-black text-red-300"
        }
      >
        {value}
      </span>
    </span>
  );
}

function SideMetric({ label, value, state = "neutral" }) {
  const color =
    state === "good"
      ? "text-[#00BCBC]"
      : state === "bad"
        ? "text-red-300"
        : state === "warning"
          ? "text-[#FBBF24]"
          : "text-white";

  return (
    <div className="border border-white/15 bg-[#004142] p-3">
      <div className="text-[10px] font-black uppercase tracking-wide text-white/50">
        {label}
      </div>
      <div className={`mt-1 truncate text-[12px] font-black tracking-wide ${color}`}>
        {value}
      </div>
    </div>
  );
}

function InputBlock({ label, error, children }) {
  return (
    <div>
      <label className="mb-1 block text-[11px] font-black tracking-wide">
        {label}
      </label>

      {children}

      {error && <p className="mt-1 text-[11px] text-red-600">{error}</p>}
    </div>
  );
}

function ClockAlert({ checking, error, details, onRecheck }) {
  return (
    <div className="border border-red-300 bg-red-50 p-3 text-red-700">
      <div className="text-[11px] font-black uppercase">Login Blocked</div>

      <div className="mt-1 whitespace-pre-wrap text-[12px]">
        {checking ? "Checking computer date and time..." : error || "Clock check failed."}
      </div>

      {details && (
        <div className="mt-3 grid grid-cols-1 gap-1 text-[11px] text-red-700/90">
          <div>
            Server: <b>{formatClockTime(details.server_iso || details.server_time)}</b>
          </div>

          <div>
            Computer: <b>{formatClockTime(details.browser_time)}</b>
          </div>

          {details.drift_minutes !== undefined && (
            <div>
              Difference: <b>{details.drift_minutes} minutes</b>
            </div>
          )}
        </div>
      )}

      <button
        type="button"
        onClick={onRecheck}
        className="mt-3 h-8 bg-[#004142] px-4 text-[11px] font-black uppercase text-white"
      >
        Recheck
      </button>
    </div>
  );
}

function LicenseRenewalModal({
  licenseKey,
  setLicenseKey,
  licenseKeyRef,
  openKeyboardFor,
  licenseError,
  setLicenseError,
  licenseSuccess,
  setLicenseSuccess,
  renewingLicense,
  onSubmit,
  onClose,
}) {
  return (
    <div className="fixed inset-0 z-[999998] flex items-center justify-center bg-black/70 p-5">
      <div className="w-full max-w-[760px] border border-[#ACB8B8] bg-white text-[#003636] shadow-2xl">
        <div className="flex h-14 items-center justify-between border-b-4 border-[#00BCBC] bg-[#004142] px-4">
          <div>
            <div className="text-[13px] font-black uppercase tracking-wide text-white">
              License Renewal
            </div>
            <div className="text-[10px] font-bold uppercase text-white/60">
              16-character key required
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="h-8 bg-white/10 px-3 text-[11px] font-black uppercase text-white hover:bg-white/20"
          >
            Close
          </button>
        </div>

        <form onSubmit={onSubmit} className="space-y-4 p-4">
          <div className="border border-red-200 bg-red-50 p-3">
            <div className="text-[11px] font-black uppercase text-red-700">
              License Required
            </div>
            <div className="mt-1 whitespace-pre-wrap text-[12px] text-red-700">
              {licenseError || "No active license found for this company."}
            </div>
          </div>

          {licenseSuccess && (
            <div className="border border-[#86EFAC] bg-[#DCFCE7] p-3">
              <div className="text-[11px] font-black uppercase text-[#166534]">
                License Renewed
              </div>
              <div className="mt-1 text-[12px] text-[#166534]">{licenseSuccess}</div>
            </div>
          )}

          <div>
            <div className="mb-1 flex items-center justify-between">
              <label className="block text-[11px] font-black uppercase">
                License Key
              </label>
              <button
                type="button"
                onClick={() => openKeyboardFor(licenseKeyRef.current, "License Key")}
                className="flex h-7 items-center gap-1 border border-[#ACB8B8] bg-[#F4F7F9] px-2 text-[10px] font-black text-[#004141] hover:bg-[#E8F7F5]"
              >
                <Keyboard className="h-3.5 w-3.5" />
                KEYBOARD
              </button>
            </div>

            <input
              ref={licenseKeyRef}
              type="text"
              inputMode="text"
              autoCapitalize="characters"
              autoComplete="off"
              spellCheck={false}
              maxLength={LICENSE_KEY_FORMATTED_LENGTH}
              value={licenseKey}
              onChange={(event) => {
                setLicenseKey(formatLicenseKey(event.target.value));
                setLicenseError("");
                setLicenseSuccess("");
              }}
              placeholder="XXXX-XXXX-XXXX-XXXX"
              aria-label="License key"
              className="h-12 w-full border border-[#ACB8B8] px-3 text-[16px] font-black uppercase tracking-[0.16em] outline-none focus:border-[#00BCBC]"
            />
            <div className="mt-2 text-[10px] font-bold text-[#637877]">
              Enter the four groups printed on the company license.
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <button
              type="submit"
              disabled={renewingLicense}
              className="h-10 bg-[#069495] text-[12px] font-black uppercase text-white hover:bg-[#067C7C] disabled:opacity-50"
            >
              {renewingLicense ? "Activating..." : "Activate License"}
            </button>

            <button
              type="button"
              onClick={() => {
                setLicenseKey("");
                setLicenseError("");
                setLicenseSuccess("");
              }}
              className="h-10 border border-[#ACB8B8] bg-[#EBF0F0] text-[12px] font-black uppercase text-[#003636] hover:bg-[#DFE5E5]"
            >
              Clear
            </button>
          </div>
        </form>

        <div className="flex h-8 items-center justify-between border-t border-[#ACB8B8] bg-[#F7FAFC] px-4 text-[10px] font-bold text-[#637877]">
          <span>SERVENEST</span>
          <span>LICENSE RECOVERY</span>
        </div>
      </div>
    </div>
  );
}

export default AdminLogin;

export const LICENSE_KEY_RAW_LENGTH = 16;
export const LICENSE_KEY_FORMATTED_LENGTH = 19;
export const LICENSE_KEY_ALPHABET = "ABCDEFGHJKMNPQRSTVWXYZ23456789";

const ALLOWED_KEY_PATTERN = /^[A-HJ-KM-NP-TV-Z2-9]{16}$/;

export const normalizeLicenseKey = (value) =>
  String(value || "")
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .slice(0, LICENSE_KEY_RAW_LENGTH);

export const formatLicenseKey = (value) => {
  const normalized = normalizeLicenseKey(value);

  return normalized.match(/.{1,4}/g)?.join("-") || "";
};

export const isValidLicenseKey = (value) =>
  ALLOWED_KEY_PATTERN.test(normalizeLicenseKey(value));

export const getLicenseKeyValidationMessage = (value) => {
  const normalized = normalizeLicenseKey(value);

  if (/[^A-HJ-KM-NP-TV-Z2-9]/.test(normalized)) {
    return "License keys do not use I, L, O, U, 0, or 1.";
  }

  if (normalized.length !== LICENSE_KEY_RAW_LENGTH) {
    return "Enter the complete 16-character license key.";
  }

  return "";
};

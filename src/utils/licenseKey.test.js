import {
  formatLicenseKey,
  getLicenseKeyValidationMessage,
  isValidLicenseKey,
  normalizeLicenseKey,
} from "./licenseKey";

describe("license key formatting", () => {
  test("formats pasted or typed keys as four uppercase groups", () => {
    expect(formatLicenseKey("ab23 cd45-ef67_gh89-more")).toBe(
      "AB23-CD45-EF67-GH89"
    );
  });

  test("normalizes the displayed key for the activation API", () => {
    expect(normalizeLicenseKey("AB23-CD45-EF67-GH89")).toBe(
      "AB23CD45EF67GH89"
    );
  });

  test("requires sixteen characters from the unambiguous backend alphabet", () => {
    expect(isValidLicenseKey("AB23-CD45-EF67-GH89")).toBe(true);
    expect(isValidLicenseKey("AI23-CD45-EF67-GH89")).toBe(false);
    expect(isValidLicenseKey("AB23-CD45")).toBe(false);
    expect(getLicenseKeyValidationMessage("AI23-CD45-EF67-GH89")).toMatch(
      /do not use I, L, O, U, 0, or 1/
    );
  });
});

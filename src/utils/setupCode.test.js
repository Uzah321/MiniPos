import {
  SETUP_CODE_KINDS,
  classifySetupCode,
  serverPairingCodeError,
  terminalActivationCodeError,
} from "./setupCode";

describe("one-time setup code purposes", () => {
  const pairingCode = "CMP019F93B4B704-RM-M1ARIAEO.APMB1UV6ESC7-DPCCQLHP9RC7";
  const terminalCode = "TERM-0123ABCD-4567EF89-A1B2C3D4-E5F60718";

  test("distinguishes server pairing codes from browser activation codes", () => {
    expect(classifySetupCode(pairingCode)).toBe(SETUP_CODE_KINDS.SERVER_PAIRING);
    expect(classifySetupCode(terminalCode)).toBe(SETUP_CODE_KINDS.TERMINAL_ACTIVATION);
  });

  test("rejects a server pairing code from the browser activation workflow", () => {
    expect(terminalActivationCodeError(pairingCode)).toMatch(/POS server pairing\/re-pair code/i);
    expect(terminalActivationCodeError(pairingCode)).toMatch(/Activate Browser/i);
    expect(terminalActivationCodeError(terminalCode)).toBe("");
  });

  test("rejects a browser activation code from the server pairing workflow", () => {
    expect(serverPairingCodeError(terminalCode)).toMatch(/browser activation code/i);
    expect(serverPairingCodeError(pairingCode)).toBe("");
  });

  test("explains incomplete codes before they reach the API", () => {
    expect(terminalActivationCodeError("TERM-SHORT")).toMatch(/four groups of eight/i);
    expect(serverPairingCodeError("MISSING-DOT")).toMatch(/contain a dot/i);
  });
});

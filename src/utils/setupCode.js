export const SETUP_CODE_KINDS = Object.freeze({
  EMPTY: "empty",
  TERMINAL_ACTIVATION: "terminal_activation",
  TERMINAL_ACTIVATION_LIKE: "terminal_activation_like",
  SERVER_PAIRING: "server_pairing",
  SERVER_PAIRING_LIKE: "server_pairing_like",
  UNKNOWN: "unknown",
});

const TERMINAL_ACTIVATION_PATTERN = /^TERM-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/;
const SERVER_PAIRING_PATTERN = /^[A-Z0-9_-]+\.[A-Z0-9]{12}-[A-Z0-9]{12}$/;

export const normalizeSetupCode = (value) => String(value || "").trim().toUpperCase();

export const classifySetupCode = (value) => {
  const code = normalizeSetupCode(value);
  if (!code) return SETUP_CODE_KINDS.EMPTY;
  if (TERMINAL_ACTIVATION_PATTERN.test(code)) return SETUP_CODE_KINDS.TERMINAL_ACTIVATION;
  if (SERVER_PAIRING_PATTERN.test(code)) return SETUP_CODE_KINDS.SERVER_PAIRING;
  if (code.includes(".")) return SETUP_CODE_KINDS.SERVER_PAIRING_LIKE;
  if (code.startsWith("TERM-")) return SETUP_CODE_KINDS.TERMINAL_ACTIVATION_LIKE;
  return SETUP_CODE_KINDS.UNKNOWN;
};

export const terminalActivationCodeError = (value) => {
  const kind = classifySetupCode(value);
  if (kind === SETUP_CODE_KINDS.EMPTY) {
    return "Enter the one-time browser activation code.";
  }
  if ([SETUP_CODE_KINDS.SERVER_PAIRING, SETUP_CODE_KINDS.SERVER_PAIRING_LIKE].includes(kind)) {
    return "That is a POS server pairing/re-pair code, not a browser activation code. In Back Office, choose the active POS server and select Activate Browser, then copy the TERM- code shown.";
  }
  if (kind !== SETUP_CODE_KINDS.TERMINAL_ACTIVATION) {
    return "Browser activation codes start with TERM- and contain four groups of eight characters. Copy the complete code shown after selecting Activate Browser in Back Office.";
  }
  return "";
};

export const serverPairingCodeError = (value) => {
  const kind = classifySetupCode(value);
  if (kind === SETUP_CODE_KINDS.EMPTY) {
    return "Enter the one-time POS server pairing code.";
  }
  if ([SETUP_CODE_KINDS.TERMINAL_ACTIVATION, SETUP_CODE_KINDS.TERMINAL_ACTIVATION_LIKE].includes(kind)) {
    return "That is a browser activation code, not a POS server pairing/re-pair code. Use it on the Browser Activation screen instead.";
  }
  if (kind !== SETUP_CODE_KINDS.SERVER_PAIRING) {
    return "POS server pairing codes contain a dot and end with two groups of twelve characters. Copy the complete server pairing/re-pair code from Back Office.";
  }
  return "";
};

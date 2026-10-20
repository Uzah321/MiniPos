import api from "../utils/apiBack";
import { getLocalTillIdentity, isFrontOfficeWorkspace } from "../utils/apiConfig";

let mockCertificatePromise;
let mockSignaturePromise;

const mockQz = {
  security: {
    setCertificatePromise: jest.fn((callback) => { mockCertificatePromise = callback; }),
    setSignaturePromise: jest.fn((callback) => { mockSignaturePromise = callback; }),
    setSignatureAlgorithm: jest.fn(),
  },
  websocket: {
    isActive: jest.fn(() => false),
    connect: jest.fn(),
    disconnect: jest.fn(),
    connection: {},
  },
  api: { getVersion: jest.fn() },
  printers: { find: jest.fn(), getDefault: jest.fn() },
  configs: { create: jest.fn() },
  print: jest.fn(),
};

jest.mock("qz-tray", () => ({ __esModule: true, default: mockQz }));
jest.mock("../utils/apiBack", () => ({
  __esModule: true,
  default: { get: jest.fn(), post: jest.fn() },
}));
jest.mock("../utils/apiConfig", () => ({
  getLocalTillIdentity: jest.fn(),
  isFrontOfficeWorkspace: jest.fn(),
}));

// Import after mocks so QZ registers the two security callbacks captured above.
require("./QzService");

describe("QZ request identity", () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    api.get.mockResolvedValue({ data: "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----" });
    api.post.mockResolvedValue({ data: { signature: "a".repeat(64) } });
  });

  test("Front Office lets the interceptor apply the stored till token without asserted ids", async () => {
    isFrontOfficeWorkspace.mockReturnValue(true);
    getLocalTillIdentity.mockReturnValue({
      till_token: "cryptographic-token",
      till_id: "resolved-till",
    });

    await mockCertificatePromise();
    await mockSignaturePromise("payload-to-sign");

    expect(api.get).toHaveBeenCalledWith("/api/qz/cert", expect.objectContaining({
      salexaWorkspace: "front-office",
      params: undefined,
      headers: undefined,
    }));
    expect(api.post).toHaveBeenCalledWith(
      "/api/qz/sign",
      { request: "payload-to-sign" },
      expect.objectContaining({
        salexaWorkspace: "front-office",
        headers: undefined,
      }),
    );
  });

  test("preserves the existing Back Office certificate selection context", async () => {
    isFrontOfficeWorkspace.mockReturnValue(false);
    localStorage.setItem("branch_id", "branch-1");
    localStorage.setItem("till_id", "till-1");

    await mockCertificatePromise();
    await mockSignaturePromise("management-payload");

    expect(api.get).toHaveBeenCalledWith("/api/qz/cert", expect.objectContaining({
      salexaWorkspace: "back-office",
      params: { branch_id: "branch-1", till_id: "till-1" },
      headers: { "X-Till-Id": "till-1" },
    }));
    expect(api.post).toHaveBeenCalledWith(
      "/api/qz/sign",
      {
        request: "management-payload",
        branch_id: "branch-1",
        till_id: "till-1",
      },
      expect.objectContaining({
        salexaWorkspace: "back-office",
        headers: { "X-Till-Id": "till-1" },
      }),
    );
  });
});

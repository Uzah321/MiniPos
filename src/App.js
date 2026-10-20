import React, { useEffect } from "react";
import {
  BrowserRouter as Router,
  Routes,
  Route,
  Navigate,
} from "react-router-dom";

import { CartProvider } from "./context/CartContext";
import { FrontAuthProvider } from "./context/FrontAuthContext";
import { AdminAuthProvider, useAdminAuth } from "./backOffice/context/AdminAuthContext";
import ProtectedRoute from "./components/ProtectedRoute";

import AdminLayout from "./backOffice/AdminLayOut";
import AdminProtectedRoutes from "./backOffice/AdminProtectedRoutes";
import UserManagment from "./backOffice/pages/admin/UserManagment";
import CustomerDisplay from "./backOffice/pages/sales/CustomerDisplay";
import AdminLogin from "./backOffice/login/AdminLogin";
import FrontOfficeLayout from "./frontOffice/FrontOfficeLayout";
import { WorkspaceProvider, useWorkspace } from "./context/WorkspaceContext";
import EnterpriseTableSystem from "./components/EnterpriseTableSystem";
import EnterpriseConfirmHost from "./components/EnterpriseConfirmHost";
import LicenseEnforcementGate from "./components/LicenseEnforcementGate";
import FrontOfficePairingGate from "./components/FrontOfficePairingGate";
import { SyncStatusProvider } from "./context/SyncStatusContext";
import {
  deploymentAllowsWorkspace,
  DEPLOYMENT_MODES,
  getDeploymentMode,
  getPreferredWorkspace,
} from "./config/DeploymentConfig";
import { isUnscopedPlatformAdministrator } from "./utils/workspaceAccess";
import { saveCompanyCode } from "./utils/apiConfig";

const workspaceGateStyle = {
  minHeight: "100vh",
  display: "flex",
  flexDirection: "column",
  alignItems: "center",
  justifyContent: "center",
  gap: "12px",
  padding: "24px",
  background: "#f4f7f9",
  color: "#004141",
  textAlign: "center",
};

function AdminBusinessModeLayout() {
  const { mode, loading, error, refresh } = useWorkspace();

  if (loading) {
    return (
      <main role="status" aria-live="polite" style={workspaceGateStyle}>
        <strong>Loading your workspace...</strong>
        <span>The correct menus are being prepared for this branch.</span>
      </main>
    );
  }

  if (error) {
    return (
      <main role="alert" style={workspaceGateStyle}>
        <strong>Workspace configuration could not be loaded.</strong>
        <span>{error}</span>
        <button type="button" onClick={() => refresh().catch(() => {})}>
          Retry
        </button>
      </main>
    );
  }

  return <AdminLayout key={mode} />;
}

function AdminWorkspaceRoute() {
  const { user } = useAdminAuth();
  const platformOnly = isUnscopedPlatformAdministrator(user);

  return (
    <WorkspaceProvider workspace="back-office" platformOnly={platformOnly}>
      <AdminBusinessModeLayout />
    </WorkspaceProvider>
  );
}

function App() {
  const deploymentMode = getDeploymentMode();
  const frontOfficeAllowed = deploymentAllowsWorkspace(
    deploymentMode,
    DEPLOYMENT_MODES.FRONT_OFFICE,
  );
  const backOfficeAllowed = deploymentAllowsWorkspace(
    deploymentMode,
    DEPLOYMENT_MODES.BACK_OFFICE,
  );
  const preferredWorkspace = getPreferredWorkspace(deploymentMode);
  useEffect(() => {
    document.title = preferredWorkspace === DEPLOYMENT_MODES.FRONT_OFFICE
      ? "ServeNest Hospitality POS"
      : "ServeNest Hospitality Back Office";

    if (preferredWorkspace === DEPLOYMENT_MODES.BACK_OFFICE) {
      saveCompanyCode("");
    }
  }, [preferredWorkspace]);
  const entryLogin = preferredWorkspace === DEPLOYMENT_MODES.FRONT_OFFICE
    ? (
      <FrontOfficePairingGate>
        <AdminLogin workspace="front-office" />
      </FrontOfficePairingGate>
    )
    : <AdminLogin workspace="back-office" />;

  return (
    <AdminAuthProvider>
      <FrontAuthProvider>
        <CartProvider>
          <Router>
            <SyncStatusProvider>
              <EnterpriseTableSystem />
              <EnterpriseConfirmHost />
              <LicenseEnforcementGate>
                <Routes>
                  <Route
                    path="/"
                    element={entryLogin}
                  />

                  <Route path="/admin/login" element={<Navigate to="/" replace />} />
                  <Route path="/front/login" element={<Navigate to="/" replace />} />
                  <Route path="/login" element={<Navigate to="/" replace />} />

                  <Route
                    path="/front/*"
                    element={frontOfficeAllowed ? (
                      <FrontOfficePairingGate>
                        <ProtectedRoute>
                          <WorkspaceProvider workspace="front-office">
                            <FrontOfficeLayout />
                          </WorkspaceProvider>
                        </ProtectedRoute>
                      </FrontOfficePairingGate>
                    ) : <Navigate to="/" replace />}
                  />

                  <Route
                    path="/admin/pos/customer-display"
                    element={frontOfficeAllowed ? <CustomerDisplay /> : <Navigate to="/" replace />}
                  />

                  <Route
                    path="/admin/boh/*"
                    element={backOfficeAllowed ? (
                      <AdminProtectedRoutes>
                        <Navigate to="/admin" replace />
                      </AdminProtectedRoutes>
                    ) : <Navigate to="/" replace />}
                  />

                  <Route
                    path="/admin/*"
                    element={backOfficeAllowed ? (
                      <AdminProtectedRoutes>
                        <AdminWorkspaceRoute />
                      </AdminProtectedRoutes>
                    ) : <Navigate to="/" replace />}
                  >
                    <Route path="users" element={<UserManagment />} />
                  </Route>

                  <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
              </LicenseEnforcementGate>
            </SyncStatusProvider>
          </Router>
        </CartProvider>
      </FrontAuthProvider>
    </AdminAuthProvider>
  );
}

export default App;

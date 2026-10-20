import React from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAdminAuth } from "./context/AdminAuthContext";
import {
  canAccessWorkspace,
  isUnscopedPlatformAdministrator,
} from "../utils/workspaceAccess";

const AdminProtectedRoutes = ({ children }) => {
  const { user, isAuthenticated, loading } = useAdminAuth();
  const { pathname } = useLocation();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center text-sm text-gray-600">
        Checking session...
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/" replace />;
  }

  if (!canAccessWorkspace(user, "back-office")) {
    return <Navigate to="/" replace />;
  }

  if (
    isUnscopedPlatformAdministrator(user) &&
    pathname.replace(/\/+$/, "") !== "/admin"
  ) {
    return <Navigate to="/admin" replace />;
  }

  return children;
};

export default AdminProtectedRoutes;

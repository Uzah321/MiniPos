import React from "react";
import { Navigate } from "react-router-dom";
import { useFrontAuth } from "../context/FrontAuthContext";
import { canAccessWorkspace } from "../utils/workspaceAccess";


const ProtectedRoute = ({ children }) => {
  const { user, isAuthenticated, loading } = useFrontAuth();
  
  // Show loading spinner while checking authentication
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-[#069494]"></div>
      </div>
    );
  }
  
  // Redirect to login if not authenticated
  if (!isAuthenticated) {
    return <Navigate to="/" replace />;
  }

  if (!canAccessWorkspace(user, "front-office")) {
    return <Navigate to="/" replace />;
  }
  
  return children;
};

export default ProtectedRoute;

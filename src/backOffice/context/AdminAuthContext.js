import React, { createContext, useContext, useEffect, useState } from "react";

const AdminAuthContext = createContext();

const LS_TOKEN_KEY = "back_authToken";
const LS_USER_KEY = "back_user";

export const useAdminAuth = () => {
  const context = useContext(AdminAuthContext);
  if (!context) {
    throw new Error("useAdminAuth must be used within AdminAuthProvider");
  }
  return context;
};

export const AdminAuthProvider = ({ children }) => {
  const [user, setUser] = useState(() => {
    const userData = localStorage.getItem(LS_USER_KEY);
    try {
      return userData ? JSON.parse(userData) : null;
    } catch {
      return null;
    }
  });

  const [token, setToken] = useState(() => {
    return localStorage.getItem(LS_TOKEN_KEY);
  });

  const [loading, setLoading] = useState(true);

  const isAuthenticated = !!token && !!user;

  useEffect(() => {
    setLoading(false);
  }, [token, user]);

  const login = (userData, authToken) => {
    localStorage.setItem(LS_TOKEN_KEY, authToken);
    localStorage.setItem(LS_USER_KEY, JSON.stringify(userData));

    setUser(userData);
    setToken(authToken);
  };

  const logout = () => {
    localStorage.removeItem(LS_TOKEN_KEY);
    localStorage.removeItem(LS_USER_KEY);
    localStorage.removeItem("salexa_company_code");

    setUser(null);
    setToken(null);
  };

  return (
    <AdminAuthContext.Provider
      value={{
        user,
        token,
        isAuthenticated,
        loading,
        login,
        logout,
      }}
    >
      {children}
    </AdminAuthContext.Provider>
  );
};

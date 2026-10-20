// src/context/FrontAuthContext.jsx
import React, { createContext, useCallback, useContext, useState, useEffect } from 'react';

const FrontAuthContext = createContext();

// 🔐 FRONT POS–specific keys
const LS_TOKEN_KEY = 'front_authToken';
const LS_USER_KEY  = 'front_user';

export const useFrontAuth = () => {
  const context = useContext(FrontAuthContext);
  if (!context) {
    throw new Error('useFrontAuth must be used within a FrontAuthProvider');
  }
  return context;
};

export const FrontAuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('front_authToken');
    const userData = localStorage.getItem(LS_USER_KEY);

    if (token && userData) {
      try {
        const parsedUser = JSON.parse(userData);
        setUser(parsedUser);
        setIsAuthenticated(true);
      } catch (error) {
        console.error('FrontAuthProvider - Error parsing user data:', error);
        clearAuthData();
      }
    } else {
      setIsAuthenticated(false);
      setUser(null);
    }
    setLoading(false);
  }, []);

  const clearAuthData = () => {
    localStorage.removeItem(LS_TOKEN_KEY);
    localStorage.removeItem(LS_USER_KEY);
    setUser(null);
    setIsAuthenticated(false);
  };

  const login = (userData, token) => {
    setUser(userData);
    setIsAuthenticated(true);
    localStorage.setItem(LS_TOKEN_KEY, token);
    localStorage.setItem(LS_USER_KEY, JSON.stringify(userData));
  };

  const updateUser = useCallback((updates) => {
    setUser((currentUser) => {
      const nextUser = typeof updates === 'function'
        ? updates(currentUser)
        : { ...(currentUser || {}), ...(updates || {}) };

      if (nextUser) {
        localStorage.setItem(LS_USER_KEY, JSON.stringify(nextUser));
      }

      return nextUser;
    });
  }, []);

  const logout = () => {
    clearAuthData();
  };

  return (
    <FrontAuthContext.Provider
      value={{ user, isAuthenticated, login, logout, updateUser, loading }}
    >
      {children}
    </FrontAuthContext.Provider>
  );
};

// import React, { createContext, useContext, useState, useEffect } from 'react';

// const AuthContext = createContext();

// export const useAuth = () => {
//   const context = useContext(AuthContext);
//   if (!context) {
//     throw new Error('useAuth must be used within an AuthProvider');
//   }
//   return context;
// };

// export const AuthProvider = ({ children }) => {
//   const [user, setUser] = useState(null);
//   const [isAuthenticated, setIsAuthenticated] = useState(false);
//   const [loading, setLoading] = useState(true);

//   useEffect(() => {
//     checkAuthStatus();
//   }, []);

//   const checkAuthStatus = () => {
//     const token = localStorage.getItem('token');
//     const userData = localStorage.getItem('user');

//     console.log('AuthProvider - Checking auth status:', { token, userData });

//     if (token && userData) {
//       try {
//         const parsedUser = JSON.parse(userData);
//         setUser(parsedUser);
//         setIsAuthenticated(true);
//         console.log('AuthProvider - User is authenticated:', parsedUser);
//       } catch (error) {
//         console.error('Error parsing user data:', error);
//         clearAuthData();
//       }
//     } else {
//       console.log('AuthProvider - No auth data found');
//       setIsAuthenticated(false);
//       setUser(null);
//     }
//     setLoading(false);
//   };

//   const clearAuthData = () => {
//     localStorage.removeItem('token');
//     localStorage.removeItem('user');
//     setUser(null);
//     setIsAuthenticated(false);
//   };

//   const login = (userData, token) => {
//     console.log('AuthProvider - Logging in user:', userData);
//     setUser(userData);
//     setIsAuthenticated(true);
//     localStorage.setItem('token', token);
//     localStorage.setItem('user', JSON.stringify(userData));
//   };

//   const logout = () => {
//     console.log('AuthProvider - Logging out user');
//     clearAuthData();
//   };

//   const value = {
//     user,
//     isAuthenticated,
//     login,
//     logout,
//     loading
//   };

//   return (
//     <AuthContext.Provider value={value}>
//       {children}
//     </AuthContext.Provider>
//   );
// };

// src/context/AuthContext.jsx  (FRONT POS)
import React, { createContext, useContext, useState, useEffect } from 'react';
import axios from 'axios';

const AuthContext = createContext();

// 🔐 FRONT POS keys
const LS_TOKEN_KEY = 'front_authToken';
const LS_USER_KEY  = 'front_user';

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem(LS_TOKEN_KEY);
    const userData = localStorage.getItem(LS_USER_KEY);

    console.log('FRONT AuthProvider - Checking auth status:', { token, userData });

    if (token && userData) {
      try {
        const parsedUser = JSON.parse(userData);
        setUser(parsedUser);
        setIsAuthenticated(true);
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
      } catch (error) {
        console.error('FRONT AuthProvider - Error parsing user data:', error);
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
    delete axios.defaults.headers.common['Authorization'];
    setUser(null);
    setIsAuthenticated(false);
  };

  const login = (userData, token) => {
    console.log('FRONT AuthProvider - Logging in user:', userData);
    setUser(userData);
    setIsAuthenticated(true);
    localStorage.setItem(LS_TOKEN_KEY, token);
    localStorage.setItem(LS_USER_KEY, JSON.stringify(userData));
    axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
  };

  const logout = () => {
    console.log('FRONT AuthProvider - Logging out user');
    clearAuthData();
  };

  return (
    <AuthContext.Provider value={{ user, isAuthenticated, login, logout, loading }}>
      {children}
    </AuthContext.Provider>
  );
};

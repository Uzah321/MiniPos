import React, { useMemo } from "react";
import { createTheme, ThemeProvider } from "@mui/material/styles";
import { servenestPalette } from "../styles/servenestTheme";

export default function BackOfficeThemeProvider({ children }) {
  const theme = useMemo(() => createTheme({
    palette: {
      ...servenestPalette,
      background: { default: "#ffffff", paper: "#ffffff" },
      text: { primary: "#293232", secondary: "#778181" },
      divider: "#d8dcdc",
    },
    shape: { borderRadius: 0 },
    typography: {
      fontFamily: '"Segoe UI Variable Text", "Segoe UI Variable", "Segoe UI", Arial, sans-serif',
      fontSize: 13,
      fontWeightRegular: 400,
      fontWeightMedium: 500,
      fontWeightBold: 500,
      button: { textTransform: "none", fontWeight: 400, letterSpacing: 0 },
      h1: { fontWeight: 500 }, h2: { fontWeight: 500 }, h3: { fontWeight: 500 },
      h4: { fontWeight: 500 }, h5: { fontWeight: 500 }, h6: { fontWeight: 500 },
    },
    components: {
      MuiPaper: { styleOverrides: { root: { backgroundImage: "none" } } },
      MuiButton: { defaultProps: { disableElevation: true }, styleOverrides: { root: { borderRadius: 0, minHeight: 36 } } },
      MuiOutlinedInput: { styleOverrides: { root: { borderRadius: 0, backgroundColor: "#fff" } } },
      MuiDialog: {
        styleOverrides: {
          root: { zIndex: 250000 },
          paper: ({ ownerState }) => ownerState.fullScreen ? {
            borderRadius: 0,
          } : {
            width: "min(1180px, calc(100vw - 28px))",
            height: "min(90vh, 820px)",
            maxWidth: "none",
            maxHeight: "90vh",
            margin: 14,
            borderRadius: 0,
            border: "1px solid #abb2b2",
            boxShadow: "0 20px 52px rgba(24,41,41,.28)",
            "@media (max-width: 820px)": {
              width: "calc(100vw - 14px)",
              height: "calc(100vh - 14px)",
              maxHeight: "none",
              margin: 7,
            },
          },
        },
      },
      MuiModal: { styleOverrides: { root: { zIndex: 250000 } } },
      MuiTableHead: { styleOverrides: { root: { backgroundColor: "#f5f6f7" } } },
      MuiTableCell: { styleOverrides: { root: { borderColor: "#d8dcdc", fontSize: 13 }, head: { color: "#3b4443", fontWeight: 500 } } },
      MuiListItemButton: { styleOverrides: { root: { borderRadius: 0 } } },
    },
  }), []);

  return <ThemeProvider theme={theme}>{children}</ThemeProvider>;
}

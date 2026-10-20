import React from "react";
import { createTheme, ThemeProvider } from "@mui/material/styles";

// One teal palette for every MUI surface (login, Front Office and Back Office),
// so no control falls back to MUI's default blue primary/info or purple secondary.
export const servenestPalette = {
  mode: "light",
  primary: { main: "#069494", dark: "#067c7c", light: "#e8f7f5", contrastText: "#ffffff" },
  secondary: { main: "#067c7c", dark: "#006565", light: "#cdebea", contrastText: "#ffffff" },
  info: { main: "#069494", dark: "#067c7c", light: "#0aa7a7", contrastText: "#ffffff" },
};

// Till screens such as Create Sale open in FullScreenModal (z-index 99999).
// MUI dialogs, menus and tooltips portal to <body>, so they must sit above it
// or they open invisibly behind the sale screen. Matches the Back Office theme.
const servenestTheme = createTheme({
  palette: servenestPalette,
  zIndex: { modal: 250000, snackbar: 250100, tooltip: 250200 },
});

export default function ServeNestThemeProvider({ children }) {
  return <ThemeProvider theme={servenestTheme}>{children}</ThemeProvider>;
}

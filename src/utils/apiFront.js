// apiFront.js
import axios from "axios";
import { API_BASE_URL } from "./apiConfig";

// const baseURL = "https://204.12.209.178";
// const baseURL = 'http://servenest.local:8080/';

// const baseURL = 'http://192.168.4.106:8080/';
// const baseURL = 'http://192.168.100.182:8080/';
// Configure the API origin through runtime config or REACT_APP_API_BASE_URL.
const apiFront = axios.create({
  baseURL: API_BASE_URL,
  headers: { "Content-Type": "application/json" },
});

apiFront.interceptors.request.use((config) => {
  const token = localStorage.getItem("front_authToken");
  if (token) {
    config.headers["Authorization"] = `Bearer ${token}`;
  }
  return config;
});

export default apiFront;

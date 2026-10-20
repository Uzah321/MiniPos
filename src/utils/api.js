import axios from 'axios';
import { API_BASE_URL, tokenForWorkspace } from './apiConfig';

// const baseURL = 'https://www.footpro.co.zw/footprobackend';
//const baseURL = "https://204.12.209.178";
// Configure the API origin through runtime config or REACT_APP_API_BASE_URL.
// const baseURL = 'http://servenest.local:8080/';

 const baseURL = API_BASE_URL;

// const baseURL = 'http://192.168.100.182:8080/';

// const baseURL = 'http://192.168.4.106:8080/';
const api = axios.create({
  baseURL: baseURL, 
  headers: {
    'Content-Type': 'application/json',
  },
});

api.interceptors.request.use(
  (config) => {
    const token = tokenForWorkspace();
    if (token) {
      config.headers['Authorization'] = `Bearer ${token}`;
      console.log('Authorization Header:', config.headers['Authorization']);
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);



export default api;

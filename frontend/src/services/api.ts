import axios, { AxiosInstance, AxiosError } from 'axios';
import { useAuthStore } from '../context/authStore';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:5000/api';

const api: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true,
});

// Request interceptor to add token
api.interceptors.request.use(
  (config) => {
    const token = useAuthStore.getState().accessToken;
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const originalRequest = error.config;

    // Handle token refresh
    if (error.response?.status === 401 && originalRequest) {
      try {
        const refreshToken = useAuthStore.getState().refreshToken;
        if (!refreshToken) {
          useAuthStore.getState().logout();
          return Promise.reject(error);
        }

        const response = await axios.post(`${API_BASE_URL}/auth/refresh-token`, {
          refreshToken,
        });

        const { tokens } = response.data;
        useAuthStore.getState().setTokens(tokens.accessToken, tokens.refreshToken);

        // Retry original request
        if (originalRequest.headers) {
          originalRequest.headers.Authorization = `Bearer ${tokens.accessToken}`;
        }
        return api(originalRequest);
      } catch (refreshError) {
        useAuthStore.getState().logout();
        return Promise.reject(refreshError);
      }
    }

    return Promise.reject(error);
  }
);

// Auth API
export const authAPI = {
  register: (data: { username: string; email: string; password: string; fullName: string }) =>
    api.post('/auth/register', data),
  login: (email: string, password: string) => api.post('/auth/login', { email, password }),
  logout: () => api.post('/auth/logout'),
  getCurrentUser: () => api.get('/auth/me'),
  updateProfile: (data: any) => api.put('/auth/profile', data),
};

// Project API
export const projectAPI = {
  getProjects: (page = 1, limit = 10, visibility?: string) =>
    api.get('/projects', { params: { page, limit, visibility } }),
  getProjectById: (id: string) => api.get(`/projects/${id}`),
  createProject: (data: any) => api.post('/projects', data),
  updateProject: (id: string, data: any) => api.put(`/projects/${id}`, data),
  deleteProject: (id: string) => api.delete(`/projects/${id}`),
  getProjectStats: (id: string) => api.get(`/projects/${id}/stats`),
};

// Task API (to be implemented)
export const taskAPI = {
  getTasks: (projectId: string, filters?: any) =>
    api.get(`/projects/${projectId}/tasks`, { params: filters }),
  getTaskById: (id: string) => api.get(`/tasks/${id}`),
  createTask: (data: any) => api.post('/tasks', data),
  updateTask: (id: string, data: any) => api.put(`/tasks/${id}`, data),
  deleteTask: (id: string) => api.delete(`/tasks/${id}`),
};

export default api;

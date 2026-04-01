import React, { useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { ThemeProvider, createTheme } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';

import { useAuthStore } from './context/authStore';
import { authAPI } from './services/api';

// Pages
import Login from './components/Auth/Login';
import Register from './components/Auth/Register';
import ProtectedRoute from './components/Auth/ProtectedRoute';
import Dashboard from './components/Dashboard/Dashboard';

const theme = createTheme({
  palette: {
    primary: {
      main: '#1976d2',
    },
    secondary: {
      main: '#f50057',
    },
  },
  typography: {
    fontFamily: '"Roboto", "Helvetica", "Arial", sans-serif',
  },
});

const App: React.FC = () => {
  const { setUser, accessToken } = useAuthStore();

  useEffect(() => {
    // Restore user session on app load
    if (accessToken) {
      restoreSession();
    }
  }, []);

  const restoreSession = async () => {
    try {
      const response = await authAPI.getCurrentUser();
      setUser(response.data.user);
    } catch (error) {
      console.error('Failed to restore session:', error);
      useAuthStore.getState().logout();
    }
  };

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <Router>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route
            path="/dashboard"
            element={
              <ProtectedRoute>
                <Dashboard />
              </ProtectedRoute>
            }
          />
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </Router>
      <Toaster position="top-right" />
    </ThemeProvider>
  );
};

export default App;

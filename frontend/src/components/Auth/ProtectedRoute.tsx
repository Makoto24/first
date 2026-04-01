import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../../context/authStore';
import { CircularProgress, Box } from '@mui/material';

interface ProtectedRouteProps {
  children: React.ReactNode;
}

const ProtectedRoute: React.FC<ProtectedRouteProps> = ({ children }) => {
  const { accessToken, user } = useAuthStore();

  if (!accessToken || !user) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
};

export default ProtectedRoute;

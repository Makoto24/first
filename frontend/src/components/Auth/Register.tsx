import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import {
  Container,
  Paper,
  TextField,
  Button,
  Typography,
  Box,
  Alert,
  CircularProgress,
} from '@mui/material';
import { authAPI } from '../../services/api';
import { useAuthStore } from '../../context/authStore';
import toast from 'react-hot-toast';

const Register: React.FC = () => {
  const [formData, setFormData] = useState({
    username: '',
    email: '',
    password: '',
    fullName: '',
  });
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const navigate = useNavigate();
  const { setUser, setTokens } = useAuthStore();

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setIsLoading(true);

    try {
      if (formData.password.length < 8) {
        throw new Error('Password must be at least 8 characters');
      }

      const response = await authAPI.register(formData);
      const { user, tokens } = response.data;

      setUser(user);
      setTokens(tokens.accessToken, tokens.refreshToken);
      toast.success('Registration successful!');
      navigate('/dashboard');
    } catch (err: any) {
      const errorMessage = err.response?.data?.error || err.message || 'Registration failed';
      setError(errorMessage);
      toast.error(errorMessage);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Container maxWidth="sm">
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh' }}>
        <Paper elevation={3} sx={{ padding: 4, width: '100%' }}>
          <Typography variant="h4" component="h1" gutterBottom sx={{ textAlign: 'center', marginBottom: 3 }}>
            Create Account
          </Typography>

          {error && <Alert severity="error" sx={{ marginBottom: 2 }}>{error}</Alert>}

          <form onSubmit={handleSubmit}>
            <TextField
              fullWidth
              label="Full Name"
              name="fullName"
              value={formData.fullName}
              onChange={handleChange}
              margin="normal"
              required
              disabled={isLoading}
            />
            <TextField
              fullWidth
              label="Username"
              name="username"
              value={formData.username}
              onChange={handleChange}
              margin="normal"
              required
              disabled={isLoading}
            />
            <TextField
              fullWidth
              label="Email"
              type="email"
              name="email"
              value={formData.email}
              onChange={handleChange}
              margin="normal"
              required
              disabled={isLoading}
            />
            <TextField
              fullWidth
              label="Password (min 8 characters)"
              type="password"
              name="password"
              value={formData.password}
              onChange={handleChange}
              margin="normal"
              required
              disabled={isLoading}
            />

            <Button
              type="submit"
              fullWidth
              variant="contained"
              sx={{ marginTop: 3, marginBottom: 2 }}
              disabled={isLoading}
            >
              {isLoading ? <CircularProgress size={24} /> : 'Register'}
            </Button>
          </form>

          <Typography variant="body2" sx={{ textAlign: 'center', marginTop: 2 }}>
            Already have an account?{' '}
            <Link to="/login" style={{ textDecoration: 'none', color: '#1976d2' }}>
              Login here
            </Link>
          </Typography>
        </Paper>
      </Box>
    </Container>
  );
};

export default Register;

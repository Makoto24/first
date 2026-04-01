import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Container, Grid, Paper, Typography, Button, Box, CircularProgress, Alert } from '@mui/material';
import { projectAPI } from '../../services/api';
import { useAuthStore } from '../../context/authStore';
import { Project } from '../../types';
import toast from 'react-hot-toast';
import ProjectCard from './ProjectCard';

const Dashboard: React.FC = () => {
  const [projects, setProjects] = useState<Project[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const navigate = useNavigate();
  const { user, logout } = useAuthStore();

  useEffect(() => {
    fetchProjects();
  }, []);

  const fetchProjects = async () => {
    try {
      setIsLoading(true);
      setError(null);
      const response = await projectAPI.getProjects();
      setProjects(response.data.data || []);
    } catch (err: any) {
      const errorMessage = err.response?.data?.error || 'Failed to load projects';
      setError(errorMessage);
      toast.error(errorMessage);
    } finally {
      setIsLoading(false);
    }
  };

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  return (
    <Container maxWidth="lg" sx={{ paddingY: 4 }}>
      {/* Header */}
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
        <div>
          <Typography variant="h3" component="h1" gutterBottom>
            Welcome, {user?.fullName}! 👋
          </Typography>
          <Typography variant="body1" color="textSecondary">
            Manage your projects and collaborate with your team
          </Typography>
        </div>
        <Box sx={{ display: 'flex', gap: 2 }}>
          <Button variant="contained" color="primary" onClick={() => navigate('/projects/new')}>
            + New Project
          </Button>
          <Button variant="outlined" color="error" onClick={handleLogout}>
            Logout
          </Button>
        </Box>
      </Box>

      {/* Error Message */}
      {error && <Alert severity="error" sx={{ marginBottom: 2 }}>{error}</Alert>}

      {/* Projects Grid */}
      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', paddingY: 8 }}>
          <CircularProgress />
        </Box>
      ) : projects.length === 0 ? (
        <Paper sx={{ padding: 4, textAlign: 'center' }}>
          <Typography variant="h6" gutterBottom>
            No projects yet
          </Typography>
          <Typography variant="body2" color="textSecondary" sx={{ marginBottom: 2 }}>
            Create your first project to get started
          </Typography>
          <Button variant="contained" onClick={() => navigate('/projects/new')}>
            Create Project
          </Button>
        </Paper>
      ) : (
        <Grid container spacing={3}>
          {projects.map((project) => (
            <Grid item xs={12} sm={6} md={4} key={project._id}>
              <ProjectCard
                project={project}
                onSelect={() => navigate(`/projects/${project._id}`)}
                onRefresh={fetchProjects}
              />
            </Grid>
          ))}
        </Grid>
      )}
    </Container>
  );
};

export default Dashboard;

import React from 'react';
import { Card, CardContent, CardActions, Typography, Button, LinearProgress, Box, Chip } from '@mui/material';
import { Project } from '../../types';
import { formatDistanceToNow } from 'date-fns';

interface ProjectCardProps {
  project: Project;
  onSelect: () => void;
  onRefresh: () => void;
}

const ProjectCard: React.FC<ProjectCardProps> = ({ project, onSelect, onRefresh }) => {
  const statusColors: Record<string, 'default' | 'primary' | 'secondary' | 'error' | 'info' | 'success' | 'warning'> = {
    planning: 'info',
    'in-progress': 'primary',
    'on-hold': 'warning',
    completed: 'success',
    archived: 'default',
  };

  return (
    <Card sx={{ height: '100%', display: 'flex', flexDirection: 'column', '&:hover': { boxShadow: 4 } }}>
      <CardContent sx={{ flexGrow: 1 }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', marginBottom: 1 }}>
          <Typography variant="h6" component="div" sx={{ flex: 1 }}>
            {project.name}
          </Typography>
          <Chip label={project.status} size="small" color={statusColors[project.status]} />
        </Box>

        <Typography variant="body2" color="textSecondary" sx={{ marginBottom: 2 }}>
          {project.description || 'No description'}
        </Typography>

        <Typography variant="caption" color="textSecondary" sx={{ display: 'block', marginBottom: 1 }}>
          Created {formatDistanceToNow(new Date(project.createdAt), { addSuffix: true })}
        </Typography>

        <Box sx={{ marginY: 2 }}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', marginBottom: 1 }}>
            <Typography variant="caption">Progress</Typography>
            <Typography variant="caption" sx={{ fontWeight: 'bold' }}>
              {project.progress}%
            </Typography>
          </Box>
          <LinearProgress variant="determinate" value={project.progress} />
        </Box>

        {project.endDate && (
          <Typography variant="caption" color="textSecondary">
            Due: {new Date(project.endDate).toLocaleDateString()}
          </Typography>
        )}
      </CardContent>

      <CardActions>
        <Button size="small" onClick={onSelect}>
          View Project
        </Button>
      </CardActions>
    </Card>
  );
};

export default ProjectCard;

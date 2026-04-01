import { Response } from 'express';
import { projectService } from '../services/projectService.js';
import { AuthRequest } from '../middleware/auth.js';
import { AppError, asyncHandler } from '../middleware/errorHandler.js';

export const getProjects = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const page = parseInt(req.query.page as string) || 1;
  const limit = parseInt(req.query.limit as string) || 10;
  const visibility = req.query.visibility as string;

  const result = await projectService.getProjects(req.userId, { page, limit, visibility });

  res.json({
    message: 'Projects retrieved successfully',
    ...result,
  });
});

export const getProjectById = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const { id } = req.params;
  const project = await projectService.getProjectById(id, req.userId);

  res.json({
    message: 'Project retrieved successfully',
    project,
  });
});

export const createProject = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const { name, description, startDate, endDate, budget, visibility } = req.body;

  const project = await projectService.createProject(req.userId, {
    name,
    description,
    startDate: new Date(startDate),
    endDate: endDate ? new Date(endDate) : undefined,
    budget,
    visibility,
  });

  res.status(201).json({
    message: 'Project created successfully',
    project,
  });
});

export const updateProject = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const { id } = req.params;
  const updates = req.body;

  const project = await projectService.updateProject(id, req.userId, updates);

  res.json({
    message: 'Project updated successfully',
    project,
  });
});

export const deleteProject = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const { id } = req.params;

  await projectService.deleteProject(id, req.userId);

  res.json({
    message: 'Project deleted successfully',
  });
});

export const getProjectStats = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const { id } = req.params;
  const stats = await projectService.getProjectStats(id, req.userId);

  res.json({
    message: 'Project statistics retrieved successfully',
    stats,
  });
});

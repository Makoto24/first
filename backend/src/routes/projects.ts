import express from 'express';
import {
  getProjects,
  getProjectById,
  createProject,
  updateProject,
  deleteProject,
  getProjectStats,
} from '../controllers/projectController.js';
import { authMiddleware } from '../middleware/auth.js';

const router = express.Router();

// All routes require authentication
router.use(authMiddleware);

router.get('/', getProjects);
router.post('/', createProject);
router.get('/:id', getProjectById);
router.put('/:id', updateProject);
router.delete('/:id', deleteProject);
router.get('/:id/stats', getProjectStats);

export default router;

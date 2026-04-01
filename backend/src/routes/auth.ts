import express from 'express';
import {
  register,
  login,
  logout,
  refreshToken,
  getCurrentUser,
  updateProfile,
} from '../controllers/authController.js';
import { authMiddleware } from '../middleware/auth.js';

const router = express.Router();

router.post('/register', register);
router.post('/login', login);
router.post('/logout', logout);
router.post('/refresh-token', refreshToken);
router.get('/me', authMiddleware, getCurrentUser);
router.put('/profile', authMiddleware, updateProfile);

export default router;

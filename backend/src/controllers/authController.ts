import { Response } from 'express';
import { authService } from '../services/authService.js';
import { AuthRequest } from '../middleware/auth.js';
import { AppError, asyncHandler } from '../middleware/errorHandler.js';

export const register = asyncHandler(async (req: AuthRequest, res: Response) => {
  const { username, email, password, fullName } = req.body;

  // Basic validation
  if (!username || !email || !password || !fullName) {
    throw new AppError(400, 'Missing required fields');
  }

  if (password.length < 8) {
    throw new AppError(400, 'Password must be at least 8 characters');
  }

  const result = await authService.register({ username, email, password, fullName });

  res.status(201).json({
    message: 'User registered successfully',
    ...result,
  });
});

export const login = asyncHandler(async (req: AuthRequest, res: Response) => {
  const { email, password } = req.body;

  // Basic validation
  if (!email || !password) {
    throw new AppError(400, 'Email and password are required');
  }

  const result = await authService.login(email, password);

  // Set refresh token in HttpOnly cookie
  res.cookie('refreshToken', result.tokens.refreshToken, {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'strict',
    maxAge: 7 * 24 * 60 * 60 * 1000, // 7 days
  });

  res.json({
    message: 'Login successful',
    ...result,
  });
});

export const logout = asyncHandler(async (req: AuthRequest, res: Response) => {
  // Clear refresh token cookie
  res.clearCookie('refreshToken');

  res.json({
    message: 'Logout successful',
  });
});

export const refreshToken = asyncHandler(async (req: AuthRequest, res: Response) => {
  const refreshToken = req.cookies.refreshToken || req.body.refreshToken;

  if (!refreshToken) {
    throw new AppError(400, 'Refresh token is required');
  }

  const tokens = await authService.refreshToken(refreshToken);

  // Set new refresh token in cookie
  res.cookie('refreshToken', tokens.refreshToken, {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'strict',
    maxAge: 7 * 24 * 60 * 60 * 1000,
  });

  res.json({
    message: 'Token refreshed successfully',
    tokens,
  });
});

export const getCurrentUser = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const user = await authService.getUserById(req.userId);

  res.json({
    message: 'User retrieved successfully',
    user,
  });
});

export const updateProfile = asyncHandler(async (req: AuthRequest, res: Response) => {
  if (!req.userId) {
    throw new AppError(401, 'Unauthorized');
  }

  const updates = req.body;
  const user = await authService.updateProfile(req.userId, updates);

  res.json({
    message: 'Profile updated successfully',
    user,
  });
});

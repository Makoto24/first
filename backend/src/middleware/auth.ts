import { Request, Response, NextFunction } from 'express';
import { verifyAccessToken } from '../config/jwt.js';

export interface AuthRequest extends Request {
  userId?: string;
  user?: any;
}

export const authMiddleware = (req: AuthRequest, res: Response, next: NextFunction) => {
  try {
    const authHeader = req.headers.authorization;

    if (!authHeader || !authHeader.startsWith('Bearer ')) {
      return res.status(401).json({
        error: 'No token provided',
        statusCode: 401,
      });
    }

    const token = authHeader.substring(7); // Remove "Bearer " prefix
    const decoded = verifyAccessToken(token);

    req.userId = decoded.id;
    req.user = decoded;

    next();
  } catch (error) {
    return res.status(401).json({
      error: error instanceof Error ? error.message : 'Invalid token',
      statusCode: 401,
    });
  }
};

export const optionalAuthMiddleware = (req: AuthRequest, res: Response, next: NextFunction) => {
  try {
    const authHeader = req.headers.authorization;

    if (authHeader && authHeader.startsWith('Bearer ')) {
      const token = authHeader.substring(7);
      const decoded = verifyAccessToken(token);
      req.userId = decoded.id;
      req.user = decoded;
    }

    next();
  } catch (error) {
    // Silently fail for optional auth
    next();
  }
};

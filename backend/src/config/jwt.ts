import jwt from 'jsonwebtoken';
import dotenv from 'dotenv';
import { IJWTPayload, ITokens } from '../types/index.js';

dotenv.config();

const JWT_SECRET = process.env.JWT_SECRET || 'default-secret-key';
const JWT_REFRESH_SECRET = process.env.JWT_REFRESH_SECRET || 'default-refresh-secret';
const JWT_EXPIRE = process.env.JWT_EXPIRE || '15m';
const JWT_REFRESH_EXPIRE = process.env.JWT_REFRESH_EXPIRE || '7d';

export const generateTokens = (payload: IJWTPayload): ITokens => {
  const accessToken = jwt.sign(payload, JWT_SECRET, { expiresIn: JWT_EXPIRE });
  const refreshToken = jwt.sign(payload, JWT_REFRESH_SECRET, { expiresIn: JWT_REFRESH_EXPIRE });

  return { accessToken, refreshToken };
};

export const verifyAccessToken = (token: string): IJWTPayload => {
  try {
    const decoded = jwt.verify(token, JWT_SECRET) as IJWTPayload;
    return decoded;
  } catch (error) {
    throw new Error('Invalid or expired access token');
  }
};

export const verifyRefreshToken = (token: string): IJWTPayload => {
  try {
    const decoded = jwt.verify(token, JWT_REFRESH_SECRET) as IJWTPayload;
    return decoded;
  } catch (error) {
    throw new Error('Invalid or expired refresh token');
  }
};

export const decodeToken = (token: string): IJWTPayload | null => {
  try {
    const decoded = jwt.decode(token) as IJWTPayload;
    return decoded;
  } catch (error) {
    return null;
  }
};

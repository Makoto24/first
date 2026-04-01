import bcryptjs from 'bcryptjs';
import { User } from '../models/User.js';
import { generateTokens, verifyRefreshToken } from '../config/jwt.js';
import { AppError } from '../middleware/errorHandler.js';
import { IUser, IUserPayload, ITokens, IAuthResponse } from '../types/index.js';

export class AuthService {
  async register(payload: IUserPayload): Promise<IAuthResponse> {
    const { username, email, password, fullName } = payload;

    // Check if user already exists
    const existingUser = await User.findOne({
      $or: [{ email }, { username }],
    });

    if (existingUser) {
      const field = existingUser.email === email ? 'email' : 'username';
      throw new AppError(409, `User with this ${field} already exists`);
    }

    // Hash password
    const salt = await bcryptjs.genSalt(10);
    const passwordHash = await bcryptjs.hash(password, salt);

    // Create user
    const user = await User.create({
      username,
      email,
      passwordHash,
      fullName,
      isActive: true,
    });

    // Generate tokens
    const tokens = generateTokens({
      id: user._id!.toString(),
      email: user.email,
      username: user.username,
    });

    return {
      user: this.sanitizeUser(user),
      tokens,
    };
  }

  async login(email: string, password: string): Promise<IAuthResponse> {
    // Find user
    const user = await User.findOne({ email }).select('+passwordHash');

    if (!user) {
      throw new AppError(401, 'Invalid email or password');
    }

    // Check password
    const isPasswordValid = await bcryptjs.compare(password, user.passwordHash);

    if (!isPasswordValid) {
      throw new AppError(401, 'Invalid email or password');
    }

    // Update last login
    user.lastLogin = new Date();
    await user.save();

    // Generate tokens
    const tokens = generateTokens({
      id: user._id!.toString(),
      email: user.email,
      username: user.username,
    });

    return {
      user: this.sanitizeUser(user),
      tokens,
    };
  }

  async refreshToken(refreshToken: string): Promise<ITokens> {
    try {
      const decoded = verifyRefreshToken(refreshToken);
      const user = await User.findById(decoded.id);

      if (!user || !user.isActive) {
        throw new AppError(401, 'User not found or inactive');
      }

      const tokens = generateTokens({
        id: user._id!.toString(),
        email: user.email,
        username: user.username,
      });

      return tokens;
    } catch (error) {
      throw new AppError(401, 'Invalid refresh token');
    }
  }

  async getUserById(userId: string): Promise<IUser> {
    const user = await User.findById(userId);

    if (!user) {
      throw new AppError(404, 'User not found');
    }

    return this.sanitizeUser(user);
  }

  async updateProfile(userId: string, updates: Partial<IUser>): Promise<IUser> {
    const allowedFields = ['fullName', 'avatar'];
    const sanitizedUpdates: any = {};

    allowedFields.forEach((field) => {
      if (field in updates) {
        sanitizedUpdates[field] = updates[field as keyof IUser];
      }
    });

    const user = await User.findByIdAndUpdate(userId, sanitizedUpdates, {
      new: true,
      runValidators: true,
    });

    if (!user) {
      throw new AppError(404, 'User not found');
    }

    return this.sanitizeUser(user);
  }

  private sanitizeUser(user: IUser): IUser {
    const sanitized = user.toObject ? user.toObject() : { ...user };
    delete (sanitized as any).passwordHash;
    return sanitized;
  }
}

export const authService = new AuthService();

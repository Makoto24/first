import mongoose from 'mongoose';
import { IUser } from '../types/index.js';

const userSchema = new mongoose.Schema<IUser>(
  {
    username: {
      type: String,
      required: [true, 'Username is required'],
      unique: true,
      trim: true,
      minlength: [3, 'Username must be at least 3 characters'],
    },
    email: {
      type: String,
      required: [true, 'Email is required'],
      unique: true,
      lowercase: true,
      match: [/^[^\s@]+@[^\s@]+\.[^\s@]+$/, 'Please provide a valid email'],
    },
    passwordHash: {
      type: String,
      required: [true, 'Password is required'],
      minlength: [8, 'Password must be at least 8 characters'],
      select: false, // Don't include by default in queries
    },
    fullName: {
      type: String,
      required: [true, 'Full name is required'],
      trim: true,
    },
    avatar: {
      type: String,
      default: null,
    },
    role: {
      type: String,
      enum: ['admin', 'user'],
      default: 'user',
    },
    lastLogin: {
      type: Date,
      default: null,
    },
    isActive: {
      type: Boolean,
      default: true,
    },
  },
  {
    timestamps: true,
  }
);

// Index for faster queries
userSchema.index({ email: 1 });
userSchema.index({ username: 1 });

export const User = mongoose.model<IUser>('User', userSchema);

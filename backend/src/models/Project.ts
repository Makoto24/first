import mongoose from 'mongoose';
import { IProject } from '../types/index.js';

const projectSchema = new mongoose.Schema<IProject>(
  {
    name: {
      type: String,
      required: [true, 'Project name is required'],
      trim: true,
      maxlength: [100, 'Project name must not exceed 100 characters'],
    },
    description: {
      type: String,
      default: '',
      maxlength: [1000, 'Description must not exceed 1000 characters'],
    },
    ownerId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'User',
      required: [true, 'Owner ID is required'],
    },
    status: {
      type: String,
      enum: ['planning', 'in-progress', 'on-hold', 'completed', 'archived'],
      default: 'planning',
    },
    startDate: {
      type: Date,
      required: [true, 'Start date is required'],
    },
    endDate: {
      type: Date,
      default: null,
    },
    budget: {
      type: Number,
      default: null,
      min: [0, 'Budget cannot be negative'],
    },
    teamId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'Team',
      default: null,
    },
    visibility: {
      type: String,
      enum: ['private', 'team', 'public'],
      default: 'private',
    },
    progress: {
      type: Number,
      default: 0,
      min: [0, 'Progress cannot be less than 0'],
      max: [100, 'Progress cannot exceed 100'],
    },
  },
  {
    timestamps: true,
  }
);

// Index for faster queries
projectSchema.index({ ownerId: 1 });
projectSchema.index({ teamId: 1 });
projectSchema.index({ status: 1 });

export const Project = mongoose.model<IProject>('Project', projectSchema);

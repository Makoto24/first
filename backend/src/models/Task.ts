import mongoose from 'mongoose';
import { ITask } from '../types/index.js';

const taskSchema = new mongoose.Schema<ITask>(
  {
    projectId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'Project',
      required: [true, 'Project ID is required'],
    },
    title: {
      type: String,
      required: [true, 'Task title is required'],
      trim: true,
      maxlength: [200, 'Title must not exceed 200 characters'],
    },
    description: {
      type: String,
      default: '',
      maxlength: [2000, 'Description must not exceed 2000 characters'],
    },
    status: {
      type: String,
      enum: ['todo', 'in-progress', 'review', 'done'],
      default: 'todo',
    },
    priority: {
      type: String,
      enum: ['low', 'medium', 'high', 'critical'],
      default: 'medium',
    },
    assignedTo: [
      {
        type: mongoose.Schema.Types.ObjectId,
        ref: 'User',
      },
    ],
    dueDate: {
      type: Date,
      default: null,
    },
    estimatedHours: {
      type: Number,
      default: null,
      min: [0, 'Estimated hours cannot be negative'],
    },
    actualHours: {
      type: Number,
      default: null,
      min: [0, 'Actual hours cannot be negative'],
    },
    tags: [String],
    attachments: [
      {
        fileName: String,
        fileUrl: String,
        uploadedAt: {
          type: Date,
          default: Date.now,
        },
        uploadedBy: {
          type: mongoose.Schema.Types.ObjectId,
          ref: 'User',
        },
      },
    ],
    parentTaskId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'Task',
      default: null,
    },
    subtasks: [
      {
        type: mongoose.Schema.Types.ObjectId,
        ref: 'Task',
      },
    ],
    createdBy: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'User',
      required: [true, 'Creator ID is required'],
    },
    completedAt: {
      type: Date,
      default: null,
    },
  },
  {
    timestamps: true,
  }
);

// Index for faster queries
taskSchema.index({ projectId: 1 });
taskSchema.index({ assignedTo: 1 });
taskSchema.index({ status: 1 });
taskSchema.index({ priority: 1 });
taskSchema.index({ dueDate: 1 });

export const Task = mongoose.model<ITask>('Task', taskSchema);

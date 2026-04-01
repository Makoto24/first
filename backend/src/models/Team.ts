import mongoose from 'mongoose';
import { ITeam } from '../types/index.js';

const teamMemberSchema = new mongoose.Schema({
  userId: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'User',
    required: true,
  },
  role: {
    type: String,
    enum: ['owner', 'lead', 'member'],
    default: 'member',
  },
  joinedAt: {
    type: Date,
    default: Date.now,
  },
  status: {
    type: String,
    enum: ['active', 'inactive'],
    default: 'active',
  },
});

const teamSchema = new mongoose.Schema<ITeam>(
  {
    name: {
      type: String,
      required: [true, 'Team name is required'],
      trim: true,
      maxlength: [100, 'Team name must not exceed 100 characters'],
    },
    description: {
      type: String,
      default: '',
      maxlength: [500, 'Description must not exceed 500 characters'],
    },
    createdBy: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'User',
      required: [true, 'Creator ID is required'],
    },
    members: [teamMemberSchema],
    projects: [
      {
        type: mongoose.Schema.Types.ObjectId,
        ref: 'Project',
      },
    ],
  },
  {
    timestamps: true,
  }
);

// Index for faster queries
teamSchema.index({ createdBy: 1 });
teamSchema.index({ 'members.userId': 1 });

export const Team = mongoose.model<ITeam>('Team', teamSchema);

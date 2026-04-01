import { Project } from '../models/Project.js';
import { Task } from '../models/Task.js';
import { AppError } from '../middleware/errorHandler.js';
import { IProject, IProjectPayload, IPaginatedResponse, IPaginationOptions } from '../types/index.js';

export class ProjectService {
  async getProjects(
    userId: string,
    options: IPaginationOptions & { visibility?: string } = { page: 1, limit: 10 }
  ): Promise<IPaginatedResponse<IProject>> {
    const { page = 1, limit = 10, visibility } = options;
    const skip = (page - 1) * limit;

    const query: any = {
      $or: [
        { ownerId: userId },
        { visibility: { $in: ['public', 'team'] } },
      ],
    };

    if (visibility) {
      query.visibility = visibility;
    }

    const total = await Project.countDocuments(query);
    const projects = await Project.find(query)
      .populate('ownerId', 'username fullName')
      .populate('teamId', 'name')
      .skip(skip)
      .limit(limit)
      .sort({ createdAt: -1 });

    return {
      data: projects,
      total,
      page,
      limit,
      totalPages: Math.ceil(total / limit),
    };
  }

  async getProjectById(projectId: string, userId: string): Promise<IProject> {
    const project = await Project.findById(projectId)
      .populate('ownerId', 'username fullName email')
      .populate('teamId');

    if (!project) {
      throw new AppError(404, 'Project not found');
    }

    // Check permission (owner or public/team visibility)
    const isOwner = project.ownerId._id.toString() === userId;
    if (!isOwner && project.visibility === 'private') {
      throw new AppError(403, 'You do not have permission to access this project');
    }

    return project;
  }

  async createProject(userId: string, payload: IProjectPayload): Promise<IProject> {
    const { name, description, startDate, endDate, budget, visibility } = payload;

    if (!name || !startDate) {
      throw new AppError(400, 'Name and start date are required');
    }

    const project = await Project.create({
      name,
      description: description || '',
      ownerId: userId,
      startDate,
      endDate: endDate || null,
      budget: budget || null,
      visibility: visibility || 'private',
      progress: 0,
    });

    return await project.populate('ownerId', 'username fullName');
  }

  async updateProject(
    projectId: string,
    userId: string,
    updates: Partial<IProject>
  ): Promise<IProject> {
    const project = await Project.findById(projectId);

    if (!project) {
      throw new AppError(404, 'Project not found');
    }

    // Check if user is owner
    if (project.ownerId.toString() !== userId) {
      throw new AppError(403, 'Only project owner can update this project');
    }

    // Allowed fields
    const allowedFields = ['name', 'description', 'status', 'endDate', 'budget', 'visibility', 'progress'];
    const sanitizedUpdates: any = {};

    allowedFields.forEach((field) => {
      if (field in updates) {
        sanitizedUpdates[field] = updates[field as keyof IProject];
      }
    });

    Object.assign(project, sanitizedUpdates);
    await project.save();

    return await project.populate('ownerId', 'username fullName');
  }

  async deleteProject(projectId: string, userId: string): Promise<void> {
    const project = await Project.findById(projectId);

    if (!project) {
      throw new AppError(404, 'Project not found');
    }

    // Check if user is owner
    if (project.ownerId.toString() !== userId) {
      throw new AppError(403, 'Only project owner can delete this project');
    }

    // Delete all tasks related to this project
    await Task.deleteMany({ projectId });

    // Delete project
    await Project.deleteOne({ _id: projectId });
  }

  async getProjectStats(projectId: string, userId: string): Promise<any> {
    const project = await this.getProjectById(projectId, userId);

    const tasks = await Task.find({ projectId });
    const totalTasks = tasks.length;
    const completedTasks = tasks.filter((t) => t.status === 'done').length;
    const inProgressTasks = tasks.filter((t) => t.status === 'in-progress').length;

    return {
      totalTasks,
      completedTasks,
      inProgressTasks,
      completionRate: totalTasks > 0 ? Math.round((completedTasks / totalTasks) * 100) : 0,
      byPriority: {
        critical: tasks.filter((t) => t.priority === 'critical').length,
        high: tasks.filter((t) => t.priority === 'high').length,
        medium: tasks.filter((t) => t.priority === 'medium').length,
        low: tasks.filter((t) => t.priority === 'low').length,
      },
      byStatus: {
        todo: tasks.filter((t) => t.status === 'todo').length,
        inProgress: tasks.filter((t) => t.status === 'in-progress').length,
        review: tasks.filter((t) => t.status === 'review').length,
        done: tasks.filter((t) => t.status === 'done').length,
      },
    };
  }
}

export const projectService = new ProjectService();

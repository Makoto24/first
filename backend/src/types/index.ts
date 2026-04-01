// User Types
export interface IUser {
  _id?: string;
  username: string;
  email: string;
  passwordHash: string;
  fullName: string;
  avatar?: string;
  role: 'admin' | 'user';
  createdAt?: Date;
  updatedAt?: Date;
  lastLogin?: Date;
  isActive: boolean;
}

export interface IUserPayload {
  username: string;
  email: string;
  password: string;
  fullName: string;
}

// Project Types
export interface IProject {
  _id?: string;
  name: string;
  description: string;
  ownerId: string;
  status: 'planning' | 'in-progress' | 'on-hold' | 'completed' | 'archived';
  startDate: Date;
  endDate?: Date;
  budget?: number;
  teamId?: string;
  visibility: 'private' | 'team' | 'public';
  progress: number;
  createdAt?: Date;
  updatedAt?: Date;
}

export interface IProjectPayload {
  name: string;
  description: string;
  startDate: Date;
  endDate?: Date;
  budget?: number;
  visibility?: 'private' | 'team' | 'public';
}

// Task Types
export type TaskStatus = 'todo' | 'in-progress' | 'review' | 'done';
export type TaskPriority = 'low' | 'medium' | 'high' | 'critical';

export interface ITask {
  _id?: string;
  projectId: string;
  title: string;
  description: string;
  status: TaskStatus;
  priority: TaskPriority;
  assignedTo: string[];
  dueDate?: Date;
  estimatedHours?: number;
  actualHours?: number;
  tags: string[];
  attachments?: IAttachment[];
  parentTaskId?: string;
  subtasks?: string[];
  createdBy: string;
  createdAt?: Date;
  updatedAt?: Date;
  completedAt?: Date;
}

export interface ITaskPayload {
  title: string;
  description: string;
  status?: TaskStatus;
  priority?: TaskPriority;
  assignedTo?: string[];
  dueDate?: Date;
  estimatedHours?: number;
  tags?: string[];
}

export interface IAttachment {
  fileName: string;
  fileUrl: string;
  uploadedAt: Date;
  uploadedBy: string;
}

// Team Types
export interface ITeamMember {
  userId: string;
  role: 'owner' | 'lead' | 'member';
  joinedAt: Date;
  status: 'active' | 'inactive';
}

export interface ITeam {
  _id?: string;
  name: string;
  description: string;
  createdBy: string;
  members: ITeamMember[];
  projects: string[];
  createdAt?: Date;
  updatedAt?: Date;
}

export interface ITeamPayload {
  name: string;
  description: string;
}

// Comment Types
export interface IComment {
  _id?: string;
  taskId: string;
  projectId: string;
  content: string;
  author: string;
  mentions: string[];
  attachments?: IAttachment[];
  editHistory?: IEditHistory[];
  createdAt?: Date;
  updatedAt?: Date;
}

export interface ICommentPayload {
  content: string;
  mentions?: string[];
}

export interface IEditHistory {
  content: string;
  editedAt: Date;
  editedBy: string;
}

// Activity Log Types
export type ActivityAction = 'created' | 'updated' | 'deleted' | 'assigned' | 'status_changed' | 'comment_added';
export type ActivityEntityType = 'project' | 'task' | 'comment' | 'team';

export interface IActivityLog {
  _id?: string;
  projectId: string;
  taskId?: string;
  userId: string;
  action: ActivityAction;
  entityType: ActivityEntityType;
  entityId: string;
  details?: {
    oldValue?: any;
    newValue?: any;
    changes?: Record<string, { old: any; new: any }>;
  };
  timestamp?: Date;
}

// Notification Types
export type NotificationType = 'mention' | 'assignment' | 'status_change' | 'comment' | 'team_invite';

export interface INotification {
  _id?: string;
  userId: string;
  type: NotificationType;
  relatedTaskId?: string;
  relatedProjectId?: string;
  relatedUserId?: string;
  message: string;
  isRead: boolean;
  readAt?: Date;
  actionUrl?: string;
  createdAt?: Date;
}

// JWT Token Types
export interface IJWTPayload {
  id: string;
  email: string;
  username: string;
}

export interface ITokens {
  accessToken: string;
  refreshToken: string;
}

// Request/Response Types
export interface IAuthResponse {
  user: IUser;
  tokens: ITokens;
}

export interface IErrorResponse {
  error: string;
  statusCode: number;
  details?: any;
}

// Query Filter Types
export interface ITaskFilters {
  projectId?: string;
  status?: TaskStatus;
  priority?: TaskPriority;
  assignedTo?: string;
  dueDate?: {
    from: Date;
    to: Date;
  };
  tags?: string[];
}

export interface IPaginationOptions {
  page: number;
  limit: number;
  sort?: Record<string, 1 | -1>;
}

export interface IPaginatedResponse<T> {
  data: T[];
  total: number;
  page: number;
  limit: number;
  totalPages: number;
}

// User Types
export interface User {
  _id: string;
  username: string;
  email: string;
  fullName: string;
  avatar?: string;
  role: 'admin' | 'user';
  createdAt: string;
  isActive: boolean;
}

export interface AuthResponse {
  user: User;
  tokens: {
    accessToken: string;
    refreshToken: string;
  };
}

// Project Types
export interface Project {
  _id: string;
  name: string;
  description: string;
  ownerId: string | User;
  status: 'planning' | 'in-progress' | 'on-hold' | 'completed' | 'archived';
  startDate: string;
  endDate?: string;
  budget?: number;
  teamId?: string;
  visibility: 'private' | 'team' | 'public';
  progress: number;
  createdAt: string;
  updatedAt: string;
}

// Task Types
export type TaskStatus = 'todo' | 'in-progress' | 'review' | 'done';
export type TaskPriority = 'low' | 'medium' | 'high' | 'critical';

export interface Task {
  _id: string;
  projectId: string;
  title: string;
  description: string;
  status: TaskStatus;
  priority: TaskPriority;
  assignedTo: string[];
  dueDate?: string;
  estimatedHours?: number;
  actualHours?: number;
  tags: string[];
  attachments?: Attachment[];
  parentTaskId?: string;
  subtasks?: string[];
  createdBy: string;
  createdAt: string;
  updatedAt: string;
  completedAt?: string;
}

export interface Attachment {
  fileName: string;
  fileUrl: string;
  uploadedAt: string;
  uploadedBy: string;
}

// Team Types
export interface TeamMember {
  userId: string | User;
  role: 'owner' | 'lead' | 'member';
  joinedAt: string;
  status: 'active' | 'inactive';
}

export interface Team {
  _id: string;
  name: string;
  description: string;
  createdBy: string;
  members: TeamMember[];
  projects: string[];
  createdAt: string;
  updatedAt: string;
}

// API Response Types
export interface ApiResponse<T> {
  message?: string;
  data?: T;
  project?: T;
  user?: T;
  users?: T[];
  projects?: T[];
  tasks?: T[];
  stats?: T;
  total?: number;
  page?: number;
  limit?: number;
  totalPages?: number;
}

export interface ApiError {
  error: string;
  statusCode: number;
  details?: any;
}

// Filter Types
export interface TaskFilters {
  status?: TaskStatus;
  priority?: TaskPriority;
  assignedTo?: string;
  search?: string;
  page?: number;
  limit?: number;
}

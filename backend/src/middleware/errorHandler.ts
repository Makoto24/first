import { Request, Response, NextFunction } from 'express';
import { IErrorResponse } from '../types/index.js';

export class AppError extends Error {
  constructor(
    public statusCode: number,
    public message: string,
    public details?: any
  ) {
    super(message);
    this.name = 'AppError';
  }
}

export const errorHandler = (err: any, req: Request, res: Response, next: NextFunction) => {
  console.error('Error:', err);

  let errorResponse: IErrorResponse = {
    error: 'Internal Server Error',
    statusCode: 500,
  };

  if (err instanceof AppError) {
    errorResponse = {
      error: err.message,
      statusCode: err.statusCode,
      details: err.details,
    };
  } else if (err.name === 'ValidationError') {
    errorResponse = {
      error: 'Validation Error',
      statusCode: 400,
      details: Object.values(err.errors).map((e: any) => e.message),
    };
  } else if (err.name === 'MongoError' && err.code === 11000) {
    const field = Object.keys(err.keyValue)[0];
    errorResponse = {
      error: `${field} already exists`,
      statusCode: 409,
    };
  } else if (err instanceof Error) {
    errorResponse = {
      error: err.message,
      statusCode: 500,
    };
  }

  // Add stack trace in development
  if (process.env.NODE_ENV === 'development') {
    errorResponse.details = {
      ...errorResponse.details,
      stack: err.stack,
    };
  }

  res.status(errorResponse.statusCode).json(errorResponse);
};

export const asyncHandler = (fn: Function) => {
  return (req: Request, res: Response, next: NextFunction) => {
    Promise.resolve(fn(req, res, next)).catch(next);
  };
};

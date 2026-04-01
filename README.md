# 🚀 Project Management Tool

A modern, full-stack project management application built with React, Node.js, Express, and MongoDB.

## Features

### Phase 1 (Current)
- ✅ User Authentication (Register, Login, Logout)
- ✅ JWT-based Authorization
- ✅ Project Management (CRUD operations)
- ✅ Dashboard with project overview
- ✅ Responsive UI with Material-UI

### Upcoming Features
- 📝 Task Management (Create, Edit, Delete, Status tracking)
- 👥 Team Management & Member Invitations
- 🔔 Real-time Notifications (WebSocket/Socket.io)
- 💬 Comments & Activity Logs
- 📊 Project Statistics & Analytics

## Tech Stack

### Backend
- **Framework**: Express.js (Node.js)
- **Language**: TypeScript
- **Database**: MongoDB with Mongoose
- **Authentication**: JWT (JSON Web Tokens)
- **Password Hashing**: bcryptjs
- **Real-time**: Socket.io
- **Validation**: Joi

### Frontend
- **Framework**: React 18
- **Build Tool**: Vite
- **Language**: TypeScript
- **State Management**: Zustand
- **UI Framework**: Material-UI (MUI)
- **HTTP Client**: Axios
- **Routing**: React Router v6
- **Notifications**: React Hot Toast
- **Date Handling**: date-fns

## Project Structure

```
project-management-app/
├── backend/                    # Node.js API server
│   ├── src/
│   │   ├── config/            # Database & JWT config
│   │   ├── controllers/       # Route controllers
│   │   ├── models/            # MongoDB schemas
│   │   ├── routes/            # API routes
│   │   ├── middleware/        # Auth, error handling
│   │   ├── services/          # Business logic
│   │   ├── types/             # TypeScript types
│   │   └── app.ts            # Express app setup
│   └── package.json
│
├── frontend/                   # React app
│   ├── src/
│   │   ├── components/        # React components
│   │   ├── context/           # Zustand stores
│   │   ├── services/          # API client
│   │   ├── types/             # TypeScript types
│   │   ├── App.tsx            # Main app component
│   │   └── main.tsx           # Entry point
│   └── package.json
│
├── docker-compose.yml         # Docker Compose configuration
└── README.md                  # This file
```

## Getting Started

### Prerequisites
- Node.js 18+ and npm
- MongoDB 7.0+ (or Docker)
- Git

### Installation

#### 1. Clone the repository
```bash
git clone <repository-url>
cd project-management-app
```

#### 2. Setup Backend

```bash
cd backend

# Install dependencies
npm install

# Create .env file
cp .env.example .env

# Edit .env with your configuration
# Default MongoDB URI: mongodb://localhost:27017/project-management
# Default Port: 5000
```

#### 3. Setup Frontend

```bash
cd frontend

# Install dependencies
npm install

# Create .env file
cp .env.example .env
# VITE_API_URL=http://localhost:5000/api
```

#### 4. Start MongoDB

**Option A: Using Docker**
```bash
docker run -d \
  --name mongodb \
  -p 27017:27017 \
  -e MONGO_INITDB_ROOT_USERNAME=admin \
  -e MONGO_INITDB_ROOT_PASSWORD=admin \
  mongo:7.0
```

**Option B: Using Docker Compose**
```bash
docker-compose up -d mongodb
```

#### 5. Start the Application

**Terminal 1 - Backend**
```bash
cd backend
npm run dev
# Server runs on http://localhost:5000
```

**Terminal 2 - Frontend**
```bash
cd frontend
npm run dev
# App runs on http://localhost:5173
```

### Test Credentials
```
Email: test@example.com
Password: password123
```

## API Documentation

### Authentication Endpoints

- `POST /api/auth/register` - User registration
- `POST /api/auth/login` - User login
- `POST /api/auth/logout` - User logout
- `POST /api/auth/refresh-token` - Refresh access token
- `GET /api/auth/me` - Get current user
- `PUT /api/auth/profile` - Update user profile

### Project Endpoints

- `GET /api/projects` - List all projects (paginated)
- `POST /api/projects` - Create new project
- `GET /api/projects/:id` - Get project details
- `PUT /api/projects/:id` - Update project
- `DELETE /api/projects/:id` - Delete project
- `GET /api/projects/:id/stats` - Get project statistics

## Development Workflow

### Adding a New Feature

1. Create a new branch:
```bash
git checkout -b feature/your-feature-name
```

2. Make your changes:
   - Update backend models/controllers/routes
   - Update frontend components/services
   - Update TypeScript types

3. Test your changes:
```bash
# Backend
cd backend && npm test

# Frontend
cd frontend && npm run lint
```

4. Commit and push:
```bash
git add .
git commit -m "feat: add your feature description"
git push origin feature/your-feature-name
```

5. Create a pull request

## Build for Production

### Backend
```bash
cd backend
npm run build
npm start
```

### Frontend
```bash
cd frontend
npm run build
npm run preview
```

## Environment Variables

### Backend (.env)
```
PORT=5000
NODE_ENV=development
MONGODB_URI=mongodb://localhost:27017/project-management
JWT_SECRET=your-secret-key
JWT_REFRESH_SECRET=your-refresh-secret
JWT_EXPIRE=15m
JWT_REFRESH_EXPIRE=7d
```

### Frontend (.env)
```
VITE_API_URL=http://localhost:5000/api
```

## Error Handling

The API returns standardized error responses:

```json
{
  "error": "Error message",
  "statusCode": 400,
  "details": {}
}
```

Common HTTP Status Codes:
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `409` - Conflict (e.g., duplicate email)
- `500` - Server Error

## Troubleshooting

### MongoDB Connection Error
- Ensure MongoDB is running: `mongosh` (in terminal)
- Check connection string in .env
- Verify MongoDB credentials

### CORS Error
- Check frontend URL in backend CORS config
- Ensure API is accessible from frontend

### Port Already in Use
```bash
# Find process using port
lsof -i :5000  # Backend
lsof -i :5173  # Frontend

# Kill process
kill -9 <PID>
```

## Future Roadmap

### Phase 2
- Task Management
- Team Collaboration

### Phase 3
- Real-time Features (WebSocket)
- Live Notifications

### Phase 4
- Advanced Analytics
- File Attachments

### Phase 5+
- Mobile App (React Native)
- Advanced Reporting
- AI-powered Features

## Contributing

Contributions are welcome! Please follow the existing code style and submit pull requests.

## License

MIT License - see LICENSE file for details

## Support

For issues and questions:
1. Check the troubleshooting section
2. Review existing GitHub issues
3. Create a new issue with detailed information

---

**Happy coding! 🚀**

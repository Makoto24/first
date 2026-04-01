import http from 'http';
import app from './app.js';
import { connectDB } from './config/database.js';
import dotenv from 'dotenv';

dotenv.config();

const PORT = process.env.PORT || 5000;

const server = http.createServer(app);

const startServer = async () => {
  try {
    // Connect to database
    await connectDB();

    // Start server
    server.listen(PORT, () => {
      console.log(`🚀 Server is running on http://localhost:${PORT}`);
      console.log(`📚 API Docs: http://localhost:${PORT}/api`);
      console.log(`🏥 Health Check: http://localhost:${PORT}/health`);
    });
  } catch (error) {
    console.error('❌ Failed to start server:', error);
    process.exit(1);
  }
};

// Handle graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM signal received: closing HTTP server');
  server.close(() => {
    console.log('HTTP server closed');
    process.exit(0);
  });
});

startServer();

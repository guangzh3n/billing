'use strict';

const connectDB = require('./db/connect');
const config = require('./config');
const app = require('./app');

async function start() {
  await connectDB();

  const server = app.listen(config.PORT, () => {
    console.log(`[tenant-service] Listening on port ${config.PORT} (${config.NODE_ENV})`);
  });

  // Graceful shutdown
  const shutdown = (signal) => {
    console.log(`[tenant-service] ${signal} received, shutting down...`);
    server.close(() => {
      console.log('[tenant-service] HTTP server closed');
      process.exit(0);
    });
  };

  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

start().catch((err) => {
  console.error('[tenant-service] Failed to start:', err);
  process.exit(1);
});

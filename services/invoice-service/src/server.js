'use strict';

const connectDB = require('./db/connect');
const config = require('./config');
const app = require('./app');

async function start() {
  await connectDB();

  const server = app.listen(config.PORT, () => {
    console.log(`[invoice-service] Listening on port ${config.PORT} (${config.NODE_ENV})`);
  });

  // Graceful shutdown
  const shutdown = (signal) => {
    console.log(`[invoice-service] ${signal} received, shutting down...`);
    server.close(() => {
      console.log('[invoice-service] HTTP server closed');
      process.exit(0);
    });
  };

  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

start().catch((err) => {
  console.error('[invoice-service] Failed to start:', err);
  process.exit(1);
});

'use strict';

require('dotenv').config();

const connectDB = require('./db/connect');
const seedSuperAdmin = require('./seeders/superAdmin');
const app = require('./app');
const config = require('./config');

async function start() {
  // 1. Connect to MongoDB (with retry)
  await connectDB();

  // 2. Seed the initial super admin if not present
  await seedSuperAdmin();

  // 3. Start the HTTP server
  app.listen(config.PORT, () => {
    console.log(`[auth-service] Listening on :${config.PORT}`);
  });
}

start().catch((err) => {
  console.error('[auth-service] Fatal startup error:', err);
  process.exit(1);
});

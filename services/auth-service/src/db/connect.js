'use strict';

const mongoose = require('mongoose');
const config = require('../config');

const MAX_RETRIES = 5;
const RETRY_DELAY_MS = 3000;

async function connectWithRetry(attempt = 1) {
  try {
    await mongoose.connect(config.MONGO_URI, {
      serverSelectionTimeoutMS: 5000,
    });
    console.log('[db] MongoDB connected');
  } catch (err) {
    console.error(`[db] Connection attempt ${attempt} failed: ${err.message}`);
    if (attempt < MAX_RETRIES) {
      console.log(`[db] Retrying in ${RETRY_DELAY_MS / 1000}s...`);
      await new Promise((resolve) => setTimeout(resolve, RETRY_DELAY_MS));
      return connectWithRetry(attempt + 1);
    }
    console.error('[db] Max retries reached. Exiting.');
    process.exit(1);
  }
}

mongoose.connection.on('disconnected', () => {
  console.warn('[db] MongoDB disconnected');
});

mongoose.connection.on('reconnected', () => {
  console.log('[db] MongoDB reconnected');
});

module.exports = connectWithRetry;

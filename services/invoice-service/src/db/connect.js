'use strict';

const mongoose = require('mongoose');
const config = require('../config');

const MAX_RETRIES = 5;
const RETRY_INTERVAL_MS = 3000;

async function connectDB(attempt = 1) {
  try {
    await mongoose.connect(config.MONGO_URI, {
      serverSelectionTimeoutMS: 5000,
    });
    console.log(`[invoice-service] MongoDB connected: ${mongoose.connection.host}`);
  } catch (err) {
    console.error(
      `[invoice-service] MongoDB connection error (attempt ${attempt}/${MAX_RETRIES}): ${err.message}`
    );
    if (attempt < MAX_RETRIES) {
      console.log(`[invoice-service] Retrying in ${RETRY_INTERVAL_MS / 1000}s...`);
      await new Promise((resolve) => setTimeout(resolve, RETRY_INTERVAL_MS));
      return connectDB(attempt + 1);
    }
    console.error('[invoice-service] Max retries reached. Exiting.');
    process.exit(1);
  }
}

mongoose.connection.on('disconnected', () => {
  console.warn('[invoice-service] MongoDB disconnected');
});

mongoose.connection.on('error', (err) => {
  console.error('[invoice-service] MongoDB error:', err.message);
});

module.exports = connectDB;

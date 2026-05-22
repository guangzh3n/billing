'use strict';

module.exports = {
  PORT: parseInt(process.env.AUTH_PORT, 10) || 3001,
  NODE_ENV: process.env.NODE_ENV || 'development',

  MONGO_URI: process.env.MONGO_URI || 'mongodb://mongo:27017/billing',

  JWT_ACCESS_SECRET: process.env.JWT_ACCESS_SECRET || 'change_this_access_secret_32chars',
  JWT_REFRESH_SECRET: process.env.JWT_REFRESH_SECRET || 'change_this_refresh_secret_32chars',
  JWT_ACCESS_EXPIRES: process.env.JWT_ACCESS_EXPIRES || '15m',
  JWT_REFRESH_EXPIRES: process.env.JWT_REFRESH_EXPIRES || '7d',

  SUPER_ADMIN_EMAIL: process.env.SUPER_ADMIN_EMAIL || 'admin@billing.com',
  SUPER_ADMIN_PASSWORD: process.env.SUPER_ADMIN_PASSWORD || 'Admin@123456',
  SUPER_ADMIN_NAME: process.env.SUPER_ADMIN_NAME || 'Super Admin',

  REDIS_URL: process.env.REDIS_URL || 'redis://redis:6379',
};

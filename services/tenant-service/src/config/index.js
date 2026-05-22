'use strict';

require('dotenv').config();

module.exports = {
  PORT: process.env.PORT || 3002,
  MONGO_URI: process.env.MONGO_URI || 'mongodb://localhost:27017/billing-tenants',
  NODE_ENV: process.env.NODE_ENV || 'development',
  AUTH_SERVICE_URL: process.env.AUTH_SERVICE_URL || 'http://auth-service:3001',
};

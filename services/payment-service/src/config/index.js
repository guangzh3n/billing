'use strict';

require('dotenv').config();

module.exports = {
  PORT: process.env.PAYMENT_PORT || 3006,
  MONGO_URI: process.env.MONGO_URI || 'mongodb://mongo:27017/billing',
  NODE_ENV: process.env.NODE_ENV || 'development',
  INVOICE_SERVICE_URL: process.env.INVOICE_SERVICE_URL || 'http://invoice-service:3003',
};

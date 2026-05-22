'use strict';

require('dotenv').config();

module.exports = {
  PORT: process.env.CUSTOMER_PORT || 3004,
  MONGO_URI: process.env.MONGO_URI || 'mongodb://mongo:27017/billing',
  NODE_ENV: process.env.NODE_ENV || 'development',
};

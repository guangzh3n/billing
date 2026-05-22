'use strict';

require('dotenv').config();

module.exports = {
  PORT: process.env.PRODUCT_PORT || 3005,
  MONGO_URI: process.env.MONGO_URI || 'mongodb://mongo:27017/billing',
  NODE_ENV: process.env.NODE_ENV || 'development',
};

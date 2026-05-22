'use strict';

require('dotenv').config();

module.exports = {
  PORT: process.env.EINVOICE_PORT || 3007,
  MONGO_URI: process.env.MONGO_URI || 'mongodb://mongo:27017/billing',
  NODE_ENV: process.env.NODE_ENV || 'development',
  TENANT_SERVICE_URL: process.env.TENANT_SERVICE_URL || 'http://tenant-service:3002',
  INVOICE_SERVICE_URL: process.env.INVOICE_SERVICE_URL || 'http://invoice-service:3003',
  MYINVOIS_SANDBOX_BASE: process.env.MYINVOIS_SANDBOX_BASE || 'https://preprod.api.myinvois.hasil.gov.my',
  MYINVOIS_PROD_BASE: process.env.MYINVOIS_PROD_BASE || 'https://api.myinvois.hasil.gov.my',
};

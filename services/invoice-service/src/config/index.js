'use strict';

require('dotenv').config();

module.exports = {
  PORT: process.env.PORT || 3003,
  MONGO_URI: process.env.MONGO_URI || 'mongodb://localhost:27017/billing-invoices',
  NODE_ENV: process.env.NODE_ENV || 'development',
  TENANT_SERVICE_URL: process.env.TENANT_SERVICE_URL || 'http://tenant-service:3002',
  CUSTOMER_SERVICE_URL: process.env.CUSTOMER_SERVICE_URL || 'http://customer-service:3004',
  PRODUCT_SERVICE_URL: process.env.PRODUCT_SERVICE_URL || 'http://product-service:3005',
  PAYMENT_SERVICE_URL: process.env.PAYMENT_SERVICE_URL || 'http://payment-service:3006',
  EINVOICE_SERVICE_URL: process.env.EINVOICE_SERVICE_URL || 'http://einvoice-service:3007',
};

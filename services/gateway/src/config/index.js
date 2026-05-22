'use strict';

module.exports = {
  PORT: parseInt(process.env.GATEWAY_PORT, 10) || 3000,
  NODE_ENV: process.env.NODE_ENV || 'development',

  JWT_ACCESS_SECRET: process.env.JWT_ACCESS_SECRET || 'change_this_access_secret_32chars',

  AUTH_SERVICE_URL: process.env.AUTH_SERVICE_URL || 'http://auth-service:3001',
  TENANT_SERVICE_URL: process.env.TENANT_SERVICE_URL || 'http://tenant-service:3002',
  INVOICE_SERVICE_URL: process.env.INVOICE_SERVICE_URL || 'http://invoice-service:3003',
  CUSTOMER_SERVICE_URL: process.env.CUSTOMER_SERVICE_URL || 'http://customer-service:3004',
  PRODUCT_SERVICE_URL: process.env.PRODUCT_SERVICE_URL || 'http://product-service:3005',
  PAYMENT_SERVICE_URL: process.env.PAYMENT_SERVICE_URL || 'http://payment-service:3006',
  EINVOICE_SERVICE_URL: process.env.EINVOICE_SERVICE_URL || 'http://einvoice-service:3007',
};

'use strict';

const { Router } = require('express');
const { createProxyMiddleware } = require('http-proxy-middleware');
const config = require('../config');

const router = Router();

function makeProxy(target) {
  return createProxyMiddleware({
    target,
    changeOrigin: true,
    on: {
      error(err, req, res) {
        console.error(`[proxy] Error proxying to ${target}:`, err.message);
        if (!res.headersSent) {
          res.status(503).json({
            success: false,
            error: 'Service temporarily unavailable',
            code: 'SERVICE_UNAVAILABLE',
          });
        }
      },
    },
  });
}

// Auth service — public + protected endpoints
router.use('/api/auth', makeProxy(config.AUTH_SERVICE_URL));

// Tenant service
router.use('/api/tenants', makeProxy(config.TENANT_SERVICE_URL));

// Invoice service
router.use('/api/invoices', makeProxy(config.INVOICE_SERVICE_URL));

// Customer service
router.use('/api/customers', makeProxy(config.CUSTOMER_SERVICE_URL));

// Product service
router.use('/api/products', makeProxy(config.PRODUCT_SERVICE_URL));

// Payment service
router.use('/api/payments', makeProxy(config.PAYMENT_SERVICE_URL));

// E-Invoice service
router.use('/api/einvoice', makeProxy(config.EINVOICE_SERVICE_URL));

module.exports = router;

'use strict';

const express = require('express');
const helmet = require('helmet');
const cors = require('cors');
const morgan = require('morgan');

const config = require('./config');
const { generalLimiter, authLimiter } = require('./middleware/rateLimit');
const authMiddleware = require('./middleware/auth');
const proxyRouter = require('./routes/proxy');

const app = express();

// Security headers
app.use(helmet());

// CORS
app.use(
  cors({
    origin: '*',
    methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
  })
);

// Logging
app.use(morgan(config.NODE_ENV === 'production' ? 'combined' : 'dev'));

// Health check — before rate limiters and auth
app.get('/health', (_req, res) => {
  res.json({ status: 'ok', service: 'gateway' });
});

// Rate limiting
app.use(generalLimiter);
app.use('/api/auth', authLimiter);

// JWT authentication for all /api routes
app.use('/api', authMiddleware);

// Proxy routes
app.use(proxyRouter);

// 404 fallback
app.use((_req, res) => {
  res.status(404).json({
    success: false,
    error: 'Route not found',
    code: 'NOT_FOUND',
  });
});

module.exports = app;

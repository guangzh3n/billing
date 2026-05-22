'use strict';

const express = require('express');
const helmet = require('helmet');
const morgan = require('morgan');

const config = require('./config');
const authRoutes = require('./routes/auth.routes');
const errorHandler = require('./middleware/errorHandler');

const app = express();

// Security headers
app.use(helmet());

// Request logging
app.use(morgan(config.NODE_ENV === 'production' ? 'combined' : 'dev'));

// Parse JSON bodies
app.use(express.json());

// Health check (before routes so it is always reachable)
app.get('/health', (_req, res) => {
  res.json({ status: 'ok', service: 'auth-service' });
});

// Auth routes — mounted at /api/auth to match gateway proxy path
app.use('/api/auth', authRoutes);

// Global error handler (must be last)
app.use(errorHandler);

module.exports = app;

'use strict';

const express = require('express');
const helmet = require('helmet');
const morgan = require('morgan');
const productRoutes = require('./routes/product.routes');
const errorHandler = require('./middleware/errorHandler');

const app = express();

app.use(helmet());
app.use(morgan('dev'));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Health check
app.get('/health', (req, res) => {
  res.json({ success: true, data: { status: 'ok', service: 'product-service' } });
});

// Routes
app.use('/api/products', productRoutes);

// 404 handler
app.use((req, res) => {
  res.status(404).json({ success: false, error: 'Route not found', code: 'NOT_FOUND' });
});

// Error handler
app.use(errorHandler);

module.exports = app;

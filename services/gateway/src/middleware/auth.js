'use strict';

const jwt = require('jsonwebtoken');
const config = require('../config');

// Routes that do not require a valid JWT
const PUBLIC_ROUTES = [
  { method: 'POST', path: '/api/auth/login' },
  { method: 'POST', path: '/api/auth/register' },
  { method: 'POST', path: '/api/auth/refresh' },
];

function isPublicRoute(method, path) {
  return PUBLIC_ROUTES.some(
    (r) => r.method === method.toUpperCase() && path.startsWith(r.path)
  );
}

module.exports = function authMiddleware(req, res, next) {
  // Skip auth for public routes
  if (isPublicRoute(req.method, req.path)) {
    return next();
  }

  const authHeader = req.headers['authorization'];
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({
      success: false,
      error: 'Missing or invalid Authorization header',
      code: 'UNAUTHORIZED',
    });
  }

  const token = authHeader.slice(7);

  try {
    const payload = jwt.verify(token, config.JWT_ACCESS_SECRET);

    // Inject identity headers for downstream services
    req.headers['x-user-id'] = payload.userId || '';
    req.headers['x-tenant-id'] = payload.tenantId || '';
    req.headers['x-user-role'] = payload.role || '';

    // Remove the original Authorization header so downstream services rely on
    // the injected headers only (they run on an internal network and trust them)
    // Keeping it is also fine; we leave it so services can optionally inspect it.
    return next();
  } catch (err) {
    return res.status(401).json({
      success: false,
      error: 'Invalid or expired token',
      code: 'TOKEN_INVALID',
    });
  }
};

'use strict';

const { Router } = require('express');
const Joi = require('joi');
const validate = require('../middleware/validate');
const {
  register,
  login,
  refresh,
  logout,
  me,
  changePassword,
} = require('../controllers/auth.controller');

const router = Router();

// ---------------------------------------------------------------------------
// Joi validation schemas
// ---------------------------------------------------------------------------
const registerSchema = Joi.object({
  name: Joi.string().trim().max(100).required(),
  email: Joi.string().email().required(),
  password: Joi.string().min(8).required(),
  tenantId: Joi.string().optional().allow('', null),
  role: Joi.string()
    .valid('super_admin', 'tenant_admin', 'tenant_user')
    .optional(),
});

const loginSchema = Joi.object({
  email: Joi.string().email().required(),
  password: Joi.string().required(),
});

const refreshSchema = Joi.object({
  refreshToken: Joi.string().required(),
});

const changePasswordSchema = Joi.object({
  currentPassword: Joi.string().required(),
  newPassword: Joi.string().min(8).required(),
});

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

// POST /api/auth/register — super_admin only (enforced in controller)
router.post('/register', validate(registerSchema), register);

// POST /api/auth/login — public
router.post('/login', validate(loginSchema), login);

// POST /api/auth/refresh — public (no JWT needed; refresh token in body)
router.post('/refresh', validate(refreshSchema), refresh);

// POST /api/auth/logout — requires x-user-id from gateway
router.post('/logout', logout);

// GET /api/auth/me — requires x-user-id from gateway
router.get('/me', me);

// PUT /api/auth/change-password — requires x-user-id from gateway
router.put('/change-password', validate(changePasswordSchema), changePassword);

module.exports = router;

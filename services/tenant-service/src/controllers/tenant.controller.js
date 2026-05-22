'use strict';

const Joi = require('joi');
const axios = require('axios');
const mongoose = require('mongoose');
const Tenant = require('../models/Tenant');
const config = require('../config');

// ---------------------------------------------------------------------------
// Validation schemas
// ---------------------------------------------------------------------------
const addressJoi = Joi.object({
  street: Joi.string().allow(''),
  city: Joi.string().allow(''),
  postcode: Joi.string().allow(''),
  state: Joi.string().allow(''),
  country: Joi.string().allow(''),
});

const bankJoi = Joi.object({
  name: Joi.string().allow(''),
  accountNumber: Joi.string().allow(''),
  accountHolder: Joi.string().allow(''),
});

const settingsJoi = Joi.object({
  invoicePrefix: Joi.string().max(10),
  currency: Joi.string().length(3),
  taxLabel: Joi.string(),
  defaultTaxRate: Joi.number().min(0).max(100),
});

const einvoiceJoi = Joi.object({
  enabled: Joi.boolean(),
  env: Joi.string().valid('sandbox', 'production'),
  clientId: Joi.string().allow(''),
  clientSecret: Joi.string().allow(''),
});

const createSchema = Joi.object({
  name: Joi.string().max(200).required(),
  tin: Joi.string().allow(''),
  idType: Joi.string().valid('BRN', 'NRIC', 'PASSPORT', 'ARMY'),
  idNumber: Joi.string().allow(''),
  email: Joi.string().email().allow(''),
  phone: Joi.string().allow(''),
  address: addressJoi,
  bank: bankJoi,
  settings: settingsJoi,
  einvoice: einvoiceJoi,
  plan: Joi.string().valid('free', 'basic', 'pro'),
  // Admin bootstrap fields
  adminName: Joi.string(),
  adminEmail: Joi.string().email().required(),
  adminPassword: Joi.string().min(8),
});

const updateSchema = Joi.object({
  name: Joi.string().max(200),
  tin: Joi.string().allow(''),
  idType: Joi.string().valid('BRN', 'NRIC', 'PASSPORT', 'ARMY'),
  idNumber: Joi.string().allow(''),
  email: Joi.string().email().allow(''),
  phone: Joi.string().allow(''),
  address: addressJoi,
  bank: bankJoi,
  plan: Joi.string().valid('free', 'basic', 'pro'),
});

const updateSettingsSchema = Joi.object({
  settings: settingsJoi,
  einvoice: einvoiceJoi,
});

// ---------------------------------------------------------------------------
// Helper: generate random password
// ---------------------------------------------------------------------------
function generatePassword(length = 12) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$';
  let pwd = '';
  for (let i = 0; i < length; i++) {
    pwd += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return pwd;
}

// ---------------------------------------------------------------------------
// Helper: call auth-service to get users for a tenant
// ---------------------------------------------------------------------------
async function fetchTenantUsers(tenantId) {
  const url = `${config.AUTH_SERVICE_URL}/internal/users?tenantId=${tenantId}`;
  const res = await axios.get(url, { timeout: 5000 });
  return res.data;
}

// ---------------------------------------------------------------------------
// Controllers
// ---------------------------------------------------------------------------

/**
 * POST /api/tenants  (super_admin only)
 */
async function create(req, res, next) {
  try {
    const { error, value } = createSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(400).json({
        success: false,
        error: error.details.map((d) => d.message).join(', '),
        code: 'VALIDATION_ERROR',
      });
    }

    const { adminEmail, adminPassword, adminName, ...tenantData } = value;

    // Create tenant
    const tenant = await Tenant.create({
      ...tenantData,
      createdBy: req.user.userId,
    });

    // Bootstrap tenant_admin in auth-service
    const password = adminPassword || generatePassword(12);
    let adminCredentials = { email: adminEmail, password };

    try {
      await axios.post(
        `${config.AUTH_SERVICE_URL}/api/auth/register`,
        {
          name: adminName || tenant.name,
          email: adminEmail,
          password,
          tenantId: tenant._id.toString(),
          role: 'tenant_admin',
        },
        { timeout: 8000 }
      );
    } catch (authErr) {
      // Roll back tenant creation if admin user could not be created
      await Tenant.findByIdAndDelete(tenant._id);
      const msg =
        authErr.response?.data?.error || authErr.message || 'Failed to create admin user';
      return res.status(502).json({ success: false, error: msg, code: 'AUTH_SERVICE_ERROR' });
    }

    return res.status(201).json({
      success: true,
      data: { tenant, adminCredentials },
    });
  } catch (err) {
    next(err);
  }
}

/**
 * GET /api/tenants  (super_admin only)
 */
async function list(req, res, next) {
  try {
    const page = Math.max(1, parseInt(req.query.page, 10) || 1);
    const limit = Math.min(100, Math.max(1, parseInt(req.query.limit, 10) || 20));
    const skip = (page - 1) * limit;

    const filter = {};
    if (req.query.status) filter.status = req.query.status;
    if (req.query.search) {
      const re = new RegExp(req.query.search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
      filter.$or = [{ name: re }, { email: re }];
    }

    const [tenants, total] = await Promise.all([
      Tenant.find(filter).sort({ createdAt: -1 }).skip(skip).limit(limit).lean(),
      Tenant.countDocuments(filter),
    ]);

    return res.json({
      success: true,
      data: { tenants },
      meta: { total, page, limit, totalPages: Math.ceil(total / limit) },
    });
  } catch (err) {
    next(err);
  }
}

/**
 * GET /api/tenants/:id
 * super_admin OR tenant_admin of that tenant
 */
async function getOne(req, res, next) {
  try {
    const { id } = req.params;

    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid tenant ID', code: 'INVALID_ID' });
    }

    // tenant_admin and tenant_user can only see their own tenant
    if (req.user.role !== 'super_admin' && req.user.tenantId !== id) {
      return res.status(403).json({ success: false, error: 'Forbidden', code: 'FORBIDDEN' });
    }

    const tenant = await Tenant.findById(id).lean();
    if (!tenant) {
      return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { tenant } });
  } catch (err) {
    next(err);
  }
}

/**
 * PUT /api/tenants/:id
 * super_admin OR tenant_admin of that tenant
 */
async function update(req, res, next) {
  try {
    const { id } = req.params;

    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid tenant ID', code: 'INVALID_ID' });
    }

    if (req.user.role !== 'super_admin' && req.user.tenantId !== id) {
      return res.status(403).json({ success: false, error: 'Forbidden', code: 'FORBIDDEN' });
    }

    const { error, value } = updateSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(400).json({
        success: false,
        error: error.details.map((d) => d.message).join(', '),
        code: 'VALIDATION_ERROR',
      });
    }

    // Prevent slug change
    delete value.slug;

    const tenant = await Tenant.findByIdAndUpdate(id, { $set: value }, { new: true, runValidators: true });
    if (!tenant) {
      return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { tenant } });
  } catch (err) {
    next(err);
  }
}

/**
 * PUT /api/tenants/:id/settings
 * tenant_admin of that tenant (or super_admin)
 */
async function updateSettings(req, res, next) {
  try {
    const { id } = req.params;

    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid tenant ID', code: 'INVALID_ID' });
    }

    if (req.user.role !== 'super_admin' && req.user.tenantId !== id) {
      return res.status(403).json({ success: false, error: 'Forbidden', code: 'FORBIDDEN' });
    }

    const { error, value } = updateSettingsSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(400).json({
        success: false,
        error: error.details.map((d) => d.message).join(', '),
        code: 'VALIDATION_ERROR',
      });
    }

    const updateOps = {};
    if (value.settings) {
      Object.entries(value.settings).forEach(([k, v]) => {
        updateOps[`settings.${k}`] = v;
      });
    }
    if (value.einvoice) {
      Object.entries(value.einvoice).forEach(([k, v]) => {
        updateOps[`einvoice.${k}`] = v;
      });
    }

    if (Object.keys(updateOps).length === 0) {
      return res.status(400).json({ success: false, error: 'No settings provided', code: 'VALIDATION_ERROR' });
    }

    const tenant = await Tenant.findByIdAndUpdate(id, { $set: updateOps }, { new: true, runValidators: true });
    if (!tenant) {
      return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { tenant } });
  } catch (err) {
    next(err);
  }
}

/**
 * POST /api/tenants/:id/suspend  (super_admin only)
 */
async function suspend(req, res, next) {
  try {
    const { id } = req.params;

    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid tenant ID', code: 'INVALID_ID' });
    }

    const tenant = await Tenant.findByIdAndUpdate(
      id,
      { $set: { status: 'suspended' } },
      { new: true }
    );
    if (!tenant) {
      return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { tenant } });
  } catch (err) {
    next(err);
  }
}

/**
 * POST /api/tenants/:id/activate  (super_admin only)
 */
async function activate(req, res, next) {
  try {
    const { id } = req.params;

    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid tenant ID', code: 'INVALID_ID' });
    }

    const tenant = await Tenant.findByIdAndUpdate(
      id,
      { $set: { status: 'active' } },
      { new: true }
    );
    if (!tenant) {
      return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { tenant } });
  } catch (err) {
    next(err);
  }
}

/**
 * GET /api/tenants/stats  (super_admin only)
 */
async function getStats(req, res, next) {
  try {
    const stats = await Tenant.aggregate([
      {
        $group: {
          _id: '$status',
          count: { $sum: 1 },
        },
      },
    ]);

    const byStatus = {};
    let total = 0;
    stats.forEach((s) => {
      byStatus[s._id] = s.count;
      total += s.count;
    });

    const planStats = await Tenant.aggregate([
      {
        $group: {
          _id: '$plan',
          count: { $sum: 1 },
        },
      },
    ]);

    const byPlan = {};
    planStats.forEach((s) => {
      byPlan[s._id] = s.count;
    });

    return res.json({
      success: true,
      data: {
        total,
        byStatus: {
          active: byStatus.active || 0,
          suspended: byStatus.suspended || 0,
          inactive: byStatus.inactive || 0,
        },
        byPlan: {
          free: byPlan.free || 0,
          basic: byPlan.basic || 0,
          pro: byPlan.pro || 0,
        },
      },
    });
  } catch (err) {
    next(err);
  }
}

/**
 * GET /api/tenants/:id/users
 * super_admin or tenant_admin for own tenant
 */
async function listUsers(req, res, next) {
  try {
    const { id } = req.params;

    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid tenant ID', code: 'INVALID_ID' });
    }

    if (req.user.role !== 'super_admin' && req.user.tenantId !== id) {
      return res.status(403).json({ success: false, error: 'Forbidden', code: 'FORBIDDEN' });
    }

    let result;
    try {
      result = await fetchTenantUsers(id);
    } catch (authErr) {
      const msg = authErr.response?.data?.error || authErr.message || 'Failed to fetch users';
      return res.status(502).json({ success: false, error: msg, code: 'AUTH_SERVICE_ERROR' });
    }

    return res.json({ success: true, data: result.data || result });
  } catch (err) {
    next(err);
  }
}

module.exports = { create, list, getOne, update, updateSettings, suspend, activate, getStats, listUsers };

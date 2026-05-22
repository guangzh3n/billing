'use strict';

const mongoose = require('mongoose');
const Joi = require('joi');
const Customer = require('../models/Customer');

// ---------------------------------------------------------------------------
// Validation schemas
// ---------------------------------------------------------------------------
const createSchema = Joi.object({
  name: Joi.string().trim().max(200).required(),
  tin: Joi.string().trim().allow('', null),
  idType: Joi.string().valid('BRN', 'NRIC', 'PASSPORT', 'ARMY').default('BRN'),
  idNumber: Joi.string().trim().allow('', null),
  email: Joi.string().trim().email().lowercase().allow('', null),
  phone: Joi.string().trim().allow('', null),
  address: Joi.object({
    street: Joi.string().trim().allow('', null),
    city: Joi.string().trim().allow('', null),
    postcode: Joi.string().trim().allow('', null),
    state: Joi.string().trim().allow('', null),
    country: Joi.string().trim().default('Malaysia'),
  }).default({}),
});

const updateSchema = Joi.object({
  name: Joi.string().trim().max(200),
  tin: Joi.string().trim().allow('', null),
  idType: Joi.string().valid('BRN', 'NRIC', 'PASSPORT', 'ARMY'),
  idNumber: Joi.string().trim().allow('', null),
  email: Joi.string().trim().email().lowercase().allow('', null),
  phone: Joi.string().trim().allow('', null),
  address: Joi.object({
    street: Joi.string().trim().allow('', null),
    city: Joi.string().trim().allow('', null),
    postcode: Joi.string().trim().allow('', null),
    state: Joi.string().trim().allow('', null),
    country: Joi.string().trim().allow('', null),
  }),
  isActive: Joi.boolean(),
});

// ---------------------------------------------------------------------------
// list  GET /
// ---------------------------------------------------------------------------
exports.list = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    const page = Math.max(1, parseInt(req.query.page, 10) || 1);
    const limit = Math.min(100, Math.max(1, parseInt(req.query.limit, 10) || 20));
    const skip = (page - 1) * limit;

    const filter = { tenantId: new mongoose.Types.ObjectId(tenantId) };

    if (req.query.search) {
      const re = new RegExp(req.query.search.trim().replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
      filter.$or = [{ name: re }, { email: re }, { tin: re }];
    }

    if (req.query.isActive !== undefined) {
      filter.isActive = req.query.isActive === 'true';
    }

    const [customers, total] = await Promise.all([
      Customer.find(filter).sort({ name: 1 }).skip(skip).limit(limit).lean(),
      Customer.countDocuments(filter),
    ]);

    return res.json({
      success: true,
      data: { customers },
      meta: {
        total,
        page,
        limit,
        totalPages: Math.ceil(total / limit),
      },
    });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// create  POST /
// ---------------------------------------------------------------------------
exports.create = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    const { error, value } = createSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(422).json({
        success: false,
        error: error.details.map((d) => d.message).join('; '),
        code: 'VALIDATION_ERROR',
      });
    }

    // Duplicate email check
    if (value.email) {
      const exists = await Customer.findOne({
        tenantId: new mongoose.Types.ObjectId(tenantId),
        email: value.email,
      });
      if (exists) {
        return res.status(409).json({
          success: false,
          error: 'A customer with this email already exists',
          code: 'DUPLICATE_EMAIL',
        });
      }
    }

    const customer = await Customer.create({
      ...value,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    });

    return res.status(201).json({ success: true, data: { customer } });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// getOne  GET /:id
// ---------------------------------------------------------------------------
exports.getOne = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    if (!mongoose.Types.ObjectId.isValid(req.params.id)) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    const customer = await Customer.findOne({
      _id: req.params.id,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    }).lean();

    if (!customer) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { customer } });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// update  PUT /:id
// ---------------------------------------------------------------------------
exports.update = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    if (!mongoose.Types.ObjectId.isValid(req.params.id)) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    const { error, value } = updateSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(422).json({
        success: false,
        error: error.details.map((d) => d.message).join('; '),
        code: 'VALIDATION_ERROR',
      });
    }

    const existing = await Customer.findOne({
      _id: req.params.id,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    });

    if (!existing) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    // Duplicate email check if email is being changed
    if (value.email && value.email !== existing.email) {
      const dup = await Customer.findOne({
        tenantId: new mongoose.Types.ObjectId(tenantId),
        email: value.email,
        _id: { $ne: existing._id },
      });
      if (dup) {
        return res.status(409).json({
          success: false,
          error: 'A customer with this email already exists',
          code: 'DUPLICATE_EMAIL',
        });
      }
    }

    Object.assign(existing, value);
    await existing.save();

    return res.json({ success: true, data: { customer: existing.toObject() } });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// softDelete  DELETE /:id
// ---------------------------------------------------------------------------
exports.softDelete = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    if (!mongoose.Types.ObjectId.isValid(req.params.id)) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    const customer = await Customer.findOne({
      _id: req.params.id,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    });

    if (!customer) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    customer.isActive = false;
    await customer.save();

    return res.json({ success: true, data: { message: 'Customer deactivated successfully' } });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// getForInvoice  GET /internal/:id  (no auth — internal only)
// ---------------------------------------------------------------------------
exports.getForInvoice = async (req, res, next) => {
  try {
    if (!mongoose.Types.ObjectId.isValid(req.params.id)) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    const customer = await Customer.findById(req.params.id)
      .select('name tin idType idNumber email phone address')
      .lean();

    if (!customer) {
      return res.status(404).json({ success: false, error: 'Customer not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { customer } });
  } catch (err) {
    next(err);
  }
};

'use strict';

const mongoose = require('mongoose');
const Joi = require('joi');
const Product = require('../models/Product');

// ---------------------------------------------------------------------------
// Validation schemas
// ---------------------------------------------------------------------------
const createSchema = Joi.object({
  name: Joi.string().trim().max(200).required(),
  description: Joi.string().trim().allow('', null),
  unitPrice: Joi.number().min(0).default(0),
  taxType: Joi.string().trim().default('E'),
  taxRate: Joi.number().min(0).max(100).default(0),
  classification: Joi.string().trim().default('022'),
  unit: Joi.string().trim().default('UNIT'),
  isActive: Joi.boolean().default(true),
});

const updateSchema = Joi.object({
  name: Joi.string().trim().max(200),
  description: Joi.string().trim().allow('', null),
  unitPrice: Joi.number().min(0),
  taxType: Joi.string().trim(),
  taxRate: Joi.number().min(0).max(100),
  classification: Joi.string().trim(),
  unit: Joi.string().trim(),
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
      filter.name = re;
    }

    if (req.query.isActive !== undefined) {
      filter.isActive = req.query.isActive === 'true';
    }

    const [products, total] = await Promise.all([
      Product.find(filter).sort({ name: 1 }).skip(skip).limit(limit).lean(),
      Product.countDocuments(filter),
    ]);

    return res.json({
      success: true,
      data: { products },
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

    const product = await Product.create({
      ...value,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    });

    return res.status(201).json({ success: true, data: { product } });
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
      return res.status(404).json({ success: false, error: 'Product not found', code: 'NOT_FOUND' });
    }

    const product = await Product.findOne({
      _id: req.params.id,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    }).lean();

    if (!product) {
      return res.status(404).json({ success: false, error: 'Product not found', code: 'NOT_FOUND' });
    }

    return res.json({ success: true, data: { product } });
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
      return res.status(404).json({ success: false, error: 'Product not found', code: 'NOT_FOUND' });
    }

    const { error, value } = updateSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(422).json({
        success: false,
        error: error.details.map((d) => d.message).join('; '),
        code: 'VALIDATION_ERROR',
      });
    }

    const product = await Product.findOne({
      _id: req.params.id,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    });

    if (!product) {
      return res.status(404).json({ success: false, error: 'Product not found', code: 'NOT_FOUND' });
    }

    Object.assign(product, value);
    await product.save();

    return res.json({ success: true, data: { product: product.toObject() } });
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
      return res.status(404).json({ success: false, error: 'Product not found', code: 'NOT_FOUND' });
    }

    const product = await Product.findOne({
      _id: req.params.id,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    });

    if (!product) {
      return res.status(404).json({ success: false, error: 'Product not found', code: 'NOT_FOUND' });
    }

    product.isActive = false;
    await product.save();

    return res.json({ success: true, data: { message: 'Product deactivated successfully' } });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// listForInvoice  GET /internal/list?tenantId=xxx  (no auth — internal only)
// ---------------------------------------------------------------------------
exports.listForInvoice = async (req, res, next) => {
  try {
    const { tenantId } = req.query;
    if (!tenantId || !mongoose.Types.ObjectId.isValid(tenantId)) {
      return res.status(400).json({ success: false, error: 'Valid tenantId query param required', code: 'MISSING_TENANT' });
    }

    const products = await Product.find({
      tenantId: new mongoose.Types.ObjectId(tenantId),
      isActive: true,
    })
      .select('name description unitPrice taxType taxRate classification unit')
      .sort({ name: 1 })
      .lean();

    return res.json({ success: true, data: { products } });
  } catch (err) {
    next(err);
  }
};

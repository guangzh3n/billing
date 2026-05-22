'use strict';

const Joi = require('joi');
const axios = require('axios');
const mongoose = require('mongoose');
const Invoice = require('../models/Invoice');
const config = require('../config');
const {
  createInvoice,
  getInvoiceWithBalance,
  buildInvoiceFilter,
  internalHeaders,
} = require('../services/invoice.service');

// ---------------------------------------------------------------------------
// Joi schemas
// ---------------------------------------------------------------------------

const itemSchema = Joi.object({
  productId: Joi.string().allow('', null),
  description: Joi.string().required(),
  quantity: Joi.number().min(0.0001).required(),
  unitPrice: Joi.number().min(0).required(),
  unit: Joi.string().default('UNIT'),
  discountRate: Joi.number().min(0).max(100).default(0),
  taxType: Joi.string().default('E'),
  taxRate: Joi.number().min(0).max(100).default(0),
  classification: Joi.string().default('022'),
});

const createSchema = Joi.object({
  customerId: Joi.string().required(),
  invoiceType: Joi.string().valid('01', '02', '03', '04', '11', '12', '13', '14').default('01'),
  issueDate: Joi.date().iso(),
  dueDate: Joi.date().iso().allow(null),
  currency: Joi.string().length(3),
  items: Joi.array().items(itemSchema).min(1).required(),
  notes: Joi.string().allow('', null),
});

const updateSchema = Joi.object({
  customerId: Joi.string(),
  invoiceType: Joi.string().valid('01', '02', '03', '04', '11', '12', '13', '14'),
  issueDate: Joi.date().iso(),
  dueDate: Joi.date().iso().allow(null),
  currency: Joi.string().length(3),
  items: Joi.array().items(itemSchema).min(1),
  notes: Joi.string().allow('', null),
});

const ALLOWED_STATUSES = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
const STATUS_SELF_SERVICE = ['draft', 'sent', 'overdue', 'cancelled']; // tenant users cannot set 'paid' directly

// ---------------------------------------------------------------------------
// GET /api/invoices
// ---------------------------------------------------------------------------
async function list(req, res, next) {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'Tenant context required', code: 'NO_TENANT' });
    }

    const page = Math.max(1, parseInt(req.query.page, 10) || 1);
    const limit = Math.min(100, Math.max(1, parseInt(req.query.limit, 10) || 20));
    const skip = (page - 1) * limit;

    const filter = buildInvoiceFilter(tenantId, req.query);

    const [invoices, total] = await Promise.all([
      Invoice.find(filter).sort({ issueDate: -1 }).skip(skip).limit(limit).lean(),
      Invoice.countDocuments(filter),
    ]);

    // Optionally enrich with balance (opt-in to avoid N+1 for large lists)
    let enrichedInvoices = invoices;
    if (req.query.withBalance === 'true') {
      enrichedInvoices = await Promise.all(
        invoices.map((inv) => {
          // getInvoiceWithBalance expects a mongoose doc or plain obj with toObject
          const pseudo = { ...inv, toObject: () => inv };
          return getInvoiceWithBalance(pseudo, tenantId);
        })
      );
    }

    return res.json({
      success: true,
      data: { invoices: enrichedInvoices },
      meta: { total, page, limit, totalPages: Math.ceil(total / limit) },
    });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// POST /api/invoices
// ---------------------------------------------------------------------------
async function create(req, res, next) {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'Tenant context required', code: 'NO_TENANT' });
    }

    const { error, value } = createSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(400).json({
        success: false,
        error: error.details.map((d) => d.message).join(', '),
        code: 'VALIDATION_ERROR',
      });
    }

    const invoice = await createInvoice(tenantId, req.user.userId, value);

    return res.status(201).json({ success: true, data: { invoice } });
  } catch (err) {
    // Surface service-layer errors with proper HTTP codes
    if (err.status) {
      return res.status(err.status).json({ success: false, error: err.message, code: err.code });
    }
    next(err);
  }
}

// ---------------------------------------------------------------------------
// GET /api/invoices/:id
// ---------------------------------------------------------------------------
async function getOne(req, res, next) {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'Tenant context required', code: 'NO_TENANT' });
    }

    const { id } = req.params;
    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid invoice ID', code: 'INVALID_ID' });
    }

    const invoice = await Invoice.findOne({ _id: id, tenantId });
    if (!invoice) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    const enriched = await getInvoiceWithBalance(invoice, tenantId);

    return res.json({ success: true, data: { invoice: enriched } });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// PUT /api/invoices/:id
// ---------------------------------------------------------------------------
async function update(req, res, next) {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'Tenant context required', code: 'NO_TENANT' });
    }

    const { id } = req.params;
    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid invoice ID', code: 'INVALID_ID' });
    }

    const invoice = await Invoice.findOne({ _id: id, tenantId });
    if (!invoice) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    if (invoice.status !== 'draft') {
      return res.status(400).json({
        success: false,
        error: 'Only draft invoices can be updated',
        code: 'INVALID_STATUS',
      });
    }

    const { error, value } = updateSchema.validate(req.body, { abortEarly: false });
    if (error) {
      return res.status(400).json({
        success: false,
        error: error.details.map((d) => d.message).join(', '),
        code: 'VALIDATION_ERROR',
      });
    }

    // Apply updates to the mongoose document so pre-save hook recalculates totals
    Object.assign(invoice, value);
    await invoice.save();

    return res.json({ success: true, data: { invoice } });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// PATCH /api/invoices/:id/status
// ---------------------------------------------------------------------------
async function updateStatus(req, res, next) {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'Tenant context required', code: 'NO_TENANT' });
    }

    const { id } = req.params;
    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid invoice ID', code: 'INVALID_ID' });
    }

    const { status } = req.body;
    if (!status || !ALLOWED_STATUSES.includes(status)) {
      return res.status(400).json({
        success: false,
        error: `Status must be one of: ${ALLOWED_STATUSES.join(', ')}`,
        code: 'VALIDATION_ERROR',
      });
    }

    // Only super_admin and tenant_admin can cancel; tenant_user cannot set 'paid' directly
    if (status === 'paid') {
      return res.status(400).json({
        success: false,
        error: 'Use the payment endpoint to mark an invoice as paid',
        code: 'USE_PAYMENT_ENDPOINT',
      });
    }

    if (status === 'cancelled' && !['super_admin', 'tenant_admin'].includes(req.user.role)) {
      return res.status(403).json({ success: false, error: 'Forbidden', code: 'FORBIDDEN' });
    }

    const invoice = await Invoice.findOne({ _id: id, tenantId });
    if (!invoice) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    if (invoice.status === 'cancelled') {
      return res.status(400).json({
        success: false,
        error: 'Cancelled invoices cannot be updated',
        code: 'INVOICE_CANCELLED',
      });
    }

    invoice.status = status;
    // Use findOneAndUpdate to bypass pre-save total recalculation for status-only change
    const updated = await Invoice.findOneAndUpdate(
      { _id: id, tenantId },
      { $set: { status } },
      { new: true }
    );

    return res.json({ success: true, data: { invoice: updated } });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// DELETE /api/invoices/:id
// ---------------------------------------------------------------------------
async function deleteInvoice(req, res, next) {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'Tenant context required', code: 'NO_TENANT' });
    }

    const { id } = req.params;
    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid invoice ID', code: 'INVALID_ID' });
    }

    const invoice = await Invoice.findOne({ _id: id, tenantId });
    if (!invoice) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    if (invoice.status !== 'draft') {
      return res.status(400).json({
        success: false,
        error: 'Only draft invoices can be deleted',
        code: 'INVALID_STATUS',
      });
    }

    await Invoice.findOneAndDelete({ _id: id, tenantId });

    return res.json({ success: true, data: { message: 'Invoice deleted' } });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// GET /api/invoices/stats
// ---------------------------------------------------------------------------
async function getDashboardStats(req, res, next) {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'Tenant context required', code: 'NO_TENANT' });
    }

    const tenantObjId = new mongoose.Types.ObjectId(tenantId);

    const now = new Date();
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    const endOfMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59, 999);

    // Basic counts
    const [totalCount, pendingAgg, overdueCount, paidThisMonthAgg] = await Promise.all([
      Invoice.countDocuments({ tenantId: tenantObjId }),
      Invoice.aggregate([
        { $match: { tenantId: tenantObjId, status: { $in: ['sent', 'overdue'] } } },
        { $group: { _id: null, total: { $sum: '$totalAmount' } } },
      ]),
      Invoice.countDocuments({ tenantId: tenantObjId, status: 'overdue' }),
      Invoice.aggregate([
        {
          $match: {
            tenantId: tenantObjId,
            status: 'paid',
            issueDate: { $gte: startOfMonth, $lte: endOfMonth },
          },
        },
        { $group: { _id: null, total: { $sum: '$totalAmount' } } },
      ]),
    ]);

    const pendingAmount = pendingAgg[0]?.total || 0;
    const paidThisMonth = paidThisMonthAgg[0]?.total || 0;

    // Monthly revenue — last 6 months (based on invoice totalAmount where status=paid)
    const sixMonthsAgo = new Date(now.getFullYear(), now.getMonth() - 5, 1);

    const monthlyRevenue = await Invoice.aggregate([
      {
        $match: {
          tenantId: tenantObjId,
          status: 'paid',
          issueDate: { $gte: sixMonthsAgo },
        },
      },
      {
        $group: {
          _id: {
            year: { $year: '$issueDate' },
            month: { $month: '$issueDate' },
          },
          revenue: { $sum: '$totalAmount' },
          count: { $sum: 1 },
        },
      },
      { $sort: { '_id.year': 1, '_id.month': 1 } },
    ]);

    // Enrich monthly revenue with payment-service data (best-effort)
    let paymentMonthlyData = [];
    try {
      const paymentsUrl = `${config.PAYMENT_SERVICE_URL}/api/payments/stats/monthly?tenantId=${tenantId}&months=6`;
      const payResp = await axios.get(paymentsUrl, {
        headers: internalHeaders(tenantId),
        timeout: 5000,
      });
      paymentMonthlyData = payResp.data.data?.monthly || [];
    } catch (err) {
      console.warn('[invoice-service] Could not fetch payment monthly stats:', err.message);
    }

    return res.json({
      success: true,
      data: {
        totalInvoices: totalCount,
        pendingAmount: Math.round(pendingAmount * 100) / 100,
        paidThisMonth: Math.round(paidThisMonth * 100) / 100,
        overdueCount,
        monthlyRevenue: monthlyRevenue.map((m) => ({
          year: m._id.year,
          month: m._id.month,
          revenue: Math.round(m.revenue * 100) / 100,
          count: m.count,
        })),
        paymentMonthlyData,
      },
    });
  } catch (err) {
    next(err);
  }
}

module.exports = {
  list,
  create,
  getOne,
  update,
  updateStatus,
  delete: deleteInvoice,
  getDashboardStats,
};

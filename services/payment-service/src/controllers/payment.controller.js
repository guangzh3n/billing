'use strict';

const mongoose = require('mongoose');
const axios = require('axios');
const Joi = require('joi');
const Payment = require('../models/Payment');
const config = require('../config');

// ---------------------------------------------------------------------------
// Validation schema
// ---------------------------------------------------------------------------
const createSchema = Joi.object({
  invoiceId: Joi.string().required(),
  amount: Joi.number().min(0.01).required(),
  paymentDate: Joi.date().iso().default(() => new Date()),
  paymentMethod: Joi.string()
    .valid('cash', 'bank_transfer', 'cheque', 'credit_card', 'fpx', 'duitnow', 'other')
    .default('bank_transfer'),
  referenceNumber: Joi.string().trim().allow('', null),
  notes: Joi.string().trim().allow('', null),
});

// ---------------------------------------------------------------------------
// Helper: forward auth headers when calling invoice-service
// ---------------------------------------------------------------------------
function authHeaders(req) {
  return {
    'x-user-id': req.headers['x-user-id'],
    'x-tenant-id': req.headers['x-tenant-id'],
    'x-user-role': req.headers['x-user-role'],
  };
}

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

    if (req.query.invoiceId && mongoose.Types.ObjectId.isValid(req.query.invoiceId)) {
      filter.invoiceId = new mongoose.Types.ObjectId(req.query.invoiceId);
    }

    const [payments, total] = await Promise.all([
      Payment.find(filter).sort({ paymentDate: -1 }).skip(skip).limit(limit).lean(),
      Payment.countDocuments(filter),
    ]);

    return res.json({
      success: true,
      data: { payments },
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

    if (!mongoose.Types.ObjectId.isValid(value.invoiceId)) {
      return res.status(422).json({ success: false, error: 'Invalid invoiceId', code: 'VALIDATION_ERROR' });
    }

    // Verify invoice belongs to this tenant
    let invoice;
    try {
      const invoiceResp = await axios.get(
        `${config.INVOICE_SERVICE_URL}/api/invoices/${value.invoiceId}`,
        { headers: authHeaders(req), timeout: 10000 }
      );
      invoice = invoiceResp.data.data?.invoice || invoiceResp.data.data;
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!invoice || String(invoice.tenantId) !== String(tenantId)) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    if (invoice.status === 'cancelled') {
      return res.status(400).json({ success: false, error: 'Cannot add payment to a cancelled invoice', code: 'INVOICE_CANCELLED' });
    }

    // Calculate total already paid for this invoice
    const aggResult = await Payment.aggregate([
      { $match: { invoiceId: new mongoose.Types.ObjectId(value.invoiceId) } },
      { $group: { _id: null, totalPaid: { $sum: '$amount' } } },
    ]);
    const totalPaid = aggResult.length > 0 ? aggResult[0].totalPaid : 0;
    const balance = invoice.totalAmount - totalPaid;

    if (value.amount > balance + 0.01) {
      return res.status(400).json({
        success: false,
        error: `Payment amount (${value.amount.toFixed(2)}) exceeds outstanding balance (${balance.toFixed(2)})`,
        code: 'AMOUNT_EXCEEDS_BALANCE',
      });
    }

    const payment = await Payment.create({
      ...value,
      invoiceId: new mongoose.Types.ObjectId(value.invoiceId),
      tenantId: new mongoose.Types.ObjectId(tenantId),
      createdBy: req.user.userId ? new mongoose.Types.ObjectId(req.user.userId) : undefined,
    });

    // Update invoice status to 'paid' if fully settled
    const newTotalPaid = totalPaid + value.amount;
    if (newTotalPaid >= invoice.totalAmount - 0.01) {
      try {
        await axios.patch(
          `${config.INVOICE_SERVICE_URL}/api/invoices/${value.invoiceId}/status`,
          { status: 'paid' },
          { headers: authHeaders(req), timeout: 10000 }
        );
      } catch (patchErr) {
        // Non-fatal — log but don't fail the payment creation
        console.error('Failed to update invoice status to paid:', patchErr.message);
      }
    }

    return res.status(201).json({ success: true, data: { payment } });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// getByInvoice  GET /invoice/:invoiceId
// ---------------------------------------------------------------------------
exports.getByInvoice = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    if (!mongoose.Types.ObjectId.isValid(req.params.invoiceId)) {
      return res.status(400).json({ success: false, error: 'Invalid invoiceId', code: 'VALIDATION_ERROR' });
    }

    // Verify invoice belongs to tenant
    let invoice;
    try {
      const invoiceResp = await axios.get(
        `${config.INVOICE_SERVICE_URL}/api/invoices/${req.params.invoiceId}`,
        { headers: authHeaders(req), timeout: 10000 }
      );
      invoice = invoiceResp.data.data?.invoice || invoiceResp.data.data;
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!invoice || String(invoice.tenantId) !== String(tenantId)) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    const payments = await Payment.find({
      invoiceId: new mongoose.Types.ObjectId(req.params.invoiceId),
      tenantId: new mongoose.Types.ObjectId(tenantId),
    })
      .sort({ paymentDate: -1 })
      .lean();

    const totalPaid = payments.reduce((sum, p) => sum + p.amount, 0);
    const balance = Math.max(0, invoice.totalAmount - totalPaid);

    return res.json({
      success: true,
      data: {
        payments,
        summary: {
          invoiceTotal: invoice.totalAmount,
          totalPaid: Math.round(totalPaid * 100) / 100,
          balance: Math.round(balance * 100) / 100,
        },
      },
    });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// delete  DELETE /:id
// ---------------------------------------------------------------------------
exports.delete = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    if (!mongoose.Types.ObjectId.isValid(req.params.id)) {
      return res.status(404).json({ success: false, error: 'Payment not found', code: 'NOT_FOUND' });
    }

    const payment = await Payment.findOne({
      _id: req.params.id,
      tenantId: new mongoose.Types.ObjectId(tenantId),
    });

    if (!payment) {
      return res.status(404).json({ success: false, error: 'Payment not found', code: 'NOT_FOUND' });
    }

    const invoiceId = payment.invoiceId;
    await payment.deleteOne();

    // Recalculate total paid; if invoice was 'paid' and now under-paid, revert to 'sent'
    try {
      const invoiceResp = await axios.get(
        `${config.INVOICE_SERVICE_URL}/api/invoices/${invoiceId}`,
        { headers: authHeaders(req), timeout: 10000 }
      );
      const invoice = invoiceResp.data.data?.invoice || invoiceResp.data.data;

      if (invoice && invoice.status === 'paid') {
        const aggResult = await Payment.aggregate([
          { $match: { invoiceId: new mongoose.Types.ObjectId(String(invoiceId)) } },
          { $group: { _id: null, totalPaid: { $sum: '$amount' } } },
        ]);
        const remainingPaid = aggResult.length > 0 ? aggResult[0].totalPaid : 0;

        if (remainingPaid < invoice.totalAmount - 0.01) {
          await axios.patch(
            `${config.INVOICE_SERVICE_URL}/api/invoices/${invoiceId}/status`,
            { status: 'sent' },
            { headers: authHeaders(req), timeout: 10000 }
          );
        }
      }
    } catch (recalcErr) {
      // Non-fatal
      console.error('Failed to recalculate invoice status after payment deletion:', recalcErr.message);
    }

    return res.json({ success: true, data: { message: 'Payment deleted successfully' } });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// getMonthlyRevenue  GET /reports/monthly
// ---------------------------------------------------------------------------
exports.getMonthlyRevenue = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    // Last 12 months from today
    const now = new Date();
    const from = new Date(now.getFullYear(), now.getMonth() - 11, 1); // start of month 12 months ago

    const results = await Payment.aggregate([
      {
        $match: {
          tenantId: new mongoose.Types.ObjectId(tenantId),
          paymentDate: { $gte: from },
        },
      },
      {
        $group: {
          _id: {
            year: { $year: '$paymentDate' },
            month: { $month: '$paymentDate' },
          },
          total: { $sum: '$amount' },
        },
      },
      { $sort: { '_id.year': 1, '_id.month': 1 } },
    ]);

    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // Build full 12-month array, filling gaps with zero
    const monthlyData = [];
    for (let i = 11; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      const year = d.getFullYear();
      const month = d.getMonth() + 1; // 1-based
      const found = results.find((r) => r._id.year === year && r._id.month === month);
      monthlyData.push({
        year,
        month,
        label: `${monthNames[month - 1]} ${year}`,
        total: found ? Math.round(found.total * 100) / 100 : 0,
      });
    }

    return res.json({ success: true, data: { monthly: monthlyData } });
  } catch (err) {
    next(err);
  }
};

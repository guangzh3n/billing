'use strict';

const { Router } = require('express');
const mongoose = require('mongoose');
const auth = require('../middleware/auth');
const Tenant = require('../models/Tenant');

const router = Router({ mergeParams: true });

/**
 * POST /api/tenants/:id/invoice-number
 * Atomically increments and returns the next formatted invoice number.
 * Called internally by invoice-service; also accessible via the gateway for tenant users.
 */
router.post('/', auth, async (req, res, next) => {
  try {
    const { id } = req.params;

    if (!mongoose.isValidObjectId(id)) {
      return res.status(400).json({ success: false, error: 'Invalid tenant ID', code: 'INVALID_ID' });
    }

    // Only allow requests scoped to the tenant or super_admin
    if (req.user.role !== 'super_admin' && req.user.tenantId !== id) {
      return res.status(403).json({ success: false, error: 'Forbidden', code: 'FORBIDDEN' });
    }

    const invoiceNumber = await Tenant.getNextInvoiceNumber(id);
    return res.json({ success: true, data: { invoiceNumber } });
  } catch (err) {
    next(err);
  }
});

module.exports = router;

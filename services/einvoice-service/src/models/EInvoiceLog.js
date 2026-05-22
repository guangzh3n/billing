'use strict';

const mongoose = require('mongoose');
const { Schema } = mongoose;

const einvoiceLogSchema = new Schema({
  tenantId: { type: Schema.Types.ObjectId, required: true, index: true },
  invoiceId: { type: Schema.Types.ObjectId, required: true },
  invoiceNumber: { type: String, trim: true },
  action: {
    type: String,
    enum: ['submit', 'status_check', 'cancel'],
    default: 'submit',
  },
  status: { type: String, trim: true },
  request: { type: Schema.Types.Mixed }, // request payload summary (no secrets)
  response: { type: Schema.Types.Mixed }, // API response
  error: { type: String, trim: true },
  createdAt: { type: Date, default: Date.now },
});

module.exports = mongoose.model('EInvoiceLog', einvoiceLogSchema);

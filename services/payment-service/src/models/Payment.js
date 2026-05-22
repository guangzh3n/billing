'use strict';

const mongoose = require('mongoose');
const { Schema } = mongoose;

const paymentSchema = new Schema(
  {
    tenantId: { type: Schema.Types.ObjectId, required: true, index: true },
    invoiceId: { type: Schema.Types.ObjectId, required: true, index: true },
    amount: { type: Number, required: true, min: 0.01 },
    paymentDate: { type: Date, required: true, default: Date.now },
    paymentMethod: {
      type: String,
      enum: ['cash', 'bank_transfer', 'cheque', 'credit_card', 'fpx', 'duitnow', 'other'],
      default: 'bank_transfer',
    },
    referenceNumber: { type: String, trim: true },
    notes: { type: String, trim: true },
    createdBy: { type: Schema.Types.ObjectId },
  },
  { timestamps: true }
);

paymentSchema.index({ tenantId: 1, invoiceId: 1 });
paymentSchema.index({ tenantId: 1, paymentDate: -1 });

module.exports = mongoose.model('Payment', paymentSchema);

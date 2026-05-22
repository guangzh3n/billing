'use strict';

const mongoose = require('mongoose');
const { Schema } = mongoose;

const productSchema = new Schema(
  {
    tenantId: { type: Schema.Types.ObjectId, required: true, index: true },
    name: { type: String, required: true, trim: true, maxlength: 200 },
    description: { type: String, trim: true },
    unitPrice: { type: Number, required: true, min: 0, default: 0 },
    taxType: {
      type: String,
      trim: true,
      default: 'E',
      // Common Malaysia tax types: E (Exempt), OE (Out of Scope), AE (Reverse Charge),
      // 01 (Sales Tax 5%), 02 (Sales Tax 10%), 03 (Tourism Tax), 04 (High Value Goods)
    },
    taxRate: { type: Number, default: 0, min: 0, max: 100 },
    classification: { type: String, trim: true, default: '022' }, // MyInvois classification code
    unit: { type: String, trim: true, default: 'UNIT' },
    isActive: { type: Boolean, default: true },
  },
  { timestamps: true }
);

productSchema.index({ tenantId: 1, name: 1 });

module.exports = mongoose.model('Product', productSchema);

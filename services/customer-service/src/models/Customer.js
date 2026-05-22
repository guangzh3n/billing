'use strict';

const mongoose = require('mongoose');
const { Schema } = mongoose;

const addressSchema = new Schema(
  {
    street: { type: String, trim: true },
    city: { type: String, trim: true },
    postcode: { type: String, trim: true },
    state: { type: String, trim: true },
    country: { type: String, trim: true, default: 'Malaysia' },
  },
  { _id: false }
);

const customerSchema = new Schema(
  {
    tenantId: { type: Schema.Types.ObjectId, required: true, index: true },
    name: { type: String, required: true, trim: true, maxlength: 200 },
    tin: { type: String, trim: true },
    idType: {
      type: String,
      enum: ['BRN', 'NRIC', 'PASSPORT', 'ARMY'],
      default: 'BRN',
    },
    idNumber: { type: String, trim: true },
    email: { type: String, trim: true, lowercase: true },
    phone: { type: String, trim: true },
    address: { type: addressSchema, default: () => ({}) },
    isActive: { type: Boolean, default: true },
  },
  { timestamps: true }
);

customerSchema.index({ tenantId: 1, name: 1 });
customerSchema.index({ tenantId: 1, email: 1 });

module.exports = mongoose.model('Customer', customerSchema);

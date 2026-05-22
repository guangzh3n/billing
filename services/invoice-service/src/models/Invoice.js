'use strict';

const mongoose = require('mongoose');
const { Schema } = mongoose;

// ---------------------------------------------------------------------------
// Sub-schemas
// ---------------------------------------------------------------------------
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

const customerSnapshotSchema = new Schema(
  {
    name: { type: String, trim: true },
    tin: { type: String, trim: true },
    idType: { type: String, trim: true },
    idNumber: { type: String, trim: true },
    email: { type: String, trim: true, lowercase: true },
    phone: { type: String, trim: true },
    address: { type: addressSchema, default: () => ({}) },
  },
  { _id: false }
);

const itemSchema = new Schema(
  {
    productId: { type: Schema.Types.ObjectId },
    description: { type: String, required: true, trim: true },
    quantity: { type: Number, required: true, min: 0 },
    unit: { type: String, default: 'UNIT', trim: true },
    unitPrice: { type: Number, required: true, min: 0 },
    discountRate: { type: Number, default: 0, min: 0, max: 100 },
    taxType: { type: String, default: 'E', trim: true },
    taxRate: { type: Number, default: 0 },
    taxAmount: { type: Number, default: 0 },
    subtotal: { type: Number, default: 0 },
    total: { type: Number, default: 0 },
    classification: { type: String, default: '022', trim: true },
  },
  { _id: true }
);

const einvoiceStatusSchema = new Schema(
  {
    status: {
      type: String,
      enum: ['none', 'pending', 'valid', 'invalid', 'cancelled', 'rejected'],
      default: 'none',
    },
    submissionUid: { type: String },
    uuid: { type: String },
    longId: { type: String },
    submittedAt: { type: Date },
    validatedAt: { type: Date },
  },
  { _id: false }
);

// ---------------------------------------------------------------------------
// Invoice schema
// ---------------------------------------------------------------------------
const invoiceSchema = new Schema(
  {
    tenantId: { type: Schema.Types.ObjectId, required: true, index: true },
    invoiceNumber: { type: String, required: true },
    customerId: { type: Schema.Types.ObjectId, required: true },
    customerSnapshot: { type: customerSnapshotSchema, default: () => ({}) },
    invoiceType: {
      type: String,
      enum: ['01', '02', '03', '04', '11', '12', '13', '14'],
      default: '01',
    },
    issueDate: { type: Date, required: true, default: Date.now },
    dueDate: { type: Date },
    status: {
      type: String,
      enum: ['draft', 'sent', 'paid', 'overdue', 'cancelled'],
      default: 'draft',
    },
    currency: { type: String, default: 'MYR', trim: true },
    items: { type: [itemSchema], default: [] },
    subtotal: { type: Number, default: 0 },
    taxAmount: { type: Number, default: 0 },
    discountAmount: { type: Number, default: 0 },
    totalAmount: { type: Number, default: 0 },
    notes: { type: String, trim: true },
    einvoice: { type: einvoiceStatusSchema, default: () => ({}) },
    createdBy: { type: Schema.Types.ObjectId },
  },
  { timestamps: true }
);

// ---------------------------------------------------------------------------
// Indexes
// ---------------------------------------------------------------------------
invoiceSchema.index({ tenantId: 1, invoiceNumber: 1 }, { unique: true });
invoiceSchema.index({ tenantId: 1, status: 1 });
invoiceSchema.index({ tenantId: 1, customerId: 1 });
invoiceSchema.index({ tenantId: 1, issueDate: -1 });

// ---------------------------------------------------------------------------
// Pre-save: calculate totals
// ---------------------------------------------------------------------------
invoiceSchema.pre('save', function (next) {
  let subtotal = 0;
  let taxTotal = 0;
  let discountTotal = 0;

  this.items.forEach((item) => {
    const gross = item.quantity * item.unitPrice;
    const discAmt = gross * (item.discountRate / 100);
    const taxable = gross - discAmt;
    const taxAmt = taxable * (item.taxRate / 100);

    item.subtotal = Math.round(taxable * 100) / 100;
    item.taxAmount = Math.round(taxAmt * 100) / 100;
    item.total = Math.round((taxable + taxAmt) * 100) / 100;

    subtotal += item.subtotal;
    taxTotal += item.taxAmount;
    discountTotal += Math.round(discAmt * 100) / 100;
  });

  this.subtotal = Math.round(subtotal * 100) / 100;
  this.taxAmount = Math.round(taxTotal * 100) / 100;
  this.discountAmount = Math.round(discountTotal * 100) / 100;
  this.totalAmount = Math.round((subtotal + taxTotal) * 100) / 100;

  next();
});

module.exports = mongoose.model('Invoice', invoiceSchema);

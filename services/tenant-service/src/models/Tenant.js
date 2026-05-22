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

const bankSchema = new Schema(
  {
    name: { type: String, trim: true },
    accountNumber: { type: String, trim: true },
    accountHolder: { type: String, trim: true },
  },
  { _id: false }
);

const settingsSchema = new Schema(
  {
    invoicePrefix: { type: String, default: 'INV' },
    nextInvoiceNumber: { type: Number, default: 1 },
    currency: { type: String, default: 'MYR' },
    taxLabel: { type: String, default: 'SST' },
    defaultTaxRate: { type: Number, default: 0 },
  },
  { _id: false }
);

const einvoiceSchema = new Schema(
  {
    enabled: { type: Boolean, default: false },
    env: { type: String, enum: ['sandbox', 'production'], default: 'sandbox' },
    clientId: { type: String },
    clientSecret: { type: String },
  },
  { _id: false }
);

const tenantSchema = new Schema(
  {
    name: { type: String, required: true, trim: true, maxlength: 200 },
    slug: { type: String, unique: true, lowercase: true, trim: true },
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
    bank: { type: bankSchema, default: () => ({}) },
    settings: { type: settingsSchema, default: () => ({}) },
    einvoice: { type: einvoiceSchema, default: () => ({}) },
    plan: { type: String, enum: ['free', 'basic', 'pro'], default: 'free' },
    status: {
      type: String,
      enum: ['active', 'suspended', 'inactive'],
      default: 'active',
    },
    createdBy: { type: Schema.Types.ObjectId, ref: 'User' },
  },
  { timestamps: true }
);

// ---------------------------------------------------------------------------
// Pre-save: auto-generate slug from name
// ---------------------------------------------------------------------------
tenantSchema.pre('save', async function (next) {
  if (!this.isModified('name') && this.slug) return next();

  const baseSlug = this.name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');

  // Check if slug already taken (excluding this doc)
  const existing = await mongoose.model('Tenant').findOne({
    slug: baseSlug,
    _id: { $ne: this._id },
  });

  if (!existing) {
    this.slug = baseSlug;
  } else {
    // Append random 4-char alphanumeric suffix
    const suffix = Math.random().toString(36).substring(2, 6);
    this.slug = `${baseSlug}-${suffix}`;
  }

  next();
});

// ---------------------------------------------------------------------------
// Static: atomically get next invoice number
// ---------------------------------------------------------------------------
tenantSchema.statics.getNextInvoiceNumber = async function (tenantId) {
  const updated = await this.findOneAndUpdate(
    { _id: tenantId },
    { $inc: { 'settings.nextInvoiceNumber': 1 } },
    { new: true, select: 'settings.nextInvoiceNumber settings.invoicePrefix' }
  );

  if (!updated) {
    throw Object.assign(new Error('Tenant not found'), { status: 404, code: 'TENANT_NOT_FOUND' });
  }

  const num = updated.settings.nextInvoiceNumber;
  const prefix = updated.settings.invoicePrefix || 'INV';
  return `${prefix}${String(num).padStart(5, '0')}`;
};

module.exports = mongoose.model('Tenant', tenantSchema);

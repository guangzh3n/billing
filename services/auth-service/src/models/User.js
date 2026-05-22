'use strict';

const mongoose = require('mongoose');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const config = require('../config');

const { Schema, Types } = mongoose;

const refreshTokenSchema = new Schema(
  {
    token: { type: String, required: true },
    expiresAt: { type: Date, required: true },
    createdAt: { type: Date, default: Date.now },
  },
  { _id: false }
);

const userSchema = new Schema(
  {
    tenantId: {
      type: Types.ObjectId,
      ref: 'Tenant',
      default: null,
    },
    name: {
      type: String,
      required: true,
      trim: true,
      maxlength: 100,
    },
    email: {
      type: String,
      required: true,
      unique: true,
      lowercase: true,
      trim: true,
    },
    password: {
      type: String,
      required: true,
      minlength: 6,
    },
    role: {
      type: String,
      enum: ['super_admin', 'tenant_admin', 'tenant_user'],
      default: 'tenant_user',
    },
    isActive: {
      type: Boolean,
      default: true,
    },
    lastLogin: {
      type: Date,
    },
    refreshTokens: [refreshTokenSchema],
  },
  {
    timestamps: true,
  }
);

// Indexes
userSchema.index({ email: 1 }, { unique: true });
userSchema.index({ tenantId: 1 });

// Pre-save: hash password only when it has been modified
userSchema.pre('save', async function hashPassword(next) {
  if (!this.isModified('password')) return next();
  try {
    const salt = await bcrypt.genSalt(12);
    this.password = await bcrypt.hash(this.password, salt);
    next();
  } catch (err) {
    next(err);
  }
});

// Instance method: verify password candidate
userSchema.methods.comparePassword = async function comparePassword(candidate) {
  return bcrypt.compare(candidate, this.password);
};

// Instance method: generate short-lived access token
userSchema.methods.generateAccessToken = function generateAccessToken() {
  return jwt.sign(
    {
      userId: this._id.toString(),
      tenantId: this.tenantId ? this.tenantId.toString() : null,
      role: this.role,
      email: this.email,
    },
    config.JWT_ACCESS_SECRET,
    { expiresIn: config.JWT_ACCESS_EXPIRES }
  );
};

// Instance method: generate refresh token, persist it, and return the raw token string
userSchema.methods.generateRefreshToken = async function generateRefreshToken() {
  const token = jwt.sign(
    { userId: this._id.toString() },
    config.JWT_REFRESH_SECRET,
    { expiresIn: config.JWT_REFRESH_EXPIRES }
  );

  // Decode to get expiry timestamp without re-verifying
  const decoded = jwt.decode(token);
  const expiresAt = new Date(decoded.exp * 1000);

  this.refreshTokens.push({ token, expiresAt });
  await this.save();

  return token;
};

// Instance method: remove a specific refresh token
userSchema.methods.removeRefreshToken = async function removeRefreshToken(token) {
  this.refreshTokens = this.refreshTokens.filter((t) => t.token !== token);
  await this.save();
};

// Static: clean up expired refresh tokens across all users
userSchema.statics.cleanup = async function cleanup() {
  const now = new Date();
  await this.updateMany(
    {},
    { $pull: { refreshTokens: { expiresAt: { $lte: now } } } }
  );
};

const User = mongoose.model('User', userSchema);

module.exports = User;

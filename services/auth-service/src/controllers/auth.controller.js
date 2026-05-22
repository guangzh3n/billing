'use strict';

const User = require('../models/User');

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function sanitizeUser(user) {
  const obj = user.toObject ? user.toObject() : { ...user };
  delete obj.password;
  delete obj.refreshTokens;
  return obj;
}

// ---------------------------------------------------------------------------
// POST /register  (super_admin only)
// ---------------------------------------------------------------------------
async function register(req, res, next) {
  try {
    const callerRole = req.headers['x-user-role'];
    if (callerRole !== 'super_admin') {
      return res.status(403).json({
        success: false,
        error: 'Only super admins may create user accounts',
        code: 'FORBIDDEN',
      });
    }

    const { name, email, password, tenantId, role } = req.body;

    const existing = await User.findOne({ email: email.toLowerCase().trim() });
    if (existing) {
      return res.status(409).json({
        success: false,
        error: 'Email already registered',
        code: 'EMAIL_CONFLICT',
      });
    }

    const userData = { name, email, password };
    if (tenantId) userData.tenantId = tenantId;
    if (role) userData.role = role;

    const user = await User.create(userData);

    return res.status(201).json({
      success: true,
      data: { user: sanitizeUser(user) },
      meta: {},
    });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// POST /login
// ---------------------------------------------------------------------------
async function login(req, res, next) {
  try {
    const { email, password } = req.body;

    const user = await User.findOne({ email: email.toLowerCase().trim() });
    if (!user) {
      return res.status(401).json({
        success: false,
        error: 'Invalid email or password',
        code: 'INVALID_CREDENTIALS',
      });
    }

    if (!user.isActive) {
      return res.status(403).json({
        success: false,
        error: 'Account is deactivated',
        code: 'ACCOUNT_INACTIVE',
      });
    }

    const passwordMatch = await user.comparePassword(password);
    if (!passwordMatch) {
      return res.status(401).json({
        success: false,
        error: 'Invalid email or password',
        code: 'INVALID_CREDENTIALS',
      });
    }

    // Update lastLogin (no need to await — non-critical)
    user.lastLogin = new Date();
    // generateRefreshToken calls save(), so we update lastLogin first then let it save
    const accessToken = user.generateAccessToken();
    const refreshToken = await user.generateRefreshToken(); // saves the user

    return res.status(200).json({
      success: true,
      data: {
        accessToken,
        refreshToken,
        user: {
          id: user._id.toString(),
          name: user.name,
          email: user.email,
          role: user.role,
          tenantId: user.tenantId ? user.tenantId.toString() : null,
        },
      },
      meta: {},
    });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// POST /refresh
// ---------------------------------------------------------------------------
async function refresh(req, res, next) {
  try {
    const jwt = require('jsonwebtoken');
    const config = require('../config');

    const { refreshToken } = req.body;

    let payload;
    try {
      payload = jwt.verify(refreshToken, config.JWT_REFRESH_SECRET);
    } catch {
      return res.status(401).json({
        success: false,
        error: 'Invalid or expired refresh token',
        code: 'REFRESH_TOKEN_INVALID',
      });
    }

    const user = await User.findById(payload.userId);
    if (!user || !user.isActive) {
      return res.status(401).json({
        success: false,
        error: 'User not found or inactive',
        code: 'USER_NOT_FOUND',
      });
    }

    const now = new Date();
    const stored = user.refreshTokens.find(
      (t) => t.token === refreshToken && t.expiresAt > now
    );
    if (!stored) {
      return res.status(401).json({
        success: false,
        error: 'Refresh token not recognised or expired',
        code: 'REFRESH_TOKEN_INVALID',
      });
    }

    const accessToken = user.generateAccessToken();

    return res.status(200).json({
      success: true,
      data: { accessToken },
      meta: {},
    });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// POST /logout
// ---------------------------------------------------------------------------
async function logout(req, res, next) {
  try {
    const { refreshToken } = req.body;
    const userId = req.headers['x-user-id'];

    if (userId) {
      const user = await User.findById(userId);
      if (user && refreshToken) {
        await user.removeRefreshToken(refreshToken);
      }
    }

    return res.status(200).json({
      success: true,
      data: {},
      meta: {},
    });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// GET /me
// ---------------------------------------------------------------------------
async function me(req, res, next) {
  try {
    const userId = req.headers['x-user-id'];
    if (!userId) {
      return res.status(401).json({
        success: false,
        error: 'Not authenticated',
        code: 'UNAUTHORIZED',
      });
    }

    const user = await User.findById(userId).select('-password -refreshTokens');
    if (!user) {
      return res.status(404).json({
        success: false,
        error: 'User not found',
        code: 'USER_NOT_FOUND',
      });
    }

    return res.status(200).json({
      success: true,
      data: { user },
      meta: {},
    });
  } catch (err) {
    next(err);
  }
}

// ---------------------------------------------------------------------------
// PUT /change-password
// ---------------------------------------------------------------------------
async function changePassword(req, res, next) {
  try {
    const userId = req.headers['x-user-id'];
    if (!userId) {
      return res.status(401).json({
        success: false,
        error: 'Not authenticated',
        code: 'UNAUTHORIZED',
      });
    }

    const { currentPassword, newPassword } = req.body;

    const user = await User.findById(userId);
    if (!user) {
      return res.status(404).json({
        success: false,
        error: 'User not found',
        code: 'USER_NOT_FOUND',
      });
    }

    const match = await user.comparePassword(currentPassword);
    if (!match) {
      return res.status(401).json({
        success: false,
        error: 'Current password is incorrect',
        code: 'INVALID_CREDENTIALS',
      });
    }

    user.password = newPassword; // pre-save hook will hash it
    user.refreshTokens = []; // invalidate all sessions
    await user.save();

    return res.status(200).json({
      success: true,
      data: { message: 'Password updated. Please log in again.' },
      meta: {},
    });
  } catch (err) {
    next(err);
  }
}

module.exports = { register, login, refresh, logout, me, changePassword };

'use strict';

const User = require('../models/User');
const config = require('../config');

async function seedSuperAdmin() {
  try {
    const existing = await User.findOne({ role: 'super_admin' });
    if (existing) {
      console.log('[seed] Super admin already exists, skipping.');
      return;
    }

    await User.create({
      name: config.SUPER_ADMIN_NAME,
      email: config.SUPER_ADMIN_EMAIL,
      password: config.SUPER_ADMIN_PASSWORD,
      role: 'super_admin',
      tenantId: null,
    });

    console.log('[seed] Super admin created:', config.SUPER_ADMIN_EMAIL);
  } catch (err) {
    console.error('[seed] Failed to seed super admin:', err.message);
    throw err;
  }
}

module.exports = seedSuperAdmin;

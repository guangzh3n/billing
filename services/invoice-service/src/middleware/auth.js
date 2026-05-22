'use strict';

module.exports = (req, res, next) => {
  const userId = req.headers['x-user-id'];
  const tenantId = req.headers['x-tenant-id'];
  const role = req.headers['x-user-role'];

  if (!userId) {
    return res.status(401).json({ success: false, error: 'Unauthorized', code: 'UNAUTHORIZED' });
  }

  req.user = { userId, tenantId: tenantId || null, role };
  next();
};

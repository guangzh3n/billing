'use strict';

const { Router } = require('express');
const auth = require('../middleware/auth');
const requireRole = require('../middleware/requireRole');
const ctrl = require('../controllers/invoice.controller');

const router = Router();

// Stats must come before /:id to avoid the param route capturing it
router.get('/stats', auth, requireRole('super_admin', 'tenant_admin', 'tenant_user'), ctrl.getDashboardStats);

// Standard CRUD
router.get('/', auth, requireRole('super_admin', 'tenant_admin', 'tenant_user'), ctrl.list);
router.post('/', auth, requireRole('tenant_admin', 'tenant_user'), ctrl.create);

router.get('/:id', auth, requireRole('super_admin', 'tenant_admin', 'tenant_user'), ctrl.getOne);
router.put('/:id', auth, requireRole('tenant_admin', 'tenant_user'), ctrl.update);
router.patch('/:id/status', auth, requireRole('super_admin', 'tenant_admin', 'tenant_user'), ctrl.updateStatus);
router.delete('/:id', auth, requireRole('tenant_admin'), ctrl.delete);

module.exports = router;

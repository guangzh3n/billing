'use strict';

const { Router } = require('express');
const auth = require('../middleware/auth');
const requireRole = require('../middleware/requireRole');
const ctrl = require('../controllers/tenant.controller');

const router = Router();

// Stats must be registered before /:id to avoid being captured by the param route
router.get('/stats', auth, requireRole('super_admin'), ctrl.getStats);

router.post('/', auth, requireRole('super_admin'), ctrl.create);
router.get('/', auth, requireRole('super_admin'), ctrl.list);

router.get('/:id', auth, requireRole('super_admin', 'tenant_admin', 'tenant_user'), ctrl.getOne);
router.put('/:id', auth, requireRole('super_admin', 'tenant_admin'), ctrl.update);
router.put('/:id/settings', auth, requireRole('super_admin', 'tenant_admin'), ctrl.updateSettings);
router.post('/:id/suspend', auth, requireRole('super_admin'), ctrl.suspend);
router.post('/:id/activate', auth, requireRole('super_admin'), ctrl.activate);
router.get('/:id/users', auth, requireRole('super_admin', 'tenant_admin'), ctrl.listUsers);

module.exports = router;

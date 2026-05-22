'use strict';

const router = require('express').Router();
const auth = require('../middleware/auth');
const requireRole = require('../middleware/requireRole');
const ctrl = require('../controllers/customer.controller');

// Internal route — no auth, called by invoice-service
router.get('/internal/:id', ctrl.getForInvoice);

// Public (authenticated) routes
router.get('/', auth, ctrl.list);
router.post('/', auth, requireRole('tenant_admin', 'tenant_user', 'super_admin'), ctrl.create);
router.get('/:id', auth, ctrl.getOne);
router.put('/:id', auth, requireRole('tenant_admin', 'tenant_user', 'super_admin'), ctrl.update);
router.delete('/:id', auth, requireRole('tenant_admin', 'super_admin'), ctrl.softDelete);

module.exports = router;

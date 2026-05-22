'use strict';

const router = require('express').Router();
const auth = require('../middleware/auth');
const requireRole = require('../middleware/requireRole');
const ctrl = require('../controllers/payment.controller');

router.get('/reports/monthly', auth, ctrl.getMonthlyRevenue);
router.get('/invoice/:invoiceId', auth, ctrl.getByInvoice);
router.get('/', auth, ctrl.list);
router.post('/', auth, requireRole('tenant_admin', 'tenant_user', 'super_admin'), ctrl.create);
router.delete('/:id', auth, requireRole('tenant_admin', 'super_admin'), ctrl.delete);

module.exports = router;

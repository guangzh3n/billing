'use strict';

const router = require('express').Router();
const auth = require('../middleware/auth');
const requireRole = require('../middleware/requireRole');
const ctrl = require('../controllers/einvoice.controller');

router.post('/submit', auth, requireRole('tenant_admin', 'tenant_user', 'super_admin'), ctrl.submit);
router.get('/status/:invoiceId', auth, ctrl.checkStatus);
router.post('/cancel/:invoiceId', auth, requireRole('tenant_admin', 'super_admin'), ctrl.cancel);
router.post('/test', auth, requireRole('tenant_admin', 'super_admin'), ctrl.testConnection);
router.get('/logs', auth, ctrl.getLogs);

module.exports = router;

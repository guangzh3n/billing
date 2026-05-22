'use strict';

const mongoose = require('mongoose');
const axios = require('axios');
const Joi = require('joi');
const EInvoiceLog = require('../models/EInvoiceLog');
const MyInvoisAPI = require('../services/myinvoisApi');
const { buildDocument, encodeDocument, hashDocument } = require('../services/documentBuilder');
const config = require('../config');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function authHeaders(req) {
  return {
    'x-user-id': req.headers['x-user-id'],
    'x-tenant-id': req.headers['x-tenant-id'],
    'x-user-role': req.headers['x-user-role'],
  };
}

async function fetchInvoice(invoiceId, headers) {
  const resp = await axios.get(
    `${config.INVOICE_SERVICE_URL}/api/invoices/${invoiceId}`,
    { headers, timeout: 10000 }
  );
  return resp.data.data?.invoice || resp.data.data;
}

async function fetchTenant(tenantId, headers) {
  const resp = await axios.get(
    `${config.TENANT_SERVICE_URL}/api/tenants/${tenantId}`,
    { headers, timeout: 10000 }
  );
  return resp.data.data?.tenant || resp.data.data;
}

function mapMyInvoisStatus(rawStatus) {
  if (!rawStatus) return 'pending';
  const s = rawStatus.toLowerCase();
  if (s === 'valid') return 'valid';
  if (s === 'invalid') return 'invalid';
  if (s === 'cancelled') return 'cancelled';
  if (s === 'rejected') return 'rejected';
  return 'pending';
}

async function saveLog(data) {
  try {
    await EInvoiceLog.create(data);
  } catch (logErr) {
    console.error('Failed to save EInvoiceLog:', logErr.message);
  }
}

// ---------------------------------------------------------------------------
// submit  POST /submit
// ---------------------------------------------------------------------------
exports.submit = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    const { error, value } = Joi.object({
      invoiceId: Joi.string().required(),
    }).validate(req.body, { abortEarly: false });

    if (error) {
      return res.status(422).json({
        success: false,
        error: error.details.map((d) => d.message).join('; '),
        code: 'VALIDATION_ERROR',
      });
    }

    if (!mongoose.Types.ObjectId.isValid(value.invoiceId)) {
      return res.status(422).json({ success: false, error: 'Invalid invoiceId', code: 'VALIDATION_ERROR' });
    }

    const headers = authHeaders(req);

    // Fetch invoice
    let invoice;
    try {
      invoice = await fetchInvoice(value.invoiceId, headers);
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!invoice) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    // Validate invoice belongs to this tenant
    if (String(invoice.tenantId) !== String(tenantId)) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    // Prevent re-submission of already-valid invoices
    if (invoice.einvoice?.status === 'valid') {
      return res.status(400).json({
        success: false,
        error: 'Invoice has already been submitted and validated',
        code: 'ALREADY_SUBMITTED',
      });
    }

    // Fetch tenant
    let tenant;
    try {
      tenant = await fetchTenant(tenantId, headers);
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    // Validate tenant e-invoice configuration
    if (!tenant?.einvoice?.enabled) {
      return res.status(400).json({
        success: false,
        error: 'E-invoice is not enabled for this tenant',
        code: 'EINVOICE_NOT_ENABLED',
      });
    }
    if (!tenant.einvoice.clientId || !tenant.einvoice.clientSecret) {
      return res.status(400).json({
        success: false,
        error: 'Tenant e-invoice credentials (clientId / clientSecret) are not configured',
        code: 'EINVOICE_CREDENTIALS_MISSING',
      });
    }
    if (!tenant.tin) {
      return res.status(400).json({
        success: false,
        error: 'Tenant TIN is required for e-invoice submission',
        code: 'TENANT_TIN_MISSING',
      });
    }

    // Build UBL document
    const doc = buildDocument(invoice, tenant);
    const encoded = encodeDocument(doc);
    const docHash = hashDocument(doc);

    // Submit to MyInvois
    const api = new MyInvoisAPI(
      tenant.einvoice.env || 'sandbox',
      tenant.einvoice.clientId,
      tenant.einvoice.clientSecret
    );

    let apiResponse;
    let submissionError;
    try {
      apiResponse = await api.submitDocuments([
        {
          format: 'JSON',
          document: encoded,
          documentHash: docHash,
          codeNumber: invoice.invoiceNumber,
        },
      ]);
    } catch (apiErr) {
      submissionError = apiErr.response?.data || apiErr.message;
      await saveLog({
        tenantId: new mongoose.Types.ObjectId(tenantId),
        invoiceId: new mongoose.Types.ObjectId(value.invoiceId),
        invoiceNumber: invoice.invoiceNumber,
        action: 'submit',
        status: 'error',
        request: { invoiceId: value.invoiceId, invoiceNumber: invoice.invoiceNumber },
        error: typeof submissionError === 'string' ? submissionError : JSON.stringify(submissionError),
      });
      return res.status(502).json({
        success: false,
        error: 'MyInvois API submission failed',
        code: 'MYINVOIS_ERROR',
        details: submissionError,
      });
    }

    // Parse accepted/rejected responses
    const acceptedDoc = apiResponse.acceptedDocuments?.[0];
    const rejectedDoc = apiResponse.rejectedDocuments?.[0];

    const submissionUid = apiResponse.submissionUid || null;
    const uuid = acceptedDoc?.uuid || null;
    const longId = acceptedDoc?.longId || null;
    const status = acceptedDoc ? 'pending' : 'invalid';

    // Log submission
    await saveLog({
      tenantId: new mongoose.Types.ObjectId(tenantId),
      invoiceId: new mongoose.Types.ObjectId(value.invoiceId),
      invoiceNumber: invoice.invoiceNumber,
      action: 'submit',
      status,
      request: { invoiceId: value.invoiceId, invoiceNumber: invoice.invoiceNumber },
      response: apiResponse,
    });

    if (rejectedDoc && !acceptedDoc) {
      return res.status(422).json({
        success: false,
        error: 'MyInvois rejected the document',
        code: 'MYINVOIS_REJECTED',
        details: rejectedDoc,
      });
    }

    // Update invoice einvoice fields
    try {
      await axios.patch(
        `${config.INVOICE_SERVICE_URL}/api/invoices/${value.invoiceId}/einvoice`,
        { status: 'pending', submissionUid, uuid, longId },
        { headers, timeout: 10000 }
      );
    } catch (patchErr) {
      console.error('Failed to update invoice einvoice fields:', patchErr.message);
    }

    return res.json({
      success: true,
      data: { submissionUid, uuid, longId, status },
    });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// checkStatus  GET /status/:invoiceId
// ---------------------------------------------------------------------------
exports.checkStatus = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    if (!mongoose.Types.ObjectId.isValid(req.params.invoiceId)) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    const headers = authHeaders(req);

    let invoice;
    try {
      invoice = await fetchInvoice(req.params.invoiceId, headers);
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!invoice || String(invoice.tenantId) !== String(tenantId)) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    const { submissionUid, uuid } = invoice.einvoice || {};
    if (!submissionUid && !uuid) {
      return res.status(400).json({
        success: false,
        error: 'Invoice has not been submitted to MyInvois yet',
        code: 'NOT_SUBMITTED',
      });
    }

    let tenant;
    try {
      tenant = await fetchTenant(tenantId, headers);
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!tenant?.einvoice?.clientId || !tenant?.einvoice?.clientSecret) {
      return res.status(400).json({
        success: false,
        error: 'Tenant e-invoice credentials are not configured',
        code: 'EINVOICE_CREDENTIALS_MISSING',
      });
    }

    const api = new MyInvoisAPI(
      tenant.einvoice.env || 'sandbox',
      tenant.einvoice.clientId,
      tenant.einvoice.clientSecret
    );

    let apiResponse;
    try {
      if (uuid) {
        apiResponse = await api.getDocumentDetails(uuid);
      } else {
        apiResponse = await api.getSubmissionStatus(submissionUid);
      }
    } catch (apiErr) {
      await saveLog({
        tenantId: new mongoose.Types.ObjectId(tenantId),
        invoiceId: new mongoose.Types.ObjectId(req.params.invoiceId),
        invoiceNumber: invoice.invoiceNumber,
        action: 'status_check',
        status: 'error',
        request: { submissionUid, uuid },
        error: apiErr.message,
      });
      return res.status(502).json({
        success: false,
        error: 'Failed to retrieve status from MyInvois',
        code: 'MYINVOIS_ERROR',
        details: apiErr.response?.data || apiErr.message,
      });
    }

    // Map status
    const rawStatus = apiResponse.status || apiResponse.documentSummary?.[0]?.status;
    const mappedStatus = mapMyInvoisStatus(rawStatus);
    const newUuid = apiResponse.uuid || uuid;
    const newLongId = apiResponse.longId || invoice.einvoice?.longId;

    // Log
    await saveLog({
      tenantId: new mongoose.Types.ObjectId(tenantId),
      invoiceId: new mongoose.Types.ObjectId(req.params.invoiceId),
      invoiceNumber: invoice.invoiceNumber,
      action: 'status_check',
      status: mappedStatus,
      request: { submissionUid, uuid },
      response: apiResponse,
    });

    // Update invoice einvoice fields
    try {
      await axios.patch(
        `${config.INVOICE_SERVICE_URL}/api/invoices/${req.params.invoiceId}/einvoice`,
        {
          status: mappedStatus,
          uuid: newUuid,
          longId: newLongId,
          ...(mappedStatus === 'valid' ? { validatedAt: new Date() } : {}),
        },
        { headers, timeout: 10000 }
      );
    } catch (patchErr) {
      console.error('Failed to update invoice einvoice status:', patchErr.message);
    }

    return res.json({
      success: true,
      data: {
        status: mappedStatus,
        uuid: newUuid,
        longId: newLongId,
        submissionUid,
        rawResponse: apiResponse,
      },
    });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// cancel  POST /cancel/:invoiceId
// ---------------------------------------------------------------------------
exports.cancel = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    if (!mongoose.Types.ObjectId.isValid(req.params.invoiceId)) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    const { reason } = req.body;
    if (!reason || typeof reason !== 'string' || !reason.trim()) {
      return res.status(422).json({ success: false, error: 'Cancellation reason is required', code: 'VALIDATION_ERROR' });
    }

    const headers = authHeaders(req);

    let invoice;
    try {
      invoice = await fetchInvoice(req.params.invoiceId, headers);
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!invoice || String(invoice.tenantId) !== String(tenantId)) {
      return res.status(404).json({ success: false, error: 'Invoice not found', code: 'NOT_FOUND' });
    }

    const { uuid, status: einvoiceStatus } = invoice.einvoice || {};

    if (!uuid) {
      return res.status(400).json({
        success: false,
        error: 'Invoice does not have a MyInvois UUID — has it been submitted and validated?',
        code: 'NO_UUID',
      });
    }

    if (einvoiceStatus !== 'valid') {
      return res.status(400).json({
        success: false,
        error: `Invoice e-invoice status is '${einvoiceStatus}'; only 'valid' invoices can be cancelled`,
        code: 'INVALID_STATUS_FOR_CANCEL',
      });
    }

    let tenant;
    try {
      tenant = await fetchTenant(tenantId, headers);
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!tenant?.einvoice?.clientId || !tenant?.einvoice?.clientSecret) {
      return res.status(400).json({
        success: false,
        error: 'Tenant e-invoice credentials are not configured',
        code: 'EINVOICE_CREDENTIALS_MISSING',
      });
    }

    const api = new MyInvoisAPI(
      tenant.einvoice.env || 'sandbox',
      tenant.einvoice.clientId,
      tenant.einvoice.clientSecret
    );

    let apiResponse;
    try {
      apiResponse = await api.cancelDocument(uuid, reason.trim());
    } catch (apiErr) {
      await saveLog({
        tenantId: new mongoose.Types.ObjectId(tenantId),
        invoiceId: new mongoose.Types.ObjectId(req.params.invoiceId),
        invoiceNumber: invoice.invoiceNumber,
        action: 'cancel',
        status: 'error',
        request: { uuid, reason: reason.trim() },
        error: apiErr.message,
      });
      return res.status(502).json({
        success: false,
        error: 'MyInvois API cancellation failed',
        code: 'MYINVOIS_ERROR',
        details: apiErr.response?.data || apiErr.message,
      });
    }

    // Log
    await saveLog({
      tenantId: new mongoose.Types.ObjectId(tenantId),
      invoiceId: new mongoose.Types.ObjectId(req.params.invoiceId),
      invoiceNumber: invoice.invoiceNumber,
      action: 'cancel',
      status: 'cancelled',
      request: { uuid, reason: reason.trim() },
      response: apiResponse,
    });

    // Update invoice einvoice status
    try {
      await axios.patch(
        `${config.INVOICE_SERVICE_URL}/api/invoices/${req.params.invoiceId}/einvoice`,
        { status: 'cancelled' },
        { headers, timeout: 10000 }
      );
    } catch (patchErr) {
      console.error('Failed to update invoice einvoice status to cancelled:', patchErr.message);
    }

    return res.json({
      success: true,
      data: { status: 'cancelled', uuid, response: apiResponse },
    });
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// testConnection  POST /test
// ---------------------------------------------------------------------------
exports.testConnection = async (req, res, next) => {
  try {
    const tenantId = req.body.tenantId || req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    const headers = authHeaders(req);

    let tenant;
    try {
      tenant = await fetchTenant(tenantId, headers);
    } catch (axErr) {
      if (axErr.response?.status === 404) {
        return res.status(404).json({ success: false, error: 'Tenant not found', code: 'NOT_FOUND' });
      }
      throw axErr;
    }

    if (!tenant?.einvoice?.clientId || !tenant?.einvoice?.clientSecret) {
      return res.status(400).json({
        success: false,
        error: 'Tenant e-invoice credentials are not configured',
        code: 'EINVOICE_CREDENTIALS_MISSING',
      });
    }

    const env = tenant.einvoice.env || 'sandbox';
    const api = new MyInvoisAPI(env, tenant.einvoice.clientId, tenant.einvoice.clientSecret);

    try {
      await api.authenticate();
      return res.json({
        success: true,
        data: {
          connected: true,
          env,
          message: `Successfully authenticated with MyInvois ${env} environment`,
        },
      });
    } catch (authErr) {
      return res.json({
        success: true,
        data: {
          connected: false,
          env,
          message: `Authentication failed: ${authErr.response?.data?.error_description || authErr.message}`,
        },
      });
    }
  } catch (err) {
    next(err);
  }
};

// ---------------------------------------------------------------------------
// getLogs  GET /logs
// ---------------------------------------------------------------------------
exports.getLogs = async (req, res, next) => {
  try {
    const tenantId = req.user.tenantId;
    if (!tenantId) {
      return res.status(400).json({ success: false, error: 'tenantId required', code: 'MISSING_TENANT' });
    }

    const page = Math.max(1, parseInt(req.query.page, 10) || 1);
    const limit = Math.min(100, Math.max(1, parseInt(req.query.limit, 10) || 20));
    const skip = (page - 1) * limit;

    const filter = { tenantId: new mongoose.Types.ObjectId(tenantId) };

    if (req.query.invoiceId && mongoose.Types.ObjectId.isValid(req.query.invoiceId)) {
      filter.invoiceId = new mongoose.Types.ObjectId(req.query.invoiceId);
    }

    if (req.query.action) {
      filter.action = req.query.action;
    }

    const [logs, total] = await Promise.all([
      EInvoiceLog.find(filter).sort({ createdAt: -1 }).skip(skip).limit(limit).lean(),
      EInvoiceLog.countDocuments(filter),
    ]);

    return res.json({
      success: true,
      data: { logs },
      meta: {
        total,
        page,
        limit,
        totalPages: Math.ceil(total / limit),
      },
    });
  } catch (err) {
    next(err);
  }
};

'use strict';

const axios = require('axios');
const config = require('../config');
const Invoice = require('../models/Invoice');

// ---------------------------------------------------------------------------
// Internal HTTP helpers
// ---------------------------------------------------------------------------

/**
 * Build axios headers to forward the service-to-service identity.
 * We act as a super_admin internal caller so downstream services allow the request.
 */
function internalHeaders(tenantId, userId = 'internal') {
  const headers = {
    'x-user-id': userId,
    'x-user-role': 'super_admin',
  };
  if (tenantId) headers['x-tenant-id'] = tenantId.toString();
  return headers;
}

// ---------------------------------------------------------------------------
// Fetch tenant settings from tenant-service
// ---------------------------------------------------------------------------
async function fetchTenant(tenantId) {
  const url = `${config.TENANT_SERVICE_URL}/api/tenants/${tenantId}`;
  const response = await axios.get(url, {
    headers: internalHeaders(tenantId),
    timeout: 8000,
  });
  return response.data.data.tenant;
}

// ---------------------------------------------------------------------------
// Get next invoice number by calling tenant-service atomically
// ---------------------------------------------------------------------------
async function fetchNextInvoiceNumber(tenantId) {
  const url = `${config.TENANT_SERVICE_URL}/api/tenants/${tenantId}/invoice-number`;
  const response = await axios.post(url, {}, {
    headers: internalHeaders(tenantId),
    timeout: 8000,
  });
  return response.data.data.invoiceNumber;
}

// ---------------------------------------------------------------------------
// Fetch customer snapshot from customer-service
// ---------------------------------------------------------------------------
async function fetchCustomer(customerId, tenantId, userId) {
  const url = `${config.CUSTOMER_SERVICE_URL}/api/customers/${customerId}`;
  const response = await axios.get(url, {
    headers: {
      'x-user-id': userId || 'internal',
      'x-tenant-id': tenantId.toString(),
      'x-user-role': 'tenant_admin',
    },
    timeout: 8000,
  });
  // Normalise: some services nest under data.customer, others under data
  const d = response.data.data;
  return d.customer || d;
}

// ---------------------------------------------------------------------------
// Build customer snapshot object from customer document
// ---------------------------------------------------------------------------
function buildCustomerSnapshot(customer) {
  return {
    name: customer.name || '',
    tin: customer.tin || '',
    idType: customer.idType || '',
    idNumber: customer.idNumber || '',
    email: customer.email || '',
    phone: customer.phone || '',
    address: {
      street: customer.address?.street || '',
      city: customer.address?.city || '',
      postcode: customer.address?.postcode || '',
      state: customer.address?.state || '',
      country: customer.address?.country || 'Malaysia',
    },
  };
}

// ---------------------------------------------------------------------------
// createInvoice — main business logic
// ---------------------------------------------------------------------------
async function createInvoice(tenantId, userId, data) {
  // 1. Verify tenant exists and get settings
  let tenant;
  try {
    tenant = await fetchTenant(tenantId);
  } catch (err) {
    const msg = err.response?.data?.error || err.message || 'Failed to fetch tenant';
    const status = err.response?.status === 404 ? 404 : 502;
    const e = new Error(msg);
    e.status = status;
    e.code = status === 404 ? 'TENANT_NOT_FOUND' : 'TENANT_SERVICE_ERROR';
    throw e;
  }

  if (tenant.status !== 'active') {
    const e = new Error('Tenant is not active');
    e.status = 403;
    e.code = 'TENANT_SUSPENDED';
    throw e;
  }

  // 2. Generate invoice number atomically
  let invoiceNumber;
  try {
    invoiceNumber = await fetchNextInvoiceNumber(tenantId);
  } catch (err) {
    const msg = err.response?.data?.error || err.message || 'Failed to generate invoice number';
    const e = new Error(msg);
    e.status = 502;
    e.code = 'INVOICE_NUMBER_ERROR';
    throw e;
  }

  // 3. Fetch and snapshot customer
  let customerSnapshot;
  try {
    const customer = await fetchCustomer(data.customerId, tenantId, userId);
    customerSnapshot = buildCustomerSnapshot(customer);
  } catch (err) {
    const status = err.response?.status;
    if (status === 404) {
      const e = new Error('Customer not found');
      e.status = 404;
      e.code = 'CUSTOMER_NOT_FOUND';
      throw e;
    }
    const msg = err.response?.data?.error || err.message || 'Failed to fetch customer';
    const e = new Error(msg);
    e.status = 502;
    e.code = 'CUSTOMER_SERVICE_ERROR';
    throw e;
  }

  // 4. Build invoice document
  const invoiceData = {
    tenantId,
    invoiceNumber,
    customerId: data.customerId,
    customerSnapshot,
    invoiceType: data.invoiceType || '01',
    issueDate: data.issueDate || new Date(),
    dueDate: data.dueDate || undefined,
    status: 'draft',
    currency: data.currency || tenant.settings?.currency || 'MYR',
    items: data.items.map((item) => ({
      productId: item.productId || undefined,
      description: item.description,
      quantity: item.quantity,
      unit: item.unit || 'UNIT',
      unitPrice: item.unitPrice,
      discountRate: item.discountRate || 0,
      taxType: item.taxType || 'E',
      taxRate: item.taxRate !== undefined ? item.taxRate : (tenant.settings?.defaultTaxRate || 0),
      classification: item.classification || '022',
    })),
    notes: data.notes || undefined,
    createdBy: userId,
  };

  const invoice = new Invoice(invoiceData);
  await invoice.save();

  return invoice;
}

// ---------------------------------------------------------------------------
// getInvoiceWithBalance — enrich invoice with payment balance
// ---------------------------------------------------------------------------
async function getInvoiceWithBalance(invoice, tenantId) {
  let amountPaid = 0;
  try {
    const url = `${config.PAYMENT_SERVICE_URL}/api/payments?invoiceId=${invoice._id}`;
    const response = await axios.get(url, {
      headers: internalHeaders(tenantId),
      timeout: 5000,
    });
    const payments = response.data.data?.payments || response.data.data || [];
    if (Array.isArray(payments)) {
      amountPaid = payments.reduce((sum, p) => {
        // Only count confirmed/completed payments
        if (['completed', 'confirmed', 'paid'].includes(p.status)) {
          return sum + (p.amount || 0);
        }
        return sum;
      }, 0);
    }
  } catch (err) {
    // Non-fatal — return 0 if payment-service is unreachable
    console.warn('[invoice-service] Could not fetch payments for balance:', err.message);
  }

  amountPaid = Math.round(amountPaid * 100) / 100;
  const invoiceObj = invoice.toObject ? invoice.toObject() : { ...invoice };
  invoiceObj.amountPaid = amountPaid;
  invoiceObj.balance = Math.round((invoiceObj.totalAmount - amountPaid) * 100) / 100;

  return invoiceObj;
}

// ---------------------------------------------------------------------------
// buildInvoiceFilter — build MongoDB filter from query params
// ---------------------------------------------------------------------------
function buildInvoiceFilter(tenantId, query) {
  const filter = { tenantId };

  if (query.status) {
    filter.status = query.status;
  }

  if (query.customerId) {
    filter.customerId = query.customerId;
  }

  if (query.dateFrom || query.dateTo) {
    filter.issueDate = {};
    if (query.dateFrom) {
      filter.issueDate.$gte = new Date(query.dateFrom);
    }
    if (query.dateTo) {
      // Include full day by setting time to end of day
      const to = new Date(query.dateTo);
      to.setHours(23, 59, 59, 999);
      filter.issueDate.$lte = to;
    }
  }

  if (query.search) {
    const escaped = query.search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    filter.invoiceNumber = { $regex: escaped, $options: 'i' };
  }

  return filter;
}

module.exports = {
  createInvoice,
  getInvoiceWithBalance,
  buildInvoiceFilter,
  fetchTenant,
  internalHeaders,
};

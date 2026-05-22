'use strict';

const crypto = require('crypto');

/**
 * Maps MyInvois tax type codes to their human-readable exemption reasons.
 */
function getTaxExemptionReason(taxType) {
  const map = {
    E: 'Exempt Supply',
    OE: 'Out of Scope',
    AE: 'Reverse Charge',
    '01': 'Sales Tax',
    '02': 'Service Tax',
    '03': 'Tourism Tax',
    '04': 'High Value Goods Tax',
  };
  return map[taxType] || 'NA';
}

/**
 * Builds the UBL 2.1 JSON address sub-object used by both supplier and customer.
 */
function buildAddress(obj) {
  return {
    CityName: [{ _: obj?.address?.city || 'NA' }],
    PostalZone: [{ _: obj?.address?.postcode || '00000' }],
    CountrySubentityCode: [{ _: '14' }],
    AddressLine: [
      { Line: [{ _: obj?.address?.street || 'NA' }] },
      { Line: [{ _: obj?.address?.city || 'NA' }] },
      { Line: [{ _: obj?.address?.state || 'NA' }] },
    ],
    Country: [
      {
        IdentificationCode: [
          { _: 'MYS', listID: 'ISO3166-1', listAgencyID: '6' },
        ],
      },
    ],
  };
}

/**
 * Builds the complete UBL 2.1 JSON document for submission to MyInvois.
 *
 * @param {Object} invoice  - Full invoice document from invoice-service
 * @param {Object} tenant   - Full tenant document from tenant-service
 * @returns {Object} UBL JSON document
 */
function buildDocument(invoice, tenant) {
  const currency = invoice.currency || 'MYR';
  const issueDate = new Date(invoice.issueDate).toISOString().split('T')[0];
  const issueTime = new Date().toISOString().split('T')[1].replace(/\.\d{3}Z/, 'Z');

  // -------------------------------------------------------------------------
  // Tax subtotals grouped by taxType + taxRate
  // -------------------------------------------------------------------------
  const taxGroups = {};
  invoice.items.forEach((item) => {
    const key = `${item.taxType}_${item.taxRate}`;
    if (!taxGroups[key]) {
      taxGroups[key] = { taxType: item.taxType, taxRate: item.taxRate, taxable: 0, taxAmt: 0 };
    }
    taxGroups[key].taxable += item.subtotal;
    taxGroups[key].taxAmt += item.taxAmount;
  });

  const taxSubtotals = Object.values(taxGroups).map((g) => ({
    TaxableAmount: [{ _: g.taxable.toFixed(2), currencyID: currency }],
    TaxAmount: [{ _: g.taxAmt.toFixed(2), currencyID: currency }],
    Percent: [{ _: g.taxRate.toFixed(2) }],
    TaxCategory: [
      {
        ID: [{ _: g.taxType }],
        TaxExemptionReason: [{ _: getTaxExemptionReason(g.taxType) }],
        TaxScheme: [
          {
            ID: [{ _: 'OTH', schemeID: 'UN/ECE 5153', schemeAgencyID: '6' }],
          },
        ],
      },
    ],
  }));

  // -------------------------------------------------------------------------
  // Invoice lines
  // -------------------------------------------------------------------------
  const customer = invoice.customerSnapshot || {};

  const invoiceLines = invoice.items.map((item, idx) => {
    const grossBeforeDisc = item.quantity * item.unitPrice;
    const discAmt = grossBeforeDisc * (item.discountRate / 100);
    const discAmtPerUnit = item.quantity > 0 ? (grossBeforeDisc * (item.discountRate / 100)) / item.quantity : 0;

    return {
      ID: [{ _: String(idx + 1) }],
      InvoicedQuantity: [{ _: item.quantity.toFixed(4), unitCode: item.unit || 'UNIT' }],
      LineExtensionAmount: [{ _: item.subtotal.toFixed(2), currencyID: currency }],
      AllowanceCharge: [
        {
          ChargeIndicator: [{ _: false }],
          AllowanceChargeReason: [{ _: 'Discount' }],
          MultiplierFactorNumeric: [{ _: (item.discountRate / 100).toFixed(4) }],
          Amount: [{ _: discAmt.toFixed(2), currencyID: currency }],
        },
      ],
      TaxTotal: [
        {
          TaxAmount: [{ _: item.taxAmount.toFixed(2), currencyID: currency }],
          TaxSubtotal: [
            {
              TaxableAmount: [{ _: item.subtotal.toFixed(2), currencyID: currency }],
              TaxAmount: [{ _: item.taxAmount.toFixed(2), currencyID: currency }],
              Percent: [{ _: item.taxRate.toFixed(2) }],
              TaxCategory: [
                {
                  ID: [{ _: item.taxType || 'E' }],
                  TaxExemptionReason: [{ _: getTaxExemptionReason(item.taxType) }],
                  TaxScheme: [
                    {
                      ID: [{ _: 'OTH', schemeID: 'UN/ECE 5153', schemeAgencyID: '6' }],
                    },
                  ],
                },
              ],
            },
          ],
        },
      ],
      Item: [
        {
          CommodityClassification: [
            {
              ItemClassificationCode: [
                { _: item.classification || '022', listID: 'CLASS' },
              ],
            },
          ],
          Description: [{ _: item.description }],
          OriginCountry: [{ IdentificationCode: [{ _: 'MYS' }] }],
        },
      ],
      Price: [
        {
          PriceAmount: [{ _: item.unitPrice.toFixed(2), currencyID: currency }],
          AllowanceCharge: [
            {
              ChargeIndicator: [{ _: false }],
              AllowanceChargeReason: [{ _: 'Discount' }],
              Amount: [{ _: discAmtPerUnit.toFixed(2), currencyID: currency }],
            },
          ],
        },
      ],
      ItemPriceExtension: [
        {
          Amount: [{ _: item.total.toFixed(2), currencyID: currency }],
        },
      ],
    };
  });

  // -------------------------------------------------------------------------
  // Full UBL 2.1 document
  // -------------------------------------------------------------------------
  return {
    _D: 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
    _A: 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    _B: 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    Invoice: [
      {
        ID: [{ _: invoice.invoiceNumber }],
        IssueDate: [{ _: issueDate }],
        IssueTime: [{ _: issueTime }],
        InvoiceTypeCode: [{ _: invoice.invoiceType || '01', listVersionID: '1.0' }],
        DocumentCurrencyCode: [{ _: currency }],
        TaxCurrencyCode: [{ _: currency }],
        InvoicePeriod: [
          {
            StartDate: [{ _: issueDate }],
            EndDate: [{ _: issueDate }],
            Description: [{ _: 'Monthly' }],
          },
        ],
        AccountingSupplierParty: [
          {
            AdditionalAccountID: [
              { _: tenant.tin || 'NA', schemeAgencyName: 'CertEX' },
            ],
            Party: [
              {
                IndustryClassificationCode: [
                  { _: tenant.msicCode || '10110', name: tenant.name },
                ],
                PartyIdentification: [
                  { ID: [{ _: tenant.tin || 'NA', schemeID: 'TIN' }] },
                  { ID: [{ _: tenant.idNumber || 'NA', schemeID: tenant.idType || 'BRN' }] },
                ],
                PostalAddress: [buildAddress(tenant)],
                PartyLegalEntity: [
                  { RegistrationName: [{ _: tenant.name }] },
                ],
                Contact: [
                  {
                    Telephone: [{ _: tenant.phone || 'NA' }],
                    ElectronicMail: [{ _: tenant.email || 'NA' }],
                  },
                ],
              },
            ],
          },
        ],
        AccountingCustomerParty: [
          {
            Party: [
              {
                PartyIdentification: [
                  { ID: [{ _: customer.tin || 'NA', schemeID: 'TIN' }] },
                  { ID: [{ _: customer.idNumber || 'NA', schemeID: customer.idType || 'BRN' }] },
                ],
                PostalAddress: [buildAddress(customer)],
                PartyLegalEntity: [
                  { RegistrationName: [{ _: customer.name || 'NA' }] },
                ],
                Contact: [
                  {
                    Telephone: [{ _: customer.phone || 'NA' }],
                    ElectronicMail: [{ _: customer.email || 'NA' }],
                  },
                ],
              },
            ],
          },
        ],
        PaymentMeans: [
          {
            PaymentMeansCode: [{ _: '03' }],
            PayeeFinancialAccount: [
              { ID: [{ _: tenant.bank?.accountNumber || 'NA' }] },
            ],
          },
        ],
        PaymentTerms: [
          { Note: [{ _: 'Payment terms as agreed' }] },
        ],
        AllowanceCharge: [
          {
            ChargeIndicator: [{ _: false }],
            AllowanceChargeReason: [{ _: 'Discount' }],
            Amount: [{ _: invoice.discountAmount.toFixed(2), currencyID: currency }],
          },
        ],
        TaxTotal: [
          {
            TaxAmount: [{ _: invoice.taxAmount.toFixed(2), currencyID: currency }],
            TaxSubtotal: taxSubtotals,
          },
        ],
        LegalMonetaryTotal: [
          {
            LineExtensionAmount: [
              {
                _: (invoice.subtotal + invoice.discountAmount).toFixed(2),
                currencyID: currency,
              },
            ],
            TaxExclusiveAmount: [{ _: invoice.subtotal.toFixed(2), currencyID: currency }],
            TaxInclusiveAmount: [{ _: invoice.totalAmount.toFixed(2), currencyID: currency }],
            AllowanceTotalAmount: [{ _: invoice.discountAmount.toFixed(2), currencyID: currency }],
            ChargeTotalAmount: [{ _: '0.00', currencyID: currency }],
            PayableRoundingAmount: [{ _: '0.00', currencyID: currency }],
            PayableAmount: [{ _: invoice.totalAmount.toFixed(2), currencyID: currency }],
          },
        ],
        InvoiceLine: invoiceLines,
      },
    ],
  };
}

/**
 * Base64-encodes the JSON document for API submission.
 */
function encodeDocument(doc) {
  return Buffer.from(JSON.stringify(doc)).toString('base64');
}

/**
 * SHA-256 hashes the JSON document string.
 */
function hashDocument(doc) {
  return crypto.createHash('sha256').update(JSON.stringify(doc)).digest('hex');
}

module.exports = { buildDocument, encodeDocument, hashDocument };

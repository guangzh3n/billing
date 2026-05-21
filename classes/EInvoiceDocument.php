<?php
/**
 * EInvoiceDocument — generates LHDN MyInvois UBL 2.1 JSON format documents.
 *
 * Reference: https://sdk.myinvois.hasil.gov.my/documents/
 *
 * Invoice type codes:
 *  01 = Invoice, 02 = Credit Note, 03 = Debit Note, 04 = Refund Note
 *  11 = Self-Billed Invoice, 12-14 = Self-Billed variants
 *
 * Tax categories (TaxScheme ID "OTH", TaxCategory ID):
 *  01 = Sales Tax, 02 = Service Tax, 03 = Tourism Tax, 04 = High Value Goods Tax
 *  E  = Tax Exempt, OE = Out of Scope, AE = VAT Reverse Charge
 */
class EInvoiceDocument
{
    /**
     * Generate the full MyInvois UBL 2.1 JSON document.
     *
     * @param array $invoice   Row from invoices table + joined customer fields
     * @param array $items     Rows from invoice_items table
     * @param array $customer  Row from customers table
     * @param array $company   Associative array of company settings
     * @return array           JSON-serializable document array
     */
    public function generate(array $invoice, array $items, array $customer, array $company): array
    {
        $issueDate = $invoice['issue_date'];
        $issueTime = date('H:i:s') . 'Z';
        $currency  = $invoice['currency'] ?: 'MYR';

        // Build tax totals grouped by tax category
        $taxSubtotals = $this->buildTaxSubtotals($items, $currency);
        $totalTax     = array_sum(array_column($taxSubtotals, '_taxAmount'));
        $totalSubtotal= (float)$invoice['subtotal'];
        $totalAmount  = (float)$invoice['total_amount'];
        $totalDiscount= (float)$invoice['discount_amount'];

        $doc = [
            '_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            '_A' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            '_B' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'Invoice' => [
                [
                    'ID'                    => [['_' => $invoice['invoice_number']]],
                    'IssueDate'             => [['_' => $issueDate]],
                    'IssueTime'             => [['_' => $issueTime]],
                    'InvoiceTypeCode'       => [['_' => $invoice['invoice_type'], 'listVersionID' => '1.0']],
                    'DocumentCurrencyCode'  => [['_' => $currency]],
                    'TaxCurrencyCode'       => [['_' => $currency]],
                    'InvoicePeriod'         => [
                        [
                            'StartDate'     => [['_' => $issueDate]],
                            'EndDate'       => [['_' => $invoice['due_date'] ?: $issueDate]],
                            'Description'   => [['_' => 'Monthly']],
                        ]
                    ],
                    'BillingReference'      => [],
                    'AdditionalDocumentReference' => [
                        [
                            'ID'            => [['_' => $invoice['invoice_number']]],
                            'DocumentType'  => [['_' => 'CustomsImportForm']],
                        ]
                    ],
                    'AccountingSupplierParty' => [
                        [
                            'AdditionalAccountID' => [['_' => $company['company_tin'] ?: 'NA', 'schemeAgencyName' => 'CertEX']],
                            'Party' => [
                                [
                                    'IndustryClassificationCode' => [['_' => '10110', 'name' => $company['company_name']]],
                                    'PartyIdentification' => [
                                        ['ID' => [['_' => $company['company_tin'] ?: 'NA', 'schemeID' => 'TIN']]],
                                        ['ID' => [['_' => $company['company_id_number'] ?: 'NA', 'schemeID' => $company['company_id_type'] ?: 'BRN']]],
                                    ],
                                    'PostalAddress' => [
                                        [
                                            'CityName'        => [['_' => $company['company_city'] ?: 'Kuala Lumpur']],
                                            'PostalZone'      => [['_' => $company['company_postcode'] ?: '50000']],
                                            'CountrySubentityCode' => [['_' => '14']], // KL code
                                            'AddressLine'     => [
                                                ['Line' => [['_' => $company['company_address'] ?: 'NA']]],
                                                ['Line' => [['_' => $company['company_city'] ?: 'NA']]],
                                                ['Line' => [['_' => $company['company_state'] ?: 'NA']]],
                                            ],
                                            'Country' => [
                                                ['IdentificationCode' => [['_' => 'MYS', 'listID' => 'ISO3166-1', 'listAgencyID' => '6']]],
                                            ],
                                        ]
                                    ],
                                    'PartyLegalEntity' => [
                                        [
                                            'RegistrationName' => [['_' => $company['company_name']]],
                                        ]
                                    ],
                                    'Contact' => [
                                        [
                                            'Telephone'         => [['_' => $company['company_phone'] ?: 'NA']],
                                            'ElectronicMail'    => [['_' => $company['company_email'] ?: 'NA']],
                                        ]
                                    ],
                                ]
                            ],
                        ]
                    ],
                    'AccountingCustomerParty' => [
                        [
                            'Party' => [
                                [
                                    'PartyIdentification' => [
                                        ['ID' => [['_' => $customer['tin'] ?: 'NA', 'schemeID' => 'TIN']]],
                                        ['ID' => [['_' => $customer['id_number'] ?: 'NA', 'schemeID' => $customer['id_type'] ?: 'BRN']]],
                                    ],
                                    'PostalAddress' => [
                                        [
                                            'CityName'        => [['_' => $customer['city'] ?: 'NA']],
                                            'PostalZone'      => [['_' => $customer['postcode'] ?: '00000']],
                                            'CountrySubentityCode' => [['_' => '14']],
                                            'AddressLine'     => [
                                                ['Line' => [['_' => $customer['address'] ?: 'NA']]],
                                                ['Line' => [['_' => $customer['city'] ?: 'NA']]],
                                                ['Line' => [['_' => $customer['state'] ?: 'NA']]],
                                            ],
                                            'Country' => [
                                                ['IdentificationCode' => [['_' => 'MYS', 'listID' => 'ISO3166-1', 'listAgencyID' => '6']]],
                                            ],
                                        ]
                                    ],
                                    'PartyLegalEntity' => [
                                        [
                                            'RegistrationName' => [['_' => $customer['name']]],
                                        ]
                                    ],
                                    'Contact' => [
                                        [
                                            'Telephone'      => [['_' => $customer['phone'] ?: 'NA']],
                                            'ElectronicMail' => [['_' => $customer['email'] ?: 'NA']],
                                        ]
                                    ],
                                ]
                            ],
                        ]
                    ],
                    'Delivery' => [
                        [
                            'DeliveryParty' => [
                                [
                                    'PartyLegalEntity' => [
                                        [
                                            'RegistrationName' => [['_' => $customer['name']]],
                                        ]
                                    ],
                                ]
                            ],
                            'Shipment' => [
                                [
                                    'ID'          => [['_' => 'NA']],
                                    'FreightAllowanceCharge' => [
                                        [
                                            'ChargeIndicator' => [['_' => false]],
                                            'AllowanceChargeReason' => [['_' => 'NA']],
                                            'Amount' => [['_' => '0.00', 'currencyID' => $currency]],
                                        ]
                                    ],
                                ]
                            ],
                        ]
                    ],
                    'PaymentMeans' => [
                        [
                            'PaymentMeansCode' => [['_' => '03']],  // 03 = Cash/Bank Transfer
                            'PayeeFinancialAccount' => [
                                [
                                    'ID' => [['_' => $company['company_bank_account'] ?: 'NA']],
                                ]
                            ],
                        ]
                    ],
                    'PaymentTerms' => [
                        [
                            'Note' => [['_' => 'Payment due ' . ($invoice['due_date'] ? date('d/m/Y', strtotime($invoice['due_date'])) : 'on invoice date')]],
                        ]
                    ],
                    'AllowanceCharge' => [
                        [
                            'ChargeIndicator'        => [['_' => false]],
                            'AllowanceChargeReason'  => [['_' => 'Discount']],
                            'Amount'                 => [['_' => number_format($totalDiscount, 2, '.', ''), 'currencyID' => $currency]],
                        ]
                    ],
                    'TaxTotal' => [
                        [
                            'TaxAmount'     => [['_' => number_format($totalTax, 2, '.', ''), 'currencyID' => $currency]],
                            'TaxSubtotal'   => $this->formatTaxSubtotals($taxSubtotals, $currency),
                        ]
                    ],
                    'LegalMonetaryTotal' => [
                        [
                            'LineExtensionAmount'    => [['_' => number_format($totalSubtotal + $totalDiscount, 2, '.', ''), 'currencyID' => $currency]],
                            'TaxExclusiveAmount'     => [['_' => number_format($totalSubtotal, 2, '.', ''), 'currencyID' => $currency]],
                            'TaxInclusiveAmount'     => [['_' => number_format($totalAmount, 2, '.', ''), 'currencyID' => $currency]],
                            'AllowanceTotalAmount'   => [['_' => number_format($totalDiscount, 2, '.', ''), 'currencyID' => $currency]],
                            'ChargeTotalAmount'      => [['_' => '0.00', 'currencyID' => $currency]],
                            'PayableRoundingAmount'  => [['_' => '0.00', 'currencyID' => $currency]],
                            'PayableAmount'          => [['_' => number_format($totalAmount, 2, '.', ''), 'currencyID' => $currency]],
                        ]
                    ],
                    'InvoiceLine' => $this->buildInvoiceLines($items, $currency),
                ]
            ]
        ];

        return $doc;
    }

    /**
     * Build InvoiceLine array for all items.
     */
    private function buildInvoiceLines(array $items, string $currency): array
    {
        $lines = [];
        foreach ($items as $idx => $item) {
            $lineNum   = $idx + 1;
            $qty       = (float)$item['quantity'];
            $unitPrice = (float)$item['unit_price'];
            $discRate  = (float)$item['discount_rate'];
            $taxRate   = (float)$item['tax_rate'];
            $taxType   = $item['tax_type'] ?: 'E';
            $gross     = $qty * $unitPrice;
            $discAmt   = $gross * ($discRate / 100);
            $taxable   = $gross - $discAmt;
            $taxAmt    = $taxable * ($taxRate / 100);
            $lineTotal = $taxable + $taxAmt;

            $line = [
                'ID'                => [['_' => (string)$lineNum]],
                'InvoicedQuantity'  => [['_' => number_format($qty, 4, '.', ''), 'unitCode' => $item['unit'] ?: 'UNIT']],
                'LineExtensionAmount' => [['_' => number_format($taxable, 2, '.', ''), 'currencyID' => $currency]],
                'AllowanceCharge'   => [
                    [
                        'ChargeIndicator'       => [['_' => false]],
                        'AllowanceChargeReason' => [['_' => 'Discount']],
                        'MultiplierFactorNumeric' => [['_' => number_format($discRate / 100, 2, '.', '')]],
                        'Amount'                => [['_' => number_format($discAmt, 2, '.', ''), 'currencyID' => $currency]],
                    ]
                ],
                'TaxTotal'          => [
                    [
                        'TaxAmount'   => [['_' => number_format($taxAmt, 2, '.', ''), 'currencyID' => $currency]],
                        'TaxSubtotal' => [
                            [
                                'TaxableAmount' => [['_' => number_format($taxable, 2, '.', ''), 'currencyID' => $currency]],
                                'TaxAmount'     => [['_' => number_format($taxAmt, 2, '.', ''), 'currencyID' => $currency]],
                                'Percent'       => [['_' => number_format($taxRate, 2, '.', '')]],
                                'TaxCategory'   => [
                                    [
                                        'ID'        => [['_' => $taxType]],
                                        'TaxExemptionReason' => [['_' => $this->getTaxExemptionReason($taxType)]],
                                        'TaxScheme' => [
                                            ['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]],
                                        ],
                                    ]
                                ],
                            ]
                        ],
                    ]
                ],
                'Item'              => [
                    [
                        'CommodityClassification' => [
                            [
                                'ItemClassificationCode' => [['_' => $item['classification'] ?: '022', 'listID' => 'CLASS']],
                            ]
                        ],
                        'Description'             => [['_' => $item['description']]],
                        'OriginCountry'           => [
                            ['IdentificationCode' => [['_' => 'MYS']]],
                        ],
                    ]
                ],
                'Price'             => [
                    [
                        'PriceAmount'     => [['_' => number_format($unitPrice, 2, '.', ''), 'currencyID' => $currency]],
                        'AllowanceCharge' => [
                            [
                                'ChargeIndicator'       => [['_' => false]],
                                'AllowanceChargeReason' => [['_' => 'Discount']],
                                'Amount'                => [['_' => number_format($discAmt / max($qty, 1), 2, '.', ''), 'currencyID' => $currency]],
                            ]
                        ],
                    ]
                ],
                'ItemPriceExtension' => [
                    [
                        'Amount' => [['_' => number_format($lineTotal, 2, '.', ''), 'currencyID' => $currency]],
                    ]
                ],
            ];

            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Group items by tax type and sum taxable + tax amounts.
     */
    private function buildTaxSubtotals(array $items, string $currency): array
    {
        $groups = [];
        foreach ($items as $item) {
            $taxType   = $item['tax_type'] ?: 'E';
            $taxRate   = (float)$item['tax_rate'];
            $qty       = (float)$item['quantity'];
            $unitPrice = (float)$item['unit_price'];
            $discRate  = (float)$item['discount_rate'];
            $gross     = $qty * $unitPrice;
            $discAmt   = $gross * ($discRate / 100);
            $taxable   = $gross - $discAmt;
            $taxAmt    = $taxable * ($taxRate / 100);
            $key       = $taxType . '_' . $taxRate;
            if (!isset($groups[$key])) {
                $groups[$key] = ['taxType' => $taxType, 'taxRate' => $taxRate, '_taxable' => 0, '_taxAmount' => 0];
            }
            $groups[$key]['_taxable']    += $taxable;
            $groups[$key]['_taxAmount']  += $taxAmt;
        }
        return array_values($groups);
    }

    private function formatTaxSubtotals(array $subtotals, string $currency): array
    {
        $result = [];
        foreach ($subtotals as $sub) {
            $result[] = [
                'TaxableAmount' => [['_' => number_format($sub['_taxable'], 2, '.', ''), 'currencyID' => $currency]],
                'TaxAmount'     => [['_' => number_format($sub['_taxAmount'], 2, '.', ''), 'currencyID' => $currency]],
                'Percent'       => [['_' => number_format($sub['taxRate'], 2, '.', '')]],
                'TaxCategory'   => [
                    [
                        'ID'        => [['_' => $sub['taxType']]],
                        'TaxExemptionReason' => [['_' => $this->getTaxExemptionReason($sub['taxType'])]],
                        'TaxScheme' => [
                            ['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]],
                        ],
                    ]
                ],
            ];
        }
        return $result;
    }

    private function getTaxExemptionReason(string $taxType): string
    {
        $reasons = [
            'E'  => 'Exempt Supply',
            'OE' => 'Out of Scope',
            'AE' => 'Reverse Charge',
            '01' => 'Sales Tax',
            '02' => 'Service Tax',
            '03' => 'Tourism Tax',
            '04' => 'High Value Goods Tax',
        ];
        return $reasons[$taxType] ?? 'NA';
    }

    /**
     * Encode document for submission (base64 of minified JSON string).
     */
    public function encodeDocument(array $document): string
    {
        return base64_encode(json_encode($document, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Compute SHA-256 hash of the JSON document string (hex).
     */
    public function generateHash(array $document): string
    {
        return hash('sha256', json_encode($document, JSON_UNESCAPED_UNICODE));
    }
}

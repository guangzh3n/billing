'use strict';

const axios = require('axios');
const config = require('../config');

const { MYINVOIS_SANDBOX_BASE, MYINVOIS_PROD_BASE } = config;

class MyInvoisAPI {
  constructor(env, clientId, clientSecret) {
    this.baseUrl = env === 'production' ? MYINVOIS_PROD_BASE : MYINVOIS_SANDBOX_BASE;
    this.clientId = clientId;
    this.clientSecret = clientSecret;
    this.accessToken = null;
    this.tokenExpiry = 0;
  }

  async authenticate() {
    if (this.accessToken && Date.now() < this.tokenExpiry) return true;

    const resp = await axios.post(
      `${this.baseUrl}/connect/token`,
      new URLSearchParams({
        grant_type: 'client_credentials',
        client_id: this.clientId,
        client_secret: this.clientSecret,
        scope: 'InvoicingAPI',
      }),
      {
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        timeout: 15000,
      }
    );

    this.accessToken = resp.data.access_token;
    this.tokenExpiry = Date.now() + ((resp.data.expires_in || 3600) - 60) * 1000;
    return true;
  }

  async submitDocuments(documents) {
    await this.authenticate();
    const resp = await axios.post(
      `${this.baseUrl}/api/v1.0/documentsubmissions`,
      { documents },
      {
        headers: {
          Authorization: `Bearer ${this.accessToken}`,
          'Content-Type': 'application/json',
        },
        timeout: 30000,
      }
    );
    return resp.data;
  }

  async getSubmissionStatus(submissionUid) {
    await this.authenticate();
    const resp = await axios.get(
      `${this.baseUrl}/api/v1.0/documents/submissions/${submissionUid}`,
      {
        headers: { Authorization: `Bearer ${this.accessToken}` },
        timeout: 15000,
      }
    );
    return resp.data;
  }

  async getDocumentDetails(uuid) {
    await this.authenticate();
    const resp = await axios.get(
      `${this.baseUrl}/api/v1.0/documents/${uuid}/details`,
      {
        headers: { Authorization: `Bearer ${this.accessToken}` },
        timeout: 15000,
      }
    );
    return resp.data;
  }

  async cancelDocument(uuid, reason) {
    await this.authenticate();
    const resp = await axios.put(
      `${this.baseUrl}/api/v1.0/documents/state/${uuid}/state`,
      { status: 'cancelled', reason },
      {
        headers: {
          Authorization: `Bearer ${this.accessToken}`,
          'Content-Type': 'application/json',
        },
        timeout: 15000,
      }
    );
    return resp.data;
  }
}

module.exports = MyInvoisAPI;

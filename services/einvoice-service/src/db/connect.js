'use strict';

const mongoose = require('mongoose');
const config = require('../config');

let retries = 5;

const connect = async () => {
  while (retries) {
    try {
      await mongoose.connect(config.MONGO_URI);
      console.log('MongoDB connected');
      return;
    } catch (err) {
      retries--;
      if (!retries) throw err;
      console.log(`DB connection failed, retrying... (${retries} left)`);
      await new Promise((r) => setTimeout(r, 3000));
    }
  }
};

module.exports = connect;

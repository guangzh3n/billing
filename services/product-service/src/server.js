'use strict';

const app = require('./app');
const connect = require('./db/connect');
const config = require('./config');

const start = async () => {
  await connect();
  app.listen(config.PORT, () => {
    console.log(`product-service running on port ${config.PORT}`);
  });
};

start().catch((err) => {
  console.error('Failed to start product-service:', err);
  process.exit(1);
});

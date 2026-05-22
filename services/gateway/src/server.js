'use strict';

require('dotenv').config();
const app = require('./app');
const config = require('./config');

app.listen(config.PORT, () => {
  console.log(`Gateway running on :${config.PORT}`);
});

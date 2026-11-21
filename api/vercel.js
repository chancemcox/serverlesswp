const { handler } = require('serverlesswp/wordpress');

/* ==== ERA service-error capture (dev only) — BEGIN =====================
 * Wraps the ServerlessWP handler so service-level failures (database
 * fetch/store, handler throws, process-level rejections) leave a record
 * in R2 under dev-logs/service-errors/ — see ./service-error-log.js.
 * On any non-dev deployment the helper is a complete no-op and this
 * wrapper is a transparent pass-through: same arguments in, same
 * response (or throw) out, status codes untouched.
 * ===================================================================== */
const svcLog = require('./service-error-log');
if (svcLog.enabled) {
    svcLog.installProcessHooks();
}

async function eraHandler(event, context, callback) {
    try {
        const response = await handler(event, context, callback);
        // Storage failures come back as forced 500 responses, not throws.
        await svcLog.maybeLogErrorResponse(event, response);
        return response;
    } catch (err) {
        await svcLog.logServiceError('handler', err, { event });
        throw err;
    }
}

module.exports = eraHandler;
module.exports.handler = eraHandler;
/* ==== ERA service-error capture (dev only) — END ======================= */

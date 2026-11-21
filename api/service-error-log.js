'use strict';
/*
 * ERA Service Error Log (dev only)
 * --------------------------------
 * Captures SERVICE-LEVEL errors from the Node layer around WordPress —
 * the ServerlessWP handler, its S3 database fetch/store, and process-level
 * failures — which never reach PHP and so are invisible to the ERA Dev
 * Logger plugin. Each record is one JSON object written to the
 * serverlesswp-db R2 bucket under:
 *
 *   dev-logs/service-errors/<YYYY-MM-DD>/<HHMMSS>-<rand>.json
 *
 * using the same SQLITE_S3_* environment the storage layer uses
 * (see serverlesswp/src/wordpress/storage.js: SQLITE_S3_BUCKET,
 * SQLITE_S3_API_KEY, SQLITE_S3_API_SECRET, SQLITE_S3_ENDPOINT,
 * SQLITE_S3_REGION).
 *
 * Hard rules:
 *  - DEV ONLY: unless this deployment is the dev branch, every export is
 *    a complete no-op.
 *  - FAIL-SILENT: nothing in here ever throws, changes a status code, or
 *    alters handler behaviour. Writes are bounded by a short timeout.
 *  - No credential values are ever written into a record — only whether
 *    the config was complete.
 */

const crypto = require('node:crypto');

function computeEnabled() {
    try {
        if (process.env['VERCEL_GIT_COMMIT_REF'] === 'dev') return true;
        const url = process.env['VERCEL_URL'] || '';
        if (url.includes('serverlesswp-git-dev')) return true;
        return false;
    } catch (e) {
        return false;
    }
}

const enabled = computeEnabled();

function s3Config() {
    const bucket = process.env['SQLITE_S3_BUCKET'];
    const keyId = process.env['SQLITE_S3_API_KEY'];
    const secret = process.env['SQLITE_S3_API_SECRET'] || process.env['SERVERLESSWP_DATA_SECRET'];
    const endpoint = process.env['SQLITE_S3_ENDPOINT'];
    const region = process.env['SQLITE_S3_REGION'] || 'auto'; // Cloudflare R2 convention.
    if (!bucket || !keyId || !secret || !endpoint) return null;
    let host;
    let base;
    try {
        const u = new URL(endpoint);
        host = u.host;
        base = u.protocol + '//' + u.host;
    } catch (e) {
        return null;
    }
    return { bucket, keyId, secret, region, host, base };
}

function hmac(key, data) {
    return crypto.createHmac('sha256', key).update(data, 'utf8').digest();
}

function sha256hex(data) {
    return crypto.createHash('sha256').update(data, 'utf8').digest('hex');
}

/* Minimal AWS SigV4 PUT (path-style), mirroring the PHP logger. */
async function putObject(cfg, key, body) {
    const service = 's3';
    const now = new Date();
    const iso = now.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
    const amzDate = iso; // YYYYMMDDTHHMMSSZ
    const dateStamp = iso.slice(0, 8);
    const payloadHash = sha256hex(body);
    const canonicalUri = '/' + cfg.bucket + '/' + key;
    const contentType = 'application/json';

    const canonicalHeaders =
        'content-type:' + contentType + '\n' +
        'host:' + cfg.host + '\n' +
        'x-amz-content-sha256:' + payloadHash + '\n' +
        'x-amz-date:' + amzDate + '\n';
    const signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    const canonicalRequest =
        'PUT\n' + canonicalUri + '\n\n' + canonicalHeaders + '\n' +
        signedHeaders + '\n' + payloadHash;

    const scope = dateStamp + '/' + cfg.region + '/' + service + '/aws4_request';
    const stringToSign =
        'AWS4-HMAC-SHA256\n' + amzDate + '\n' + scope + '\n' + sha256hex(canonicalRequest);

    const kDate = hmac('AWS4' + cfg.secret, dateStamp);
    const kRegion = hmac(kDate, cfg.region);
    const kService = hmac(kRegion, service);
    const kSigning = hmac(kService, 'aws4_request');
    const signature = crypto.createHmac('sha256', kSigning).update(stringToSign, 'utf8').digest('hex');

    const authorization =
        'AWS4-HMAC-SHA256 Credential=' + cfg.keyId + '/' + scope +
        ', SignedHeaders=' + signedHeaders + ', Signature=' + signature;

    const controller = new AbortController();
    const timer = setTimeout(() => { try { controller.abort(); } catch (e) {} }, 2500);
    try {
        await fetch(cfg.base + canonicalUri, {
            method: 'PUT',
            headers: {
                'Content-Type': contentType,
                'x-amz-content-sha256': payloadHash,
                'x-amz-date': amzDate,
                'Authorization': authorization,
            },
            body,
            signal: controller.signal,
        });
        return true;
    } catch (e) {
        return false;
    } finally {
        clearTimeout(timer);
    }
}

function truncate(s, n) {
    s = String(s == null ? '' : s);
    return s.length > n ? s.slice(0, n) + '…[truncated]' : s;
}

function requestPath(event) {
    try {
        if (!event) return null;
        const p = event.path || event.rawPath || null;
        if (!p) return null;
        return truncate(String(p).split('?')[0], 300); // path only, never the query string
    } catch (e) {
        return null;
    }
}

function requestHost(event) {
    try {
        if (event && event.headers) {
            for (const k of Object.keys(event.headers)) {
                if (k.toLowerCase() === 'host') return truncate(event.headers[k], 200);
            }
        }
    } catch (e) {}
    return process.env['VERCEL_URL'] || null;
}

/**
 * Write one service-error record. Never throws, never rejects.
 * phase: db_fetch | db_store | php_spawn | handler | unhandled
 */
async function logServiceError(phase, err, extra) {
    try {
        if (!enabled) return false;
        const cfg = s3Config();
        if (!cfg) return false;
        extra = extra || {};
        const now = new Date();
        const day = now.toISOString().slice(0, 10);
        const hms = now.toISOString().slice(11, 19).replace(/:/g, '');
        const rand = crypto.randomBytes(4).toString('hex');
        const key = 'dev-logs/service-errors/' + day + '/' + hms + '-' + rand + '.json';
        const record = {
            ts: now.toISOString(),
            service: 'serverlesswp-node',
            branch: process.env['VERCEL_GIT_COMMIT_REF'] || null,
            host: extra.host || requestHost(extra.event) || null,
            phase: phase || 'handler',
            error_name: err && err.name ? truncate(err.name, 200) : null,
            error_message: truncate(err && err.message ? err.message : String(err || ''), 2000),
            stack: truncate(err && err.stack ? err.stack : '', 4000),
            status_code: typeof extra.statusCode === 'number' ? extra.statusCode : null,
            path: extra.path || requestPath(extra.event),
            note: extra.note ? truncate(extra.note, 500) : null,
            s3_config: 'complete (bucket=' + cfg.bucket + ')',
        };
        return await putObject(cfg, key, JSON.stringify(record, null, 2));
    } catch (e) {
        return false;
    }
}

/*
 * Inspect a handler response for the forced error shapes ServerlessWP
 * returns when the service layer fails (the package converts storage
 * failures into 500 responses instead of throwing):
 *   - database read failure  -> _forceResponse, "could not be read"  => db_fetch
 *   - database save failure  -> "persistence failed / may not have been saved" => db_store
 *   - generic runtime failure -> "There was a problem"               => handler
 * Logs and returns nothing; the response itself is never modified.
 */
async function maybeLogErrorResponse(event, response) {
    try {
        if (!enabled || !response || typeof response !== 'object') return;
        const status = typeof response.statusCode === 'number'
            ? response.statusCode
            : (typeof response.status === 'number' ? response.status : null);
        if (status === null || status < 500) return;
        const body = typeof response.body === 'string' ? response.body : '';
        let phase = 'handler';
        let note = 'handler returned HTTP ' + status;
        if (body.includes('could not be read')) {
            phase = 'db_fetch';
            note = 'ServerlessWP forced database-read error response';
        } else if (body.includes('persistence failed') || body.includes('may not have been saved')) {
            phase = 'db_store';
            note = 'ServerlessWP database persistence error response';
        } else if (body.includes('There was a problem')) {
            phase = 'handler';
            note = 'ServerlessWP generic runtime error response (PHP layer failure is caught inside the package; see note in service-error-log.js)';
        }
        await logServiceError(phase, new Error(note), { event, statusCode: status, note });
    } catch (e) {
        /* fail-silent */
    }
}

let hooksInstalled = false;

/*
 * Process-level last-resort hooks. They log (best effort) and then
 * preserve Node's default fail-fast behaviour by rethrowing, so installing
 * them does not change how the process dies — it only leaves a record
 * behind first. The write is started synchronously; on a dying process it
 * may not complete, which is accepted and documented.
 */
function installProcessHooks() {
    if (!enabled || hooksInstalled) return;
    hooksInstalled = true;
    try {
        process.on('unhandledRejection', (reason) => {
            try { logServiceError('unhandled', reason instanceof Error ? reason : new Error(String(reason)), {}); } catch (e) {}
            throw reason; // preserve default behaviour
        });
        process.on('uncaughtException', (err) => {
            try { logServiceError('unhandled', err, {}); } catch (e) {}
            throw err; // preserve default behaviour
        });
    } catch (e) {
        /* fail-silent */
    }
}

module.exports = { enabled, logServiceError, maybeLogErrorResponse, installProcessHooks };

const HOP_BY_HOP_HEADERS = new Set([
  "connection",
  "content-length",
  "host",
  "keep-alive",
  "proxy-authenticate",
  "proxy-authorization",
  "te",
  "trailer",
  "transfer-encoding",
  "upgrade"
]);

function normalizeOrigin(value) {
  return String(value || "").trim().replace(/\/+$/, "");
}

function normalizePath(value) {
  return String(value || "")
    .trim()
    .replace(/^\/+/, "")
    .replace(/\\/g, "/");
}

function cloneRequestHeaders(request) {
  const headers = new Headers();

  request.headers.forEach((value, key) => {
    if (HOP_BY_HOP_HEADERS.has(key.toLowerCase())) {
      return;
    }

    headers.set(key, value);
  });

  return headers;
}

function rewriteRedirectLocation(locationValue, requestUrl, backendOrigin) {
  if (!locationValue) {
    return locationValue;
  }

  const currentOrigin = new URL(requestUrl).origin;

  try {
    const upstreamLocation = new URL(locationValue, `${backendOrigin}/`);

    if (upstreamLocation.origin === new URL(backendOrigin).origin) {
      return `${currentOrigin}${upstreamLocation.pathname}${upstreamLocation.search}${upstreamLocation.hash}`;
    }

    return upstreamLocation.toString();
  } catch (error) {
    if (locationValue.startsWith("/")) {
      return `${currentOrigin}${locationValue}`;
    }

    return locationValue;
  }
}

function copyResponseHeaders(upstreamResponse, requestUrl, backendOrigin) {
  const headers = new Headers();

  upstreamResponse.headers.forEach((value, key) => {
    const normalizedKey = key.toLowerCase();

    if (HOP_BY_HOP_HEADERS.has(normalizedKey) || normalizedKey === "content-encoding") {
      return;
    }

    if (normalizedKey === "location") {
      headers.set(key, rewriteRedirectLocation(value, requestUrl, backendOrigin));
      return;
    }

    if (normalizedKey === "set-cookie") {
      return;
    }

    headers.append(key, value);
  });

  if (typeof upstreamResponse.headers.getSetCookie === "function") {
    upstreamResponse.headers.getSetCookie().forEach((value) => {
      headers.append("set-cookie", value);
    });
  } else {
    const fallbackCookie = upstreamResponse.headers.get("set-cookie");
    if (fallbackCookie) {
      headers.append("set-cookie", fallbackCookie);
    }
  }

  return headers;
}

function wantsHtmlResponse(request, requestedPath) {
  const accept = String(request.headers.get("accept") || "").toLowerCase();

  return request.method === "GET" && (
    accept.includes("text/html") ||
    requestedPath.endsWith(".php")
  );
}

function buildMissingOriginResponse(request) {
  const message = "BACKEND_ORIGIN is not configured on Vercel.";

  if (wantsHtmlResponse(request, "")) {
    return new Response(
      `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>SNDRA Park Backend Not Configured</title></head><body><h1>Backend Not Configured</h1><p>${message}</p><p>Set <code>BACKEND_ORIGIN</code> to your PHP backend URL, then redeploy.</p></body></html>`,
      {
        status: 500,
        headers: {
          "content-type": "text/html; charset=utf-8",
          "cache-control": "no-store"
        }
      }
    );
  }

  return Response.json({
    success: false,
    message
  }, {
    status: 500,
    headers: {
      "cache-control": "no-store"
    }
  });
}

function buildProxyErrorResponse(request, requestedPath, error) {
  const message = error instanceof Error ? error.message : "Unable to reach the PHP backend.";

  if (wantsHtmlResponse(request, requestedPath)) {
    return new Response(
      `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>SNDRA Park Backend Unavailable</title></head><body><h1>Backend Unavailable</h1><p>${message}</p></body></html>`,
      {
        status: 502,
        headers: {
          "content-type": "text/html; charset=utf-8",
          "cache-control": "no-store"
        }
      }
    );
  }

  return Response.json({
    success: false,
    message
  }, {
    status: 502,
    headers: {
      "cache-control": "no-store"
    }
  });
}

export default async function handler(request) {
  const requestUrl = new URL(request.url);
  const requestedPath = normalizePath(requestUrl.searchParams.get("path"));
  const backendOrigin = normalizeOrigin(
    process.env.BACKEND_ORIGIN ||
    process.env.BACKEND_URL ||
    process.env.PHP_BACKEND_ORIGIN
  );

  if (!requestedPath) {
    return Response.json({
      success: false,
      message: "Missing proxy path."
    }, {
      status: 400
    });
  }

  if (!backendOrigin) {
    return buildMissingOriginResponse(request);
  }

  const upstreamUrl = new URL(`${backendOrigin}/${requestedPath}`);
  const upstreamSearch = new URLSearchParams(requestUrl.searchParams);
  upstreamSearch.delete("path");
  upstreamUrl.search = upstreamSearch.toString();

  const init = {
    method: request.method,
    headers: cloneRequestHeaders(request),
    redirect: "manual"
  };

  if (request.method !== "GET" && request.method !== "HEAD") {
    init.body = await request.arrayBuffer();
  }

  try {
    const upstreamResponse = await fetch(upstreamUrl, init);
    const responseHeaders = copyResponseHeaders(upstreamResponse, request.url, backendOrigin);

    return new Response(upstreamResponse.body, {
      status: upstreamResponse.status,
      statusText: upstreamResponse.statusText,
      headers: responseHeaders
    });
  } catch (error) {
    return buildProxyErrorResponse(request, requestedPath, error);
  }
}

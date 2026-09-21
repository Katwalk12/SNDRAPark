/*
  CSRF token plumbing for the member and booth pages.

  The backend now refuses a state-changing request that arrives without a
  token. The admin dashboard has always handled this itself -- it reads
  csrfToken out of each response and attaches it by hand -- but the member
  dashboard and the booth terminal make their API calls from roughly thirty
  scattered fetch() sites, and threading a token through every one of them
  would leave a gap the first time somebody added the thirty-first.

  So this wraps fetch() once instead. Every same-origin response is read for a
  token, and every same-origin state-changing request gets the latest one
  attached. Pages opt in only by loading this file; nothing else changes.

  Scope is deliberately narrow:
    - Same-origin requests only. A cross-origin call never sees the token.
    - Safe methods (GET/HEAD/OPTIONS) are passed through untouched.
    - An X-CSRF-Token the caller set itself is never overwritten, so the admin
      dashboard keeps working if this file is ever loaded there too.
*/
(function () {
  "use strict";

  const UNSAFE_METHODS = ["POST", "PUT", "PATCH", "DELETE"];
  const HEADER_NAME = "X-CSRF-Token";

  // Per-tab, not per-browser. The token belongs to one session; localStorage
  // would leak a stale one into a tab that has since signed in as someone else.
  const STORAGE_KEY = "sndra.csrfToken";

  const nativeFetch = window.fetch ? window.fetch.bind(window) : null;

  if (!nativeFetch) {
    return;
  }

  let token = readStoredToken();
  let inFlightTokenRequest = null;

  function readStoredToken() {
    try {
      return window.sessionStorage.getItem(STORAGE_KEY) || "";
    } catch (error) {
      // Private mode, or site data blocked. The in-memory copy still works for
      // the life of the page; only a reload has to re-fetch.
      return "";
    }
  }

  function rememberToken(value) {
    if (typeof value !== "string" || value === "" || value === token) {
      return;
    }

    token = value;

    try {
      window.sessionStorage.setItem(STORAGE_KEY, value);
    } catch (error) {
      /* non-fatal, see readStoredToken */
    }
  }

  function forgetToken() {
    token = "";

    try {
      window.sessionStorage.removeItem(STORAGE_KEY);
    } catch (error) {
      /* non-fatal */
    }
  }

  function resolveUrl(input) {
    try {
      if (typeof input === "string") {
        return new URL(input, window.location.href);
      }

      if (input && typeof input.url === "string") {
        return new URL(input.url, window.location.href);
      }
    } catch (error) {
      return null;
    }

    return null;
  }

  function isSameOrigin(url) {
    return Boolean(url) && url.origin === window.location.origin;
  }

  function methodOf(input, init) {
    const raw =
      (init && init.method) ||
      (input && typeof input !== "string" && input.method) ||
      "GET";

    return String(raw).toUpperCase();
  }

  function isUnsafe(method) {
    return UNSAFE_METHODS.indexOf(method) !== -1;
  }

  /*
    Pull a token out of a JSON response without consuming the body the caller
    is about to read -- clone() gives us our own copy of the stream.

    Only same-origin JSON is inspected, and every step is wrapped: a response
    that is announced as JSON but is not must not turn into a page error.
  */
  function captureTokenFrom(response) {
    if (!response) {
      return;
    }

    const contentType = response.headers.get("content-type") || "";

    if (contentType.toLowerCase().indexOf("application/json") === -1) {
      return;
    }

    let copy;

    try {
      copy = response.clone();
    } catch (error) {
      return;
    }

    copy
      .json()
      .then(function (payload) {
        if (!payload || typeof payload !== "object") {
          return;
        }

        const found =
          (payload.data && payload.data.csrfToken) || payload.csrfToken;

        if (typeof found === "string") {
          rememberToken(found);
        }
      })
      .catch(function () {
        /* not JSON after all, or an empty body */
      });
  }

  /*
    Fetch a token for a page that has not seen one yet.

    Concurrent callers share one request: a dashboard that fires several POSTs
    on load should not open several token requests, each of which would be
    answered with the same token anyway.
  */
  function acquireToken() {
    if (token) {
      return Promise.resolve(token);
    }

    if (inFlightTokenRequest) {
      return inFlightTokenRequest;
    }

    const endpoint =
      typeof window.getSndraBackendPath === "function"
        ? window.getSndraBackendPath("/backend/auth/csrf-token.php")
        : "/backend/auth/csrf-token.php";

    inFlightTokenRequest = nativeFetch(endpoint, {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      cache: "no-store"
    })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (payload) {
        const found = payload && payload.data && payload.data.csrfToken;

        if (typeof found === "string") {
          rememberToken(found);
        }

        return token;
      })
      .catch(function () {
        // Offline, or the endpoint is unreachable. Send the request without a
        // token and let the server decide; failing here would break pages that
        // are only doing safe work.
        return "";
      })
      .then(function (value) {
        inFlightTokenRequest = null;
        return value;
      });

    return inFlightTokenRequest;
  }

  function hasExplicitToken(init, input) {
    const sources = [init && init.headers, input && input.headers];

    for (let index = 0; index < sources.length; index += 1) {
      const headers = sources[index];

      if (!headers) {
        continue;
      }

      if (typeof Headers !== "undefined" && headers instanceof Headers) {
        if (headers.has(HEADER_NAME)) {
          return true;
        }
        continue;
      }

      if (Array.isArray(headers)) {
        const found = headers.some(function (pair) {
          return (
            Array.isArray(pair) &&
            String(pair[0]).toLowerCase() === HEADER_NAME.toLowerCase()
          );
        });

        if (found) {
          return true;
        }
        continue;
      }

      if (typeof headers === "object") {
        const keys = Object.keys(headers);
        const found = keys.some(function (key) {
          return key.toLowerCase() === HEADER_NAME.toLowerCase();
        });

        if (found) {
          return true;
        }
      }
    }

    return false;
  }

  function withTokenHeader(input, init, value) {
    const nextInit = Object.assign({}, init || {});
    const headers = new Headers(
      (init && init.headers) ||
        (input && typeof input !== "string" && input.headers) ||
        {}
    );

    headers.set(HEADER_NAME, value);
    nextInit.headers = headers;

    // These endpoints read the session cookie, so it has to be sent. Most call
    // sites already say so; this makes it true for the ones that forgot.
    if (!nextInit.credentials) {
      nextInit.credentials = "same-origin";
    }

    return nextInit;
  }

  window.fetch = function patchedFetch(input, init) {
    const url = resolveUrl(input);
    const method = methodOf(input, init);

    if (!isSameOrigin(url)) {
      return nativeFetch(input, init);
    }

    if (!isUnsafe(method)) {
      return nativeFetch(input, init).then(function (response) {
        captureTokenFrom(response);
        return response;
      });
    }

    if (hasExplicitToken(init, input)) {
      return nativeFetch(input, init).then(function (response) {
        captureTokenFrom(response);
        return response;
      });
    }

    return acquireToken().then(function (value) {
      const nextInit = value ? withTokenHeader(input, init, value) : init;

      return nativeFetch(input, nextInit).then(function (response) {
        captureTokenFrom(response);

        /*
          One retry, and only one.

          A token can go stale for honest reasons -- it expires after an hour,
          and it is deliberately rotated on sign-in -- and the user should not
          have to reload the page to recover. A second 403 is a real refusal
          and is handed back to the caller untouched.
        */
        if (response.status === 403 && value) {
          forgetToken();

          return acquireToken().then(function (fresh) {
            if (!fresh || fresh === value) {
              return response;
            }

            return nativeFetch(
              input,
              withTokenHeader(input, init, fresh)
            ).then(function (retried) {
              captureTokenFrom(retried);
              return retried;
            });
          });
        }

        return response;
      });
    });
  };

  // Exposed for the few places that build a request by hand (a classic form
  // post, say) rather than going through fetch.
  window.SNDRA_CSRF = {
    headerName: HEADER_NAME,
    fieldName: "_csrf_token",
    get current() {
      return token;
    },
    ensure: acquireToken
  };
})();

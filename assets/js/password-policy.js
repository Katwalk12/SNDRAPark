/**
 * SNDRA Park - shared password policy (browser side).
 *
 * Mirrors backend/utils/PasswordPolicy.php so sign up, password change and the
 * forgot-password reset page all show the same rules before the request is sent.
 * The backend remains the authority - this is only for instant feedback.
 */
(function (global) {
  "use strict";

  const MIN_LENGTH = 8;
  const MAX_LENGTH = 128;
  const MIN_PERSONAL_TOKEN_LENGTH = 3;

  const WEAK_FRAGMENTS = [
    "password",
    "passw0rd",
    "p@ssw0rd",
    "qwerty",
    "letmein",
    "welcome",
    "iloveyou",
    "changeme",
    "sndrapark",
    "sndra",
    "parking",
    "123456",
    "12345678",
    "abcdef"
  ];

  const EXACT_WEAK_PASSWORDS = [
    "password", "password1", "password123", "passw0rd", "12345678", "123456789", "1234567890",
    "qwerty", "qwerty123", "abc123", "admin", "admin123", "root", "user", "guest",
    "welcome", "welcome1", "letmein", "monkey", "iloveyou", "sunshine", "changeme",
    "p@ssw0rd", "p@ssword", "secret", "test1234", "default"
  ];

  const RULES = [
    { id: "length", label: `At least ${MIN_LENGTH} characters long` },
    { id: "case", label: "Uppercase and lowercase letters" },
    { id: "number", label: "At least one number" },
    { id: "special", label: "At least one special character" },
    { id: "personal", label: "No name, email or birth date" }
  ];

  function normalizeContext(context) {
    const source = context || {};

    return {
      firstName: String(source.firstName || source.first_name || ""),
      lastName: String(source.lastName || source.last_name || ""),
      fullName: String(source.fullName || source.full_name || ""),
      email: String(source.email || ""),
      birthDate: String(source.birthDate || source.birth_date || "")
    };
  }

  function birthDateTokens(birthDate) {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(birthDate).trim());

    if (!match) {
      return [];
    }

    const [, year, month, day] = match;
    const shortYear = year.slice(-2);
    const monthNames = [
      "january", "february", "march", "april", "may", "june",
      "july", "august", "september", "october", "november", "december"
    ];

    return [
      year,
      month + day,
      day + month,
      month + day + year,
      day + month + year,
      year + month + day,
      month + day + shortYear,
      day + month + shortYear,
      monthNames[Number(month) - 1] || ""
    ];
  }

  function personalTokens(context) {
    const details = normalizeContext(context);
    const tokens = [];

    [details.firstName, details.lastName, details.fullName].forEach((value) => {
      value
        .toLowerCase()
        .split(/[^a-z]+/)
        .forEach((word) => {
          if (word.length >= MIN_PERSONAL_TOKEN_LENGTH) {
            tokens.push(word);
          }
        });
    });

    const email = details.email.trim().toLowerCase();

    if (email) {
      const localPart = email.split("@")[0];

      if (localPart.length >= MIN_PERSONAL_TOKEN_LENGTH) {
        tokens.push(localPart);
      }

      localPart.split(/[^a-z0-9]+/).forEach((word) => {
        if (word.length >= MIN_PERSONAL_TOKEN_LENGTH) {
          tokens.push(word);
        }
      });
    }

    birthDateTokens(details.birthDate).forEach((token) => {
      if (token) {
        tokens.push(token);
      }
    });

    return Array.from(new Set(tokens.filter(Boolean)));
  }

  function isCommonPassword(password) {
    const normalized = String(password).toLowerCase();

    if (EXACT_WEAK_PASSWORDS.indexOf(normalized) >= 0) {
      return true;
    }

    if (WEAK_FRAGMENTS.some((fragment) => normalized.indexOf(fragment) >= 0)) {
      return true;
    }

    return /^(.)\1+$/.test(password);
  }

  function containsPersonalInfo(password, context) {
    const normalized = String(password).toLowerCase();

    return personalTokens(context).some((token) => normalized.indexOf(token) >= 0);
  }

  /**
   * Rule-by-rule result for the requirement checklist.
   */
  function evaluate(password, context) {
    const value = String(password || "");

    const checks = {
      length: value.length >= MIN_LENGTH && value.length <= MAX_LENGTH,
      case: /[A-Z]/.test(value) && /[a-z]/.test(value),
      number: /\d/.test(value),
      special: /[^A-Za-z0-9]/.test(value),
      personal: value !== "" && !containsPersonalInfo(value, context) && !isCommonPassword(value)
    };

    const errors = [];

    if (value.length < MIN_LENGTH) {
      errors.push(`Password must be at least ${MIN_LENGTH} characters long.`);
    }

    if (value.length > MAX_LENGTH) {
      errors.push(`Password must be ${MAX_LENGTH} characters or fewer.`);
    }

    if (!/[A-Z]/.test(value)) {
      errors.push("Password must contain at least one uppercase letter.");
    }

    if (!/[a-z]/.test(value)) {
      errors.push("Password must contain at least one lowercase letter.");
    }

    if (!/\d/.test(value)) {
      errors.push("Password must contain at least one number.");
    }

    if (!/[^A-Za-z0-9]/.test(value)) {
      errors.push("Password must contain at least one special character (for example ! @ # $ %).");
    }

    if (/\s/.test(value)) {
      errors.push("Password must not contain spaces.");
    }

    if (value && isCommonPassword(value)) {
      errors.push("Password is too common and easy to guess. Please choose another one.");
    }

    if (value && containsPersonalInfo(value, context)) {
      errors.push("Password must not contain your name, email or birth date.");
    }

    return {
      valid: errors.length === 0,
      errors,
      checks,
      score: score(value, context)
    };
  }

  /**
   * 0-4 strength score, matching the four segments of the meter.
   */
  function score(password, context) {
    const value = String(password || "");

    if (!value) {
      return 0;
    }

    let total = 0;

    if (/[A-Z]/.test(value)) {
      total += 1;
    }

    if (/[a-z]/.test(value)) {
      total += 1;
    }

    if (/\d/.test(value)) {
      total += 1;
    }

    if (/[^A-Za-z0-9]/.test(value) && value.length >= MIN_LENGTH) {
      total += 1;
    }

    if (isCommonPassword(value) || containsPersonalInfo(value, context)) {
      total = Math.min(total, 1);
    }

    return total;
  }

  function firstError(password, context) {
    return evaluate(password, context).errors[0] || "";
  }

  global.SndraPasswordPolicy = {
    MIN_LENGTH,
    MAX_LENGTH,
    RULES,
    evaluate,
    score,
    firstError,
    personalTokens,
    isCommonPassword,
    containsPersonalInfo
  };
})(window);

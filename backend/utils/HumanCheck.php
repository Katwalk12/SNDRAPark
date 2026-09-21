<?php

declare(strict_types=1);

/**
 * Human verification for the public sign-up form.
 *
 * Deliberately self-contained: no reCAPTCHA, no hCaptcha, no third-party
 * script. Those need an account and API keys this project does not have, they
 * do not work offline on the XAMPP box the system runs on, and they hand every
 * visitor's IP to someone else. Everything here is checked against the PHP
 * session instead.
 *
 * Three layers, cheapest first:
 *
 *   1. A bait field no person can fill. Silent, no friction, catches the
 *      scripts that simply post every input they find.
 *   2. How long the form was open. Issued server-side, so it cannot be
 *      back-dated by the client the way a hidden timestamp can.
 *   3. A question, generated per visitor, whose answer never leaves the
 *      server. This is the part a person actually sees and answers.
 *
 * The answer is held as a hash with an expiry, a use count, and single-use
 * semantics, so a solved challenge cannot be replayed for a second account.
 */
class HumanCheck
{
    private const SESSION_KEY = '_human_check';

    /** A challenge stays good for one sitting at the form. */
    private const TTL_SECONDS = 900;

    /**
     * Nobody completes a sign-up in under three seconds. Generous on purpose:
     * turning a real applicant away is worse than letting a slow bot through,
     * and the other two layers are still in front of it.
     */
    private const MIN_FILL_SECONDS = 3;

    /** Wrong three times and the challenge is spent; the form asks for a new one. */
    private const MAX_ATTEMPTS = 3;

    /**
     * Named so browser autofill has no reason to touch it. Anything resembling
     * a real field risks a password manager filling it and turning a genuine
     * applicant away. The markup in signup.html must use this exact name.
     */
    public static function fieldName(): string
    {
        return 'extra_notes_hp';
    }

    private static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Numbers are spelled out so the sum cannot be lifted with a digit regex
     * and evaluated, which is all most scripted solvers try.
     */
    private static function numberWord(int $value): string
    {
        $words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six',
            'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];

        return $words[$value] ?? (string) $value;
    }

    /**
     * Issue a fresh challenge and keep only its answer hash.
     *
     * @return array{question:string, expiresIn:int}
     */
    public static function issue(): array
    {
        self::startSession();

        $left = random_int(1, 9);
        $right = random_int(1, 9);

        // Subtraction only when it stays positive; a negative answer is a
        // needless way to fail an honest person.
        $useAddition = $left < $right || random_int(0, 1) === 1;

        if ($useAddition) {
            $question = sprintf(
                'What is %s plus %s?',
                self::numberWord($left),
                self::numberWord($right)
            );
            $answer = $left + $right;
        } else {
            $question = sprintf(
                'What is %s minus %s?',
                self::numberWord($left),
                self::numberWord($right)
            );
            $answer = $left - $right;
        }

        $_SESSION[self::SESSION_KEY] = [
            'answer_hash' => password_hash((string) $answer, PASSWORD_DEFAULT),
            'issued_at' => time(),
            'expires_at' => time() + self::TTL_SECONDS,
            'attempts' => 0
        ];

        return [
            'question' => $question,
            'expiresIn' => self::TTL_SECONDS
        ];
    }

    /**
     * Read an answer that may arrive as digits or as a word.
     *
     * "8", " 8 " and "eight" are the same answer from a person's point of
     * view, and refusing the spelled-out form would only punish whoever read
     * the question literally.
     */
    private static function normalizeAnswer($value): ?int
    {
        $raw = strtolower(trim((string) ($value ?? '')));

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^-?\d{1,3}$/', $raw)) {
            return (int) $raw;
        }

        for ($number = 0; $number <= 12; $number++) {
            if ($raw === self::numberWord($number)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * Run every layer against one submission.
     *
     * @param array $data The decoded request body.
     * @throws InvalidArgumentException 422, with a message meant for a person.
     */
    public static function verify(array $data): void
    {
        self::startSession();

        // 1. The bait. A generic message on purpose: an automated client that
        // is told which field gave it away simply stops filling that one.
        if (trim((string) ($data[self::fieldName()] ?? '')) !== '') {
            throw new InvalidArgumentException('We could not verify this submission. Please try again.', 422);
        }

        $challenge = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($challenge)) {
            throw new InvalidArgumentException('Your verification question expired. Refresh the page and try again.', 422);
        }

        if (time() > (int) ($challenge['expires_at'] ?? 0)) {
            unset($_SESSION[self::SESSION_KEY]);
            throw new InvalidArgumentException('Your verification question expired. Refresh the page and try again.', 422);
        }

        // 2. Time on the form, measured from when the server issued the
        // question rather than from anything the client reported.
        if ((time() - (int) ($challenge['issued_at'] ?? 0)) < self::MIN_FILL_SECONDS) {
            throw new InvalidArgumentException('That was submitted too quickly. Please try again.', 422);
        }

        // 3. The answer.
        $answer = self::normalizeAnswer($data['humanCheckAnswer'] ?? null);

        if ($answer === null) {
            self::registerFailedAttempt();
            throw new InvalidArgumentException('Please answer the verification question.', 422);
        }

        if (!password_verify((string) $answer, (string) ($challenge['answer_hash'] ?? ''))) {
            $remaining = self::registerFailedAttempt();

            throw new InvalidArgumentException(
                $remaining > 0
                    ? 'That answer is not correct. Please try again.'
                    : 'That answer is not correct. Refresh the page for a new question.',
                422
            );
        }

        // Single use. Without this one solved question could register accounts
        // all afternoon.
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Count a wrong answer and spend the challenge once it runs out.
     *
     * @return int Attempts left before a new question is required.
     */
    private static function registerFailedAttempt(): int
    {
        $attempts = (int) ($_SESSION[self::SESSION_KEY]['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            unset($_SESSION[self::SESSION_KEY]);
            return 0;
        }

        $_SESSION[self::SESSION_KEY]['attempts'] = $attempts;

        return self::MAX_ATTEMPTS - $attempts;
    }
}

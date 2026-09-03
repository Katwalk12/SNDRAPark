<?php

require_once __DIR__ . '/../config/database.php';

class User
{
    public function findById($id)
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'SELECT id, first_name, last_name, full_name, birth_date, email, role, status,
                    vehicle_type, plate_number, vehicle_brand, vehicle_model, vehicle_color,
                    warning_count, first_warning_at, account_locked_until, account_status,
                    failed_login_attempts, last_failed_login_at, login_locked_until, password_changed_at,
                    created_at
             FROM users
             WHERE id = ?
             LIMIT 1'
        );
        $statement->bind_param('i', $id);
        $statement->execute();
        $result = $statement->get_result();

        return $result->fetch_assoc();
    }

    public function findByEmail($email)
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'SELECT id, first_name, last_name, full_name, birth_date, email, google_id, password_hash, role, status,
                vehicle_type, plate_number, vehicle_brand, vehicle_model, vehicle_color,
                warning_count, first_warning_at, account_locked_until, account_status,
                failed_login_attempts, last_failed_login_at, login_locked_until, password_changed_at,
                created_at
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $statement->bind_param('s', $email);
        $statement->execute();
        $result = $statement->get_result();

        return $result->fetch_assoc();
    }

    public function findByGoogleId($googleId)
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'SELECT id, first_name, last_name, full_name, birth_date, email, google_id, password_hash, role, status,
                vehicle_type, plate_number, vehicle_brand, vehicle_model, vehicle_color,
                warning_count, first_warning_at, account_locked_until, account_status,
                failed_login_attempts, last_failed_login_at, login_locked_until, password_changed_at,
                created_at
             FROM users
             WHERE google_id = ?
             LIMIT 1'
        );
        $statement->bind_param('s', $googleId);
        $statement->execute();
        $result = $statement->get_result();

        return $result->fetch_assoc();
    }

    /**
     * Attach a Google account to an existing user so the next sign in matches by google_id.
     */
    public function linkGoogleId($id, $googleId)
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'UPDATE users
             SET google_id = ?
             WHERE id = ?'
        );
        $statement->bind_param('si', $googleId, $id);
        $statement->execute();

        return $statement->affected_rows >= 0;
    }

    /**
     * Just-in-time registration for a Google sign in.
     *
     * The password hash stays empty on purpose: that is how the login endpoint
     * recognises a Google-only account and tells the user to sign in with Google.
     */
    public function createFromGoogle(array $profile)
    {
        $connection = Database::connection();

        $firstName = trim((string) ($profile['first_name'] ?? ''));
        $lastName  = trim((string) ($profile['last_name'] ?? ''));
        $email     = trim((string) ($profile['email'] ?? ''));
        $googleId  = trim((string) ($profile['google_id'] ?? ''));
        $fullName  = trim($firstName . ' ' . $lastName);

        if ($fullName === '') {
            $fullName = explode('@', $email)[0];
        }

        $statement = $connection->prepare(
            'INSERT INTO users
               (first_name, last_name, full_name, email, google_id, password_hash, role, password_changed_at)
             VALUES (NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, \'\', \'user\', NOW())'
        );
        $statement->bind_param('sssss', $firstName, $lastName, $fullName, $email, $googleId);
        $statement->execute();

        return $connection->insert_id;
    }

    public function create($firstName, $lastName = null, $birthDate = null, $email = null, $password = null)
    {
        $connection = Database::connection();
        $vehicleType  = null;
        $plateNumber  = null;
        $vehicleBrand = null;
        $vehicleModel = null;
        $vehicleColor = null;

        if (is_array($firstName)) {
            $data = $firstName;
            $firstName    = trim((string) ($data['first_name'] ?? ''));
            $lastName     = trim((string) ($data['last_name'] ?? ''));
            $birthDate    = $data['birth_date'] ?? null;
            $email        = trim((string) ($data['email'] ?? ''));
            $passwordHash = (string) ($data['password_hash'] ?? '');
            $role         = trim((string) ($data['role'] ?? 'user')) ?: 'user';
            $vehicleType  = isset($data['vehicle_type']) && $data['vehicle_type'] !== '' ? (string) $data['vehicle_type'] : null;
            $plateNumber  = isset($data['plate_number']) && $data['plate_number'] !== '' ? strtoupper(trim((string) $data['plate_number'])) : null;
            $vehicleBrand = isset($data['vehicle_brand']) && $data['vehicle_brand'] !== '' ? trim((string) $data['vehicle_brand']) : null;
            $vehicleModel = isset($data['vehicle_model']) && $data['vehicle_model'] !== '' ? trim((string) $data['vehicle_model']) : null;
            $vehicleColor = isset($data['vehicle_color']) && $data['vehicle_color'] !== '' ? trim((string) $data['vehicle_color']) : null;
        } else {
            $passwordHash = password_hash((string) $password, PASSWORD_DEFAULT);
            $role = 'user';
        }

        $fullName = trim(trim((string) $firstName . ' ' . (string) $lastName));
        $birthDateValue = $birthDate ?: '';

        $statement = $connection->prepare(
            'INSERT INTO users
               (first_name, last_name, full_name, birth_date, email, password_hash, role,
                vehicle_type, plate_number, vehicle_brand, vehicle_model, vehicle_color,
                password_changed_at)
             VALUES (?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $statement->bind_param(
            'ssssssssssss',
            $firstName, $lastName, $fullName, $birthDateValue,
            $email, $passwordHash, $role,
            $vehicleType, $plateNumber, $vehicleBrand, $vehicleModel, $vehicleColor
        );
        $statement->execute();

        return $connection->insert_id;
    }

    public function update($id, $fullName, $email, $birthDate = null, $password = '', $vehicleType = null, $plateNumber = null, $vehicleBrand = null, $vehicleModel = null, $vehicleColor = null)
    {
        $connection = Database::connection();
        $nameParts = preg_split('/\s+/', trim($fullName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';
        $birthDateValue = $birthDate ?: null;

        // Normalize vehicle fields
        $vehicleType  = ($vehicleType !== null && $vehicleType !== '') ? $vehicleType : null;
        $plateNumber  = ($plateNumber !== null && $plateNumber !== '') ? strtoupper(trim($plateNumber)) : null;
        $vehicleBrand = ($vehicleBrand !== null && $vehicleBrand !== '') ? trim($vehicleBrand) : null;
        $vehicleModel = ($vehicleModel !== null && $vehicleModel !== '') ? trim($vehicleModel) : null;
        $vehicleColor = ($vehicleColor !== null && $vehicleColor !== '') ? trim($vehicleColor) : null;

        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $statement = $connection->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, full_name = ?, email = ?, birth_date = ?,
                     password_hash = ?, password_changed_at = NOW(),
                     vehicle_type = ?, plate_number = ?, vehicle_brand = ?, vehicle_model = ?, vehicle_color = ?
                 WHERE id = ?'
            );
            $statement->bind_param(
                'sssssssssssi',
                $firstName, $lastName, $fullName, $email, $birthDateValue,
                $passwordHash,
                $vehicleType, $plateNumber, $vehicleBrand, $vehicleModel, $vehicleColor,
                $id
            );
        } else {
            $statement = $connection->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, full_name = ?, email = ?, birth_date = ?,
                     vehicle_type = ?, plate_number = ?, vehicle_brand = ?, vehicle_model = ?, vehicle_color = ?
                 WHERE id = ?'
            );
            $statement->bind_param(
                'ssssssssssi',
                $firstName, $lastName, $fullName, $email, $birthDateValue,
                $vehicleType, $plateNumber, $vehicleBrand, $vehicleModel, $vehicleColor,
                $id
            );
        }

        $statement->execute();

        return $statement->affected_rows >= 0;
    }

    public function updatePassword($id, $passwordHash)
    {
        $connection = Database::connection();

        // A new password also refreshes the password age and clears any failed login lockout.
        $statement = $connection->prepare(
            'UPDATE users
             SET password_hash = ?,
                 password_changed_at = NOW(),
                 failed_login_attempts = 0,
                 last_failed_login_at = NULL,
                 login_locked_until = NULL
             WHERE id = ?'
        );
        $statement->bind_param('si', $passwordHash, $id);
        $statement->execute();

        return $statement->affected_rows >= 0;
    }
}

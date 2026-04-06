<?php

require_once __DIR__ . '/../config/database.php';

class User
{
    public function findById($id)
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'SELECT id, first_name, last_name, full_name, birth_date, email, role, status, warning_count, first_warning_at, account_locked_until, account_status, created_at
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
            'SELECT id, first_name, last_name, full_name, birth_date, email, password_hash, role, status, warning_count, first_warning_at, account_locked_until, account_status, created_at
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $statement->bind_param('s', $email);
        $statement->execute();
        $result = $statement->get_result();

        return $result->fetch_assoc();
    }

    public function create($firstName, $lastName = null, $birthDate = null, $email = null, $password = null)
    {
        $connection = Database::connection();

        if (is_array($firstName)) {
            $data = $firstName;
            $firstName = trim((string) ($data['first_name'] ?? ''));
            $lastName = trim((string) ($data['last_name'] ?? ''));
            $birthDate = $data['birth_date'] ?? null;
            $email = trim((string) ($data['email'] ?? ''));
            $passwordHash = (string) ($data['password_hash'] ?? '');
            $role = trim((string) ($data['role'] ?? 'user')) ?: 'user';
        } else {
            $passwordHash = password_hash((string) $password, PASSWORD_DEFAULT);
            $role = 'user';
        }

        $fullName = trim(trim((string) $firstName . ' ' . (string) $lastName));
        $birthDateValue = $birthDate ?: '';
        $statement = $connection->prepare(
            'INSERT INTO users (first_name, last_name, full_name, birth_date, email, password_hash, role)
             VALUES (?, ?, ?, NULLIF(?, \'\'), ?, ?, ?)'
        );
        $statement->bind_param('sssssss', $firstName, $lastName, $fullName, $birthDateValue, $email, $passwordHash, $role);
        $statement->execute();

        return $connection->insert_id;
    }

    public function update($id, $fullName, $email, $birthDate = null, $password = '')
    {
        $connection = Database::connection();
        $nameParts = preg_split('/\s+/', trim($fullName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';
        $birthDateValue = $birthDate ?: null;

        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $statement = $connection->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, full_name = ?, email = ?, birth_date = ?, password_hash = ?
                 WHERE id = ?'
            );
            $statement->bind_param('ssssssi', $firstName, $lastName, $fullName, $email, $birthDateValue, $passwordHash, $id);
        } else {
            $statement = $connection->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, full_name = ?, email = ?, birth_date = ?
                 WHERE id = ?'
            );
            $statement->bind_param('sssssi', $firstName, $lastName, $fullName, $email, $birthDateValue, $id);
        }

        $statement->execute();

        return $statement->affected_rows >= 0;
    }

    public function updatePassword($id, $passwordHash)
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'UPDATE users
             SET password_hash = ?
             WHERE id = ?'
        );
        $statement->bind_param('si', $passwordHash, $id);
        $statement->execute();

        return $statement->affected_rows >= 0;
    }
}

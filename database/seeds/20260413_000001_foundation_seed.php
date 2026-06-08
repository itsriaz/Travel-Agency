<?php

declare(strict_types=1);

use App\Helpers\PasswordHasher;

return static function (\PDO $db): void {
    $db->exec("INSERT INTO branches (id, code, name, city, country_code, base_currency, is_active) VALUES
        (1, 'swat', 'Imdad International Travel Agency', 'Swat', 'PK', 'PKR', 1),
        (2, 'dubai', 'Noble Route', 'Dubai', 'AE', 'AED', 1)
        ON DUPLICATE KEY UPDATE
        code = VALUES(code),
        name = VALUES(name),
        city = VALUES(city),
        country_code = VALUES(country_code),
        base_currency = VALUES(base_currency),
        is_active = VALUES(is_active)
    ");

    $db->exec("INSERT IGNORE INTO roles (id, code, name) VALUES
        (1, 'super_admin', 'Super Admin'),
        (2, 'employee', 'Employee')
    ");

    $superAdminPasswordHash = PasswordHasher::make('ChangeMeNow!123');
    $superAdmins = [
        ['id' => 1, 'default_branch_id' => 1, 'name' => 'Dr. Muhammad Munir', 'username' => 'm.munir', 'email' => 'munir@travelagency.local'],
        ['id' => 2, 'default_branch_id' => 1, 'name' => 'Imdad Ullah', 'username' => 'imdad.ullah', 'email' => 'imdad.ullah@travelagency.local'],
        ['id' => 3, 'default_branch_id' => 1, 'name' => 'Salman Faiz', 'username' => 'salman.faiz', 'email' => 'salman.faiz@travelagency.local'],
        ['id' => 4, 'default_branch_id' => 1, 'name' => 'Abubakar', 'username' => 'abubakar', 'email' => 'abubakar@travelagency.local'],
        ['id' => 5, 'default_branch_id' => 1, 'name' => 'Fida Hussain Khan', 'username' => 'fida.hussain', 'email' => 'fida.hussain@travelagency.local'],
        ['id' => 10, 'default_branch_id' => 1, 'name' => 'Development Super Admin', 'username' => 'superadmin', 'email' => 'superadmin@travelagency.local'],
    ];

    $roleId = 1;
    $statement = $db->prepare(
        'INSERT INTO users (
            id, role_id, default_branch_id, name, username, email, password_hash, is_active,
            must_change_password, force_password_change_reason, password_changed_at
         )
         VALUES (
            :id, :role_id, :default_branch_id, :name, :username, :email, :password_hash, 1, 1, :force_reason, NULL
         )
         ON DUPLICATE KEY UPDATE
         role_id = VALUES(role_id),
         default_branch_id = VALUES(default_branch_id),
         name = VALUES(name),
         username = VALUES(username),
         email = VALUES(email),
         password_hash = VALUES(password_hash),
         is_active = VALUES(is_active),
         must_change_password = VALUES(must_change_password),
         force_password_change_reason = VALUES(force_password_change_reason),
         password_changed_at = VALUES(password_changed_at)'
    );

    foreach ($superAdmins as $superAdmin) {
        $statement->execute([
            'id' => $superAdmin['id'],
            'role_id' => $roleId,
            'default_branch_id' => $superAdmin['default_branch_id'],
            'name' => $superAdmin['name'],
            'username' => $superAdmin['username'],
            'email' => $superAdmin['email'],
            'password_hash' => $superAdminPasswordHash,
            'force_reason' => 'first_login',
        ]);
    }

    $db->exec('DELETE FROM user_branch_access WHERE user_id IN (1,2,3,4,5,10)');
    $accessStatement = $db->prepare('INSERT IGNORE INTO user_branch_access (user_id, branch_id) VALUES (:user_id, :branch_id)');
    foreach ($superAdmins as $superAdmin) {
        foreach ([1, 2] as $branchId) {
            $accessStatement->execute([
                'user_id' => $superAdmin['id'],
                'branch_id' => $branchId,
            ]);
        }
    }

    $legacyUserStatement = $db->prepare(
        "UPDATE users
         SET is_active = 0,
             updated_at = NOW()
         WHERE id NOT IN (1,2,3,4,5,10)"
    );
    $legacyUserStatement->execute();

    $statement = $db->prepare(
        'INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
         (:app_name, :app_name_value),
         (:reporting_currency, :reporting_currency_value)'
    );
    $statement->execute([
        'app_name' => 'app.name',
        'app_name_value' => 'Travel Agency Operations',
        'reporting_currency' => 'reporting.currency',
        'reporting_currency_value' => 'PKR',
    ]);
};

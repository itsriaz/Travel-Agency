<?php

declare(strict_types=1);

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

    $superAdminPasswordHash = password_hash('ChangeMeNow!123', PASSWORD_DEFAULT);
    $employeePasswordHash = password_hash('EmployeePass!123', PASSWORD_DEFAULT);
    $statement = $db->prepare(
        'INSERT INTO users (
            id, role_id, default_branch_id, name, username, email, password_hash, is_active,
            must_change_password, force_password_change_reason, password_changed_at
         )
         VALUES
         (1, 1, 1, :admin_name, :admin_username, :admin_email, :admin_password_hash, 1, 1, :admin_force_reason, NULL),
         (2, 2, 1, :employee_name, :employee_username, :employee_email, :employee_password_hash, 1, 1, :employee_force_reason, NULL)
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
    $statement->execute([
        'admin_name' => 'System Administrator',
        'admin_username' => 'admin',
        'admin_email' => 'admin@travelagency.local',
        'admin_password_hash' => $superAdminPasswordHash,
        'admin_force_reason' => 'first_login',
        'employee_name' => 'Swat Employee',
        'employee_username' => 'employee',
        'employee_email' => 'employee@travelagency.local',
        'employee_password_hash' => $employeePasswordHash,
        'employee_force_reason' => 'first_login',
    ]);

    $db->exec("INSERT IGNORE INTO user_branch_access (user_id, branch_id) VALUES (1, 1), (1, 2), (2, 1)");

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

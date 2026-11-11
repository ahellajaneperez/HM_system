# Hospitality Management Equipment Borrowing System

## Requirements
- XAMPP with Apache and MySQL/MariaDB
- PHP 8.0+ with `pdo_mysql` enabled
- A modern browser

## Installation (XAMPP)
1. Extract the `HM_System` folder into `C:\xampp\htdocs\`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Open MySQL Workbench and create a connection named `HM_system` with hostname `127.0.0.1`, port `3307`, username `root`, and a blank password (the XAMPP defaults). Test the connection and open it.
4. Open `database/hm_system.sql` in Workbench and execute the script. It creates the `hm_system` database and its tables.
   - The SQL creates the `hm_system` database and the `users`, `equipment`, `borrowing_requests`, and `activity_logs` tables. The admin Borrowing Tools page manages the shared `equipment` table used by the student borrowing catalog. Student registrations store a unique student number, phone number, course, and section on the `users` table. Existing databases gain these columns, including the equipment photo path, automatically through `db.php`. Equipment photos are saved under `uploads/equipment/` and appear on the student Borrow Equipment catalog.
   - It also inserts the equipment catalog used by the Borrow Equipment page.
   - The script uses `CREATE TABLE IF NOT EXISTS`, so it does not drop existing tables or delete existing records. If you already have older tables with a different structure, back up first and compare their columns before importing.
5. `config/database.php` defaults to host `127.0.0.1`, port `3307`, database `hm_system`, user `root`, and a blank password to match XAMPP. Change these values if your MySQL setup uses different connection settings. Do not use port `3306` unless you intend to connect to a different MySQL server.
6. Visit `http://localhost/HM_System/`.
7. Create the first administrator by visiting `http://localhost/HM_System/create_admin.php`.
8. Sign in using:
   - Email: `admin_hm@gmail.com`
   - Temporary password: `admin123`
9. Delete `create_admin.php` after the admin account is created. Change the temporary password before using the system.
10. Students can register from the sign-in page.

## Important
- This is a local-development setup. Do not publish the default administrator password or leave `create_admin.php` accessible on a live server.
- User passwords are stored with PHP `password_hash()` and checked with `password_verify()`.
- If the system reports a database connection failure, confirm MySQL is running, the SQL import succeeded, and `db.php` matches your MySQL credentials.
- A successful `php -l` check verifies PHP syntax only; full runtime testing also requires a running MySQL/MariaDB server and browser testing.

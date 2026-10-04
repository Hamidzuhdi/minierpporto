-- Script untuk membuat user default
-- Jalankan setelah membuat database dan tabel

USE minierp;

-- Hapus user admin jika sudah ada (optional)
DELETE FROM users WHERE username = 'admin';

-- Buat user admin default
-- Password: admin123
INSERT INTO users (username, password_hash, full_name, role) 
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Administrator',
    'Admin'
);

-- Password hash untuk 'admin123' menggunakan PHP password_hash()
-- Anda bisa login dengan:
-- Username: admin
-- Password: admin123

SELECT * FROM users;

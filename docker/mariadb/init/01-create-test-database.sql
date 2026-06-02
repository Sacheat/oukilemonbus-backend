CREATE DATABASE IF NOT EXISTS bus_communal_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON bus_communal_test.* TO 'bus_user'@'%';

FLUSH PRIVILEGES;
